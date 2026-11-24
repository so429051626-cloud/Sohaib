<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\CardRules;
use App\Services\CardService;
use App\Services\LoginRegistry;
use App\Services\RouterTasks;
use App\Services\Units;
use RuntimeException;

/**
 * فحص الكرت: كل معلومات الكرت ومن يستخدمه والإجراءات عليه.
 */
final class CheckController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim(latin_digits($request->str('q')));
        $matches = [];
        if ($q !== '') {
            $owner = LoginRegistry::owner($q);
            if ($owner) {
                [$type, $id] = $owner;
                if ($type === 'subscriber') {
                    return redirect('/subscribers/' . $id);
                }
                return redirect('/check/card/' . $id);
            }
            $params = [$q, DB::like($q)];
            $sql = 'SELECT c.id, c.username, c.short_code, c.status, c.is_disabled, cat.name AS category_name FROM cards c JOIN categories cat ON cat.id = c.category_id
                    WHERE c.ext_serial = ? OR c.username LIKE ?';
            if (preg_match('/^#?(\d{1,9})$/', $q, $m)) {
                $sql .= ' OR c.id = ?';
                $params[] = (int) $m[1];
            }
            if (($mac = normalize_mac($q)) !== null) {
                $sql .= ' OR c.bound_mac = ? OR c.last_mac = ?';
                array_push($params, $mac, $mac);
            }
            $matches = DB::rows($sql . ' ORDER BY c.last_login_at DESC, c.id DESC LIMIT 50', $params);
            if (count($matches) === 1) {
                return redirect('/check/card/' . $matches[0]['id']);
            }
        }
        $recent = DB::rows(
            "SELECT l.login, l.result, l.reason, l.created_at, l.card_id, r.name AS router_name FROM login_log l LEFT JOIN routers r ON r.id = l.router_id
             WHERE l.source = 'portal' ORDER BY l.id DESC LIMIT 12"
        );
        return view('check/index', compact('q', 'matches', 'recent'));
    }

    public function card(Request $request, int $id): Response
    {
        $card = DB::row(
            'SELECT c.*, cat.name AS category_name, cat.color AS category_color, cat.price AS category_price, b.name AS batch_name,
                    d.name AS distributor_name, r.name AS last_router_name, dev.hostname AS last_hostname, bdev.hostname AS bound_hostname
             FROM cards c JOIN categories cat ON cat.id = c.category_id JOIN batches b ON b.id = c.batch_id
             LEFT JOIN distributors d ON d.id = c.distributor_id LEFT JOIN routers r ON r.id = c.last_router_id
             LEFT JOIN devices dev ON dev.mac = c.last_mac LEFT JOIN devices bdev ON bdev.mac = c.bound_mac
             WHERE c.id = ?',
            [$id]
        );
        $this->notFoundUnless($card);
        $category = DB::row('SELECT * FROM categories WHERE id = ?', [$card['category_id']]);
        [$timeLimit, $dataLimit, $validity] = CardRules::limits($card, $category);
        $remaining = CardRules::remaining($card, $category);
        $sessions = DB::rows(
            'SELECT s.*, r.name AS router_name, d.hostname FROM sessions s LEFT JOIN routers r ON r.id = s.router_id LEFT JOIN devices d ON d.mac = s.mac
             WHERE s.card_id = ? ORDER BY s.started_at DESC LIMIT 50',
            [$id]
        );
        $log = DB::rows('SELECT l.*, r.name AS router_name FROM login_log l LEFT JOIN routers r ON r.id = l.router_id WHERE l.card_id = ? ORDER BY l.id DESC LIMIT 30', [$id]);
        $presence = DB::rows('SELECT cr.*, r.name AS router_name FROM card_routers cr JOIN routers r ON r.id = cr.router_id WHERE cr.card_id = ? ORDER BY cr.synced_at DESC', [$id]);
        $pending = (int) DB::value("SELECT COUNT(*) FROM router_tasks WHERE target = ? AND status = 'pending'", ['card:' . $id]);
        $routers = DB::pairs('SELECT id, name FROM routers WHERE is_active = 1 ORDER BY name');
        $sale = $card['sale_id'] ? DB::row('SELECT s.*, e.name AS employee_name FROM sales s LEFT JOIN employees e ON e.id = s.employee_id WHERE s.id = ?', [$card['sale_id']]) : null;
        return view('check/card', compact('card', 'category', 'timeLimit', 'dataLimit', 'validity', 'remaining', 'sessions', 'log', 'presence', 'pending', 'routers', 'sale'));
    }

    public function cardAction(Request $request, int $id): Response
    {
        $card = DB::row('SELECT * FROM cards WHERE id = ?', [$id]);
        $this->notFoundUnless($card);
        $op = $request->str('op');
        $perm = match ($op) {
            'password' => 'check.password',
            'notes' => 'check.use',
            'push' => 'cards.manage',
            'delete' => 'cards.delete',
            default => 'check.actions',
        };
        if (!can($perm)) {
            throw new HttpException(403);
        }
        try {
            $msg = '';
            $job = null;
            switch ($op) {
                case 'password':
                    $new = $request->str('password');
                    if ($new === '') {
                        $new = $card['username'] === $card['password'] ? $card['username'] : (string) random_int(100000, 999999);
                    }
                    CardService::changePassword($id, $new);
                    $msg = 'تم تغيير كلمة المرور.';
                    break;
                case 'unbind':
                    $job = CardService::unbind([$id])['job'];
                    $msg = 'تم فك ربط الكرت بالجهاز.';
                    break;
                case 'reset':
                    CardService::reset($id);
                    $msg = 'تمت إعادة الكرت إلى حالته الأولى.';
                    break;
                case 'extend':
                    $job = CardService::extend(
                        [$id],
                        (int) Units::toSeconds($request->str('add_time'), $request->str('add_time_unit', 'hour')),
                        (int) Units::toBytes($request->str('add_data'), $request->str('add_data_unit', 'GB')),
                        (int) Units::toSeconds($request->str('add_validity'), $request->str('add_validity_unit', 'day'))
                    )['job'];
                    $msg = 'تم تمديد الكرت.';
                    break;
                case 'disable':
                    $job = CardService::disable([$id], $request->str('reason') ?: null)['job'];
                    $msg = 'تم إيقاف الكرت.';
                    break;
                case 'enable':
                    $job = CardService::enable([$id])['job'];
                    $msg = 'تم تفعيل الكرت.';
                    break;
                case 'disconnect':
                    $n = CardService::disconnect($card);
                    $msg = $n > 0 ? 'تم فصل جلسات الكرت.' : 'لا توجد جلسات نشطة للكرت.';
                    break;
                case 'push':
                    $routers = $request->ids('routers');
                    if (!$routers) {
                        throw new RuntimeException('اختر جهاز MikroTik.');
                    }
                    $failed = [];
                    foreach ($routers as $rid) {
                        $r = RouterTasks::dispatch($rid, 'card_provision', ['card_id' => $id], 'card:' . $id);
                        if (!$r['ok']) {
                            $failed[] = $r['error'];
                        }
                    }
                    if ($failed) {
                        throw new RuntimeException(implode(' ', $failed));
                    }
                    $msg = 'تم تطبيق الكرت على أجهزة MikroTik المحددة.';
                    break;
                case 'notes':
                    CardService::updateNotes($id, $request->str('notes') ?: null);
                    $msg = 'تم حفظ الملاحظات.';
                    break;
                case 'delete':
                    $job = CardService::delete([$id])['job'];
                    flash('success', 'تم حذف الكرت.');
                    return $job ? redirect('/jobs/' . $job) : redirect('/cards');
                default:
                    throw new HttpException(400);
            }
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            return redirect('/check/card/' . $id);
        }
        flash('success', $msg);
        return $job ? redirect('/jobs/' . $job) : redirect('/check/card/' . $id);
    }
}
