<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\MikroTik\Gateway;
use App\MikroTik\MikroTikException;
use Throwable;

/**
 * مهام التنفيذ على جهاز MikroTik: تُنفَّذ فوراً، وعند تعذّر الاتصال تُحفظ وتُعاد تلقائياً.
 */
final class RouterTasks
{
    public const LABELS = [
        'card_provision' => 'تطبيق كرت',
        'card_remove'    => 'حذف كرت',
        'card_disable'   => 'إيقاف/تفعيل كرت',
        'card_set'       => 'تعديل كرت',
        'hs_disconnect'  => 'فصل مستخدم هوتسبوت',
        'sub_provision'  => 'تطبيق مشترك',
        'sub_remove'     => 'حذف مشترك',
        'ppp_disconnect' => 'فصل مشترك PPP',
        'profile_sync'   => 'مزامنة ملف سرعة',
        'profile_remove' => 'حذف ملف سرعة',
        'binding'        => 'ربط جهاز',
    ];

    /** عمليات لا تُعاد عند رفض جهاز MikroTik لها (حذف عنصر غير موجود مثلاً) */
    private const IDEMPOTENT = ['card_remove', 'hs_disconnect', 'ppp_disconnect', 'sub_remove', 'profile_remove'];

    private static array $routers = [];

    private static function router(int $id): ?array
    {
        if (!array_key_exists($id, self::$routers)) {
            self::$routers[$id] = DB::row('SELECT * FROM routers WHERE id = ?', [$id]);
        }
        return self::$routers[$id];
    }

    public static function flushCache(): void
    {
        self::$routers = [];
    }

    /**
     * تنفيذ مهمة الآن أو جدولتها.
     * @return array{ok:bool, queued:bool, error:?string}
     */
    public static function dispatch(int $routerId, string $action, array $payload, ?string $target = null, bool $immediate = true, ?Gateway $gw = null): array
    {
        $router = self::router($routerId);
        if (!$router) {
            return ['ok' => false, 'queued' => false, 'error' => 'جهاز MikroTik غير موجود.'];
        }
        if (!$immediate || !(int) $router['is_active']) {
            self::queue($routerId, $action, $payload, $target);
            return ['ok' => true, 'queued' => true, 'error' => null];
        }
        // مهام سابقة معلّقة لنفس الهدف تُنفَّذ قبل الجديدة للحفاظ على الترتيب
        if ($target !== null && DB::value("SELECT 1 FROM router_tasks WHERE router_id = ? AND target = ? AND status = 'pending' LIMIT 1", [$routerId, $target])) {
            self::queue($routerId, $action, $payload, $target);
            return ['ok' => true, 'queued' => true, 'error' => null];
        }
        try {
            $gw ??= Gateway::for($router);
            self::execute($gw, $router, $action, $payload);
            return ['ok' => true, 'queued' => false, 'error' => null];
        } catch (MikroTikException $e) {
            if ($e->isConnection()) {
                self::queue($routerId, $action, $payload, $target, $e->friendly());
                return ['ok' => true, 'queued' => true, 'error' => $e->friendly()];
            }
            if (in_array($action, self::IDEMPOTENT, true)) {
                return ['ok' => true, 'queued' => false, 'error' => null];
            }
            self::record($routerId, $action, $payload, $target, 'failed', $e->friendly());
            return ['ok' => false, 'queued' => false, 'error' => $e->friendly()];
        } catch (Throwable $e) {
            self::record($routerId, $action, $payload, $target, 'failed', $e->getMessage());
            return ['ok' => false, 'queued' => false, 'error' => $e->getMessage()];
        }
    }

    /** جدولة مهمة (مع دمج المهام المعلّقة المتطابقة) */
    public static function queue(int $routerId, string $action, array $payload, ?string $target = null, ?string $error = null): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($target !== null) {
            $existing = DB::value(
                "SELECT id FROM router_tasks WHERE router_id = ? AND action = ? AND target = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
                [$routerId, $action, $target]
            );
            if ($existing) {
                DB::update('router_tasks', ['payload' => $json, 'last_error' => $error], 'id = ?', [$existing]);
                return;
            }
        }
        DB::insert('router_tasks', [
            'router_id'  => $routerId,
            'action'     => $action,
            'target'     => $target,
            'payload'    => $json,
            'status'     => 'pending',
            'attempts'   => $error !== null ? 1 : 0,
            'last_error' => $error,
            'run_after'  => now(),
            'created_at' => now(),
        ]);
    }

    private static function record(int $routerId, string $action, array $payload, ?string $target, string $status, ?string $error): void
    {
        DB::insert('router_tasks', [
            'router_id'  => $routerId,
            'action'     => $action,
            'target'     => $target,
            'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'     => $status,
            'attempts'   => 1,
            'last_error' => $error !== null ? mb_substr($error, 0, 255) : null,
            'run_after'  => now(),
            'created_at' => now(),
            'done_at'    => now(),
        ]);
    }

    public static function execute(Gateway $gw, array $router, string $action, array $p): void
    {
        switch ($action) {
            case 'card_provision':
                if (DB::value('SELECT id FROM cards WHERE id = ?', [(int) $p['card_id']])) {
                    Provisioner::provisionCard($gw, $router, (int) $p['card_id']);
                }
                break;
            case 'card_remove':
                $card = isset($p['card_id']) ? DB::row('SELECT * FROM cards WHERE id = ?', [(int) $p['card_id']]) : null;
                if ($card) {
                    Provisioner::removeCard($gw, $router, $card);
                } else {
                    $gw->disconnectHotspotUser((string) $p['username']);
                    $gw->removeHotspotUser((string) $p['username']);
                    $gw->removeHotspotCookies((string) $p['username']);
                }
                break;
            case 'card_disable':
                $gw->setHotspotUser((string) $p['username'], ['disabled' => !empty($p['disabled']) ? 'yes' : 'no']);
                if (!empty($p['disabled'])) {
                    $gw->disconnectHotspotUser((string) $p['username']);
                    $gw->removeHotspotCookies((string) $p['username']);
                }
                break;
            case 'card_set':
                $gw->setHotspotUser((string) $p['username'], (array) $p['props']);
                break;
            case 'hs_disconnect':
                if (!empty($p['mac']) && empty($p['user'])) {
                    $gw->disconnectHotspotMac((string) $p['mac']);
                } else {
                    $gw->disconnectHotspotUser((string) $p['user'], $p['mac'] ?? null);
                }
                break;
            case 'sub_provision':
                if (DB::value('SELECT id FROM subscribers WHERE id = ?', [(int) $p['subscriber_id']])) {
                    Provisioner::provisionSubscriber($gw, $router, (int) $p['subscriber_id']);
                }
                break;
            case 'sub_remove':
                Provisioner::removeSubscriber($gw, $router, ['id' => (int) ($p['subscriber_id'] ?? 0), 'username' => (string) $p['username']], (string) $p['service']);
                break;
            case 'ppp_disconnect':
                $gw->disconnectPpp((string) $p['name']);
                break;
            case 'profile_sync':
                if (($p['kind'] ?? '') === 'category') {
                    $cat = DB::row('SELECT * FROM categories WHERE id = ?', [(int) $p['id']]);
                    if ($cat) {
                        ProfileManager::ensureCategory($gw, $router, $cat, true);
                    }
                } else {
                    $plan = DB::row('SELECT * FROM plans WHERE id = ?', [(int) $p['id']]);
                    if ($plan) {
                        if ($plan['service'] === 'ppp') {
                            ProfileManager::ensurePppPlan($gw, $router, $plan, false, true);
                            if (ProfileManager::fupRate($plan) !== null) {
                                ProfileManager::ensurePppPlan($gw, $router, $plan, true, true);
                            }
                        } else {
                            ProfileManager::ensureHotspotPlan($gw, $router, $plan, true);
                        }
                    }
                }
                break;
            case 'profile_remove':
                // ملف سرعة لم يعد مستخدماً (حساب خاص محذوف، أو تحديد سرعة مرفوع): يُحذف فقط إن لم يبق مستخدم هوتسبوت عليه
                $name = (string) ($p['name'] ?? '');
                if (preg_match('/^sn-(c\d+|[cp]\d+-cap-[0-9A-Za-z]+-[0-9A-Za-z]+)$/', $name) && !$gw->client()->print('/ip/hotspot/user', ['profile' => $name], ['.id'])) {
                    $gw->removeHotspotProfile($name);
                }
                break;
            case 'binding':
                if (($p['type'] ?? '') === 'remove') {
                    $gw->removeBinding((string) $p['mac']);
                } else {
                    $gw->setBinding((string) $p['mac'], (string) $p['type'], (string) ($p['comment'] ?? ''));
                    if ($p['type'] === 'blocked') {
                        $gw->disconnectHotspotMac((string) $p['mac']);
                    }
                }
                break;
            default:
                throw new \RuntimeException('عملية غير معروفة: ' . $action);
        }
    }

    /**
     * تنفيذ المهام المعلّقة لجهاز MikroTik متصل.
     * @return array{done:int, failed:int, left:int}
     */
    public static function processRouter(Gateway $gw, array $router, float $budget = 15.0): array
    {
        $deadline = microtime(true) + $budget;
        $done = $failed = 0;
        $tasks = DB::rows(
            "SELECT * FROM router_tasks WHERE router_id = ? AND status = 'pending' AND run_after <= ? ORDER BY id LIMIT 500",
            [$router['id'], now()]
        );
        foreach ($tasks as $t) {
            if (microtime(true) > $deadline) {
                break;
            }
            $payload = json_decode((string) $t['payload'], true) ?: [];
            try {
                self::execute($gw, $router, $t['action'], $payload);
                DB::update('router_tasks', ['status' => 'done', 'done_at' => now(), 'attempts' => (int) $t['attempts'] + 1, 'last_error' => null], 'id = ?', [$t['id']]);
                $done++;
            } catch (MikroTikException $e) {
                if ($e->isConnection()) {
                    $attempts = (int) $t['attempts'] + 1;
                    DB::update('router_tasks', [
                        'attempts' => min(255, $attempts), 'last_error' => mb_substr($e->friendly(), 0, 255),
                        'run_after' => date('Y-m-d H:i:s', time() + min(1800, 60 * (2 ** min(5, $attempts)))),
                    ], 'id = ?', [$t['id']]);
                    break;
                }
                $status = in_array($t['action'], self::IDEMPOTENT, true) ? 'done' : 'failed';
                DB::update('router_tasks', ['status' => $status, 'done_at' => now(), 'attempts' => (int) $t['attempts'] + 1, 'last_error' => mb_substr($e->friendly(), 0, 255)], 'id = ?', [$t['id']]);
                if ($status === 'failed') {
                    $failed++;
                }
            } catch (Throwable $e) {
                DB::update('router_tasks', ['status' => 'failed', 'done_at' => now(), 'attempts' => (int) $t['attempts'] + 1, 'last_error' => mb_substr($e->getMessage(), 0, 255)], 'id = ?', [$t['id']]);
                $failed++;
            }
        }
        if ($failed > 0) {
            Notifier::alert('task_failed', 'warning', 'تعذّر تنفيذ ' . $failed . ' مهمة على جهاز MikroTik ' . $router['name'], null, (int) $router['id'], 'task_failed:' . $router['id'], 60);
        }
        $left = (int) DB::value("SELECT COUNT(*) FROM router_tasks WHERE router_id = ? AND status = 'pending'", [$router['id']]);
        return ['done' => $done, 'failed' => $failed, 'left' => $left];
    }

    public static function pending(?int $routerId = null): int
    {
        if ($routerId === null) {
            return (int) DB::value("SELECT COUNT(*) FROM router_tasks WHERE status = 'pending'");
        }
        return (int) DB::value("SELECT COUNT(*) FROM router_tasks WHERE router_id = ? AND status = 'pending'", [$routerId]);
    }

    /** إرسال مهمة إلى كل أجهزة MikroTik التي تحمل الكرت */
    public static function forCardRouters(array $card, string $action, array $payload, bool $immediate = true): array
    {
        $routers = DB::column("SELECT router_id FROM card_routers WHERE card_id = ? AND state = 'present'", [$card['id']]);
        $results = [];
        foreach ($routers as $rid) {
            $results[(int) $rid] = self::dispatch((int) $rid, $action, $payload, 'card:' . $card['id'], $immediate);
        }
        return $results;
    }
}
