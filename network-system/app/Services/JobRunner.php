<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\DB;
use App\MikroTik\Gateway;
use App\MikroTik\MikroTikException;
use Throwable;

/**
 * العمليات الطويلة على دفعات قصيرة (مناسبة للاستضافة المشتركة).
 * تتقدّم من صفحة العملية في المتصفح ومن المهمة المجدولة معاً دون تعارض.
 */
final class JobRunner
{
    public const TYPES = [
        'push_cards'         => 'رفع كروت إلى جهاز MikroTik',
        'remove_cards'       => 'حذف كروت من أجهزة MikroTik',
        'sync_cards'         => 'تحديث كروت على أجهزة MikroTik',
        'import_router_users'=> 'استيراد مستخدمي هوتسبوت من جهاز MikroTik',
        'import_ppp_secrets' => 'استيراد مشتركي PPP من جهاز MikroTik',
        'provision_subs'     => 'تطبيق مشتركين على أجهزة MikroTik',
        'deploy_sdk'         => 'نشر ملفات ربط الهوتسبوت',
        'profile_sync'       => 'مزامنة ملفات السرعة',
        'cookie_sync'        => 'تطبيق الكوكيز على أجهزة MikroTik',
    ];

    /** عمليات تلقائية تجري بصمت: لا تُسجَّل في سجل العمليات ولا تُرسل تنبيهاً عند انتهائها */
    private const SILENT = ['cookie_sync'];

    public const STATUS = [
        'pending'   => ['بالانتظار', 'badge-info'],
        'running'   => ['قيد التنفيذ', 'badge-primary'],
        'done'      => ['مكتملة', 'badge-success'],
        'failed'    => ['فشلت', 'badge-danger'],
        'cancelled' => ['ملغاة', 'badge-outline'],
    ];

    public static function create(string $type, string $title, array $payload, int $total, ?int $employeeId = null, bool $audit = true): int
    {
        $id = DB::insert('jobs', [
            'type'       => $type,
            'title'      => mb_substr($title, 0, 160),
            'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'state'      => json_encode(['i' => 0, 'log' => []], JSON_UNESCAPED_UNICODE),
            'status'     => 'pending',
            'progress'   => 0,
            'total'      => max(0, $total),
            'created_by' => $employeeId,
            'created_at' => now(),
        ]);
        if ($audit && !in_array($type, self::SILENT, true)) {
            Audit::log('job.create', $title, 'job', $id);
        }
        return $id;
    }

    private static function acquire(int $id, int $seconds): bool
    {
        return DB::query(
            "UPDATE jobs SET locked_until = ? WHERE id = ? AND status IN ('pending','running') AND (locked_until IS NULL OR locked_until < ?)",
            [date('Y-m-d H:i:s', time() + $seconds), $id, now()]
        )->rowCount() === 1;
    }

    private static function release(int $id): void
    {
        DB::update('jobs', ['locked_until' => null], 'id = ?', [$id]);
    }

    /** تنفيذ جزء من العملية ضمن مهلة زمنية */
    public static function step(int $id, float $budget = 8.0): ?array
    {
        if (!self::acquire($id, (int) ceil($budget) + 60)) {
            return DB::row('SELECT * FROM jobs WHERE id = ?', [$id]);
        }
        $job = DB::row('SELECT * FROM jobs WHERE id = ?', [$id]);
        $payload = json_decode((string) $job['payload'], true) ?: [];
        $state = json_decode((string) $job['state'], true) ?: ['i' => 0, 'log' => []];
        $deadline = microtime(true) + $budget;
        $errors = (int) $job['errors'];
        $status = 'running';
        $message = $job['message'];
        try {
            $handler = 'run' . str_replace('_', '', ucwords($job['type'], '_'));
            if (!method_exists(self::class, $handler)) {
                throw new \RuntimeException('نوع عملية غير معروف.');
            }
            $done = self::$handler($payload, $state, $deadline, $errors);
            if ($done) {
                $status = 'done';
                $message = $errors > 0 ? 'اكتملت مع ' . $errors . ' خطأ.' : 'اكتملت بنجاح.';
            }
        } catch (Throwable $e) {
            $status = 'failed';
            $message = mb_substr($e->getMessage(), 0, 500);
            log_message('error', 'Job ' . $id . ' failed: ' . $e->getMessage());
        }
        $state['log'] = array_slice($state['log'] ?? [], -60);
        DB::update('jobs', [
            'state'       => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'      => $status,
            'progress'    => min((int) ($state['i'] ?? 0), max((int) $job['total'], (int) ($state['i'] ?? 0))),
            'total'       => max((int) $job['total'], (int) ($state['total'] ?? 0)),
            'errors'      => $errors,
            'message'     => $message,
            'updated_at'  => now(),
            'finished_at' => in_array($status, ['done', 'failed'], true) ? now() : null,
            'locked_until'=> null,
        ], 'id = ?', [$id]);
        if (in_array($status, ['done', 'failed'], true) && !in_array($job['type'], self::SILENT, true)) {
            Notifier::alert('job', $status === 'done' ? ($errors ? 'warning' : 'success') : 'danger', $job['title'] . ': ' . $message, null, null, 'job:' . $id, 0, false);
        }
        return DB::row('SELECT * FROM jobs WHERE id = ?', [$id]);
    }

    public static function cancel(int $id): void
    {
        DB::query("UPDATE jobs SET status = 'cancelled', finished_at = ?, updated_at = ? WHERE id = ? AND status IN ('pending','running')", [now(), now(), $id]);
    }

    /** تقدّم كل العمليات الجارية (من المهمة المجدولة) */
    public static function runPending(float $budget): void
    {
        $deadline = microtime(true) + $budget;
        foreach (DB::column("SELECT id FROM jobs WHERE status IN ('pending','running') ORDER BY id LIMIT 10") as $id) {
            $left = $deadline - microtime(true);
            if ($left < 2) {
                break;
            }
            self::step((int) $id, min(20.0, $left));
        }
    }

    private static function log(array &$state, string $line): void
    {
        $state['log'][] = date('H:i:s') . ' ' . $line;
    }

    private static function router(int $id): array
    {
        $r = DB::row('SELECT * FROM routers WHERE id = ?', [$id]);
        if (!$r) {
            throw new \RuntimeException('جهاز MikroTik غير موجود.');
        }
        return $r;
    }

    // ---------------------------------------------------------------------
    // الأنواع
    // ---------------------------------------------------------------------

    /** رفع كروت إلى أجهزة MikroTik: payload = {card_ids:[], router_ids:[]} */
    private static function runPushCards(array $p, array &$state, float $deadline, int &$errors): bool
    {
        $cards = array_values($p['card_ids']);
        $routers = array_values($p['router_ids']);
        $total = count($cards) * count($routers);
        $state['total'] = $total;
        $gw = null;
        $currentRouter = null;
        while (($i = (int) $state['i']) < $total) {
            if (microtime(true) > $deadline) {
                break;
            }
            $routerId = (int) $routers[intdiv($i, max(1, count($cards)))];
            $cardId = (int) $cards[$i % count($cards)];
            if (in_array($routerId, $state['skip'] ?? [], true)) {
                $state['i'] = $i + 1;
                $errors++;
                continue;
            }
            try {
                if ($currentRouter !== $routerId) {
                    $gw?->close();
                    $router = self::router($routerId);
                    $gw = Gateway::for($router);
                    $currentRouter = $routerId;
                }
                Provisioner::provisionCard($gw, $router, $cardId);
            } catch (MikroTikException $e) {
                if ($e->isConnection()) {
                    $state['skip'][] = $routerId;
                    self::log($state, 'تعذّر الاتصال بجهاز MikroTik #' . $routerId . ': ' . $e->friendly());
                    $gw = null;
                    $currentRouter = null;
                } else {
                    self::log($state, 'كرت #' . $cardId . ': ' . $e->friendly());
                }
                $errors++;
            }
            $state['i'] = $i + 1;
        }
        $gw?->close();
        return (int) $state['i'] >= $total;
    }

    /** إعادة تطبيق كروت على أجهزة MikroTik التي تحملها: payload = {card_ids:[]} */
    private static function runSyncCards(array $p, array &$state, float $deadline, int &$errors): bool
    {
        if (!isset($state['pairs'])) {
            $pairs = [];
            foreach (array_chunk(array_map('intval', $p['card_ids']), 500) as $chunk) {
                foreach (DB::rows("SELECT card_id, router_id FROM card_routers WHERE state = 'present' AND card_id IN (" . DB::in($chunk) . ') ORDER BY router_id', $chunk) as $r) {
                    $pairs[] = [(int) $r['router_id'], (int) $r['card_id']];
                }
            }
            $state['pairs'] = $pairs;
            $state['total'] = count($pairs);
        }
        return self::runPairs($state, $deadline, $errors, static function (Gateway $gw, array $router, int $cardId) {
            Provisioner::provisionCard($gw, $router, $cardId);
        });
    }

    /** حذف كروت من أجهزة MikroTik: payload = {items: [[router_id, card_id, username], ...]} */
    private static function runRemoveCards(array $p, array &$state, float $deadline, int &$errors): bool
    {
        if (!isset($state['pairs'])) {
            $state['pairs'] = array_map(static fn ($x) => [(int) $x[0], $x[2], (int) $x[1]], $p['items']);
            $state['total'] = count($state['pairs']);
        }
        return self::runPairs($state, $deadline, $errors, static function (Gateway $gw, array $router, string $username, int $cardId) {
            $card = $cardId ? DB::row('SELECT * FROM cards WHERE id = ?', [$cardId]) : null;
            if ($card) {
                Provisioner::removeCard($gw, $router, $card);
            } else {
                $gw->disconnectHotspotUser($username);
                $gw->removeHotspotUser($username);
                $gw->removeHotspotCookies($username);
            }
        });
    }

    /** تطبيق مشتركين: payload = {subscriber_ids:[]} */
    private static function runProvisionSubs(array $p, array &$state, float $deadline, int &$errors): bool
    {
        if (!isset($state['pairs'])) {
            $ids = array_map('intval', $p['subscriber_ids']);
            $pairs = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (DB::rows('SELECT id, router_id FROM subscribers WHERE router_id IS NOT NULL AND id IN (' . DB::in($chunk) . ') ORDER BY router_id', $chunk) as $r) {
                    $pairs[] = [(int) $r['router_id'], (int) $r['id']];
                }
            }
            $state['pairs'] = $pairs;
            $state['total'] = count($pairs);
        }
        return self::runPairs($state, $deadline, $errors, static function (Gateway $gw, array $router, int $subId) {
            Provisioner::provisionSubscriber($gw, $router, $subId);
        });
    }

    /** مزامنة ملفات السرعة على كل أجهزة MikroTik: payload = {kind, id} */
    private static function runProfileSync(array $p, array &$state, float $deadline, int &$errors): bool
    {
        if (!isset($state['pairs'])) {
            $state['pairs'] = array_map(static fn ($r) => [(int) $r, 0], DB::column('SELECT id FROM routers WHERE is_active = 1'));
            $state['total'] = count($state['pairs']);
        }
        return self::runPairs($state, $deadline, $errors, static function (Gateway $gw, array $router) use ($p) {
            RouterTasks::execute($gw, $router, 'profile_sync', ['kind' => $p['kind'], 'id' => (int) $p['id']]);
        });
    }

    /**
     * تطبيق الكوكيز على كل أجهزة MikroTik: payload = {enabled, clear}
     * يحدّث ملفات السرعة الموجودة على كل جهاز (باقات الكروت وباقات الهوتسبوت)، ويحذف الكوكيز المحفوظة عند الإيقاف.
     */
    private static function runCookieSync(array $p, array &$state, float $deadline, int &$errors): bool
    {
        if (!isset($state['pairs'])) {
            $state['pairs'] = array_map(static fn ($r) => [(int) $r], DB::column('SELECT id FROM routers WHERE is_active = 1'));
            $state['total'] = count($state['pairs']);
        }
        return self::runPairs($state, $deadline, $errors, static function (Gateway $gw, array $router) use ($p, &$state) {
            $rid = (int) $router['id'];
            $refs = DB::rows("SELECT kind, ref_id FROM router_profiles WHERE router_id = ? AND kind IN ('category', 'plan')", [$rid]);
            $n = 0;
            foreach ($refs as $ref) {
                if ($ref['kind'] === 'category') {
                    $cat = DB::row('SELECT * FROM categories WHERE id = ?', [(int) $ref['ref_id']]);
                    if ($cat) {
                        ProfileManager::ensureCategory($gw, $router, $cat, true);
                        $n++;
                    }
                } else {
                    $plan = DB::row("SELECT * FROM plans WHERE id = ? AND service <> 'ppp'", [(int) $ref['ref_id']]);
                    if ($plan) {
                        ProfileManager::ensureHotspotPlan($gw, $router, $plan, true);
                        $n++;
                    }
                }
            }
            $cleared = !empty($p['clear']) ? $gw->clearHotspotCookies() : 0;
            self::log($state, $router['name'] . ': تحديث ' . $n . ' ملف سرعة' . ($cleared ? '، وحذف ' . $cleared . ' كوكيز محفوظة' : ''));
        });
    }

    /**
     * منفّذ عام لأزواج [router_id, ...args] مرتبة حسب جهاز MikroTik.
     */
    private static function runPairs(array &$state, float $deadline, int &$errors, callable $fn): bool
    {
        $pairs = $state['pairs'];
        $total = count($pairs);
        $gw = null;
        $current = null;
        $router = null;
        while (($i = (int) $state['i']) < $total) {
            if (microtime(true) > $deadline) {
                break;
            }
            $args = $pairs[$i];
            $routerId = (int) array_shift($args);
            if (in_array($routerId, $state['skip'] ?? [], true)) {
                $state['i'] = $i + 1;
                $errors++;
                continue;
            }
            try {
                if ($current !== $routerId) {
                    $gw?->close();
                    $router = self::router($routerId);
                    $gw = Gateway::for($router);
                    $current = $routerId;
                }
                $fn($gw, $router, ...$args);
            } catch (MikroTikException $e) {
                if ($e->isConnection()) {
                    $state['skip'][] = $routerId;
                    self::log($state, 'تعذّر الاتصال بجهاز MikroTik #' . $routerId . ': ' . $e->friendly());
                    $gw = null;
                    $current = null;
                } else {
                    self::log($state, $e->friendly());
                }
                $errors++;
            } catch (Throwable $e) {
                self::log($state, $e->getMessage());
                $errors++;
            }
            $state['i'] = $i + 1;
        }
        $gw?->close();
        return (int) $state['i'] >= $total;
    }

    /** استيراد مستخدمي الهوتسبوت: payload = {router_id, map:{profile:category_id}, batch_name, skip_existing, retag} */
    private static function runImportRouterUsers(array $p, array &$state, float $deadline, int &$errors): bool
    {
        return RouterImport::stepHotspot($p, $state, $deadline, $errors);
    }

    /** استيراد مشتركي PPP: payload = {router_id, map:{profile:plan_id}, retag} */
    private static function runImportPppSecrets(array $p, array &$state, float $deadline, int &$errors): bool
    {
        return RouterImport::stepPpp($p, $state, $deadline, $errors);
    }

    /** نشر ملفات الربط: payload = {router_ids:[]} */
    private static function runDeploySdk(array $p, array &$state, float $deadline, int &$errors): bool
    {
        $routers = array_values($p['router_ids']);
        $state['total'] = count($routers);
        while (($i = (int) $state['i']) < count($routers)) {
            if (microtime(true) > $deadline) {
                break;
            }
            $router = DB::row('SELECT * FROM routers WHERE id = ?', [(int) $routers[$i]]);
            if ($router) {
                $result = HotspotSdk::deploy($router);
                self::log($state, $router['name'] . ': ' . $result['message']);
                if (!$result['ok']) {
                    $errors++;
                }
            }
            $state['i'] = $i + 1;
        }
        return (int) $state['i'] >= count($routers);
    }
}
