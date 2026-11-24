<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminAuth;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Platform;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Platform\Support;
use Throwable;

/**
 * لوحة المسؤول: تذاكر الدعم والإعلانات للزبائن.
 */
final class AdminSupportController extends Controller
{
    private function admin(): string
    {
        return (string) (AdminAuth::user()['name'] ?? '');
    }

    private function ticket(int $id): array
    {
        $t = DB::row('SELECT s.*, t.name AS tenant_name, t.slug, t.status AS tenant_status, t.phone FROM support_tickets s JOIN tenants t ON t.id = s.tenant_id WHERE s.id = ?', [$id]);
        $this->notFoundUnless($t);
        return $t;
    }

    public function tickets(Request $request): Response
    {
        $status = isset(Support::STATUS[$request->str('status')]) ? $request->str('status') : ($request->str('status') === 'all' ? 'all' : 'open');
        $where = $status === 'all' ? '1 = 1' : 's.status = ?';
        $params = $status === 'all' ? [] : [$status];
        $total = (int) DB::value("SELECT COUNT(*) FROM support_tickets s WHERE $where", $params);
        $pager = Paginator::fromRequest($request, $total, 30);
        return view('admin/tickets', [
            'rows' => DB::rows("SELECT s.*, t.name AS tenant_name, t.slug FROM support_tickets s JOIN tenants t ON t.id = s.tenant_id WHERE $where ORDER BY s.last_message_at DESC" . $pager->limitSql(), $params),
            'pager' => $pager, 'request' => $request, 'status' => $status, 'counts' => DB::pairs('SELECT status, COUNT(*) FROM support_tickets GROUP BY status'),
        ], 'admin');
    }

    public function ticketShow(Request $request, int $id): Response
    {
        $t = $this->ticket($id);
        if ((int) $t['admin_unread'] === 1) {
            DB::update('support_tickets', ['admin_unread' => 0], 'id = ?', [$id]);
        }
        return view('admin/ticket', ['t' => $t, 'messages' => Support::thread($id), 'aiState' => \App\Services\Ai::status(null)], 'admin');
    }

    public function ticketReply(Request $request, int $id): Response
    {
        $this->ticket($id);
        try {
            Support::reply($id, 'admin', $this->admin(), (string) $request->input('body', ''));
        } catch (Throwable $e) {
            return $this->backWithErrors($request, ['body' => $e->getMessage()], '/admin/tickets/' . $id);
        }
        if ($request->bool('close')) {
            Support::setClosed($id, true);
        }
        Session::pull('_old');
        return $this->ok('تم إرسال الرد.', '/admin/tickets/' . $id);
    }

    public function ticketState(Request $request, int $id): Response
    {
        $this->ticket($id);
        $close = $request->str('action') !== 'reopen';
        Support::setClosed($id, $close);
        return $this->ok($close ? 'تم إغلاق التذكرة.' : 'أُعيد فتح التذكرة.', '/admin/tickets/' . $id);
    }

    /** اقتراح رد بالذكاء الاصطناعي يراجعه المسؤول قبل الإرسال */
    public function ticketSuggest(Request $request, int $id): Response
    {
        $t = $this->ticket($id);
        Session::release();
        $r = Support::suggest($t, Support::thread($id), ['name' => $t['tenant_name'], 'status' => $t['tenant_status']], $this->admin());
        return json_response($r, $r['ok'] ? 200 : 422);
    }

    // ------------------------------------------------------------------
    // الإعلانات
    // ------------------------------------------------------------------

    public function announcements(Request $request): Response
    {
        $rows = DB::rows('SELECT a.*, (SELECT COUNT(*) FROM announcement_tenants x WHERE x.announcement_id = a.id) AS targets FROM platform_announcements a ORDER BY a.id DESC LIMIT 100');
        $targets = [];
        foreach (DB::rows('SELECT announcement_id, tenant_id FROM announcement_tenants') as $x) {
            $targets[(int) $x['announcement_id']][] = (int) $x['tenant_id'];
        }
        return view('admin/announcements', ['rows' => $rows, 'targets' => $targets, 'tenants' => DB::rows('SELECT id, name, slug FROM tenants ORDER BY name')], 'admin');
    }

    public function announcementSave(Request $request): Response
    {
        $id = $request->int('id');
        $d = $this->validate($request, [
            'title' => 'required|max:120', 'body' => 'nullable|max:600', 'level' => 'required|in:info,success,warning', 'audience' => 'required|in:all,selected',
            'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date',
        ], ['title' => 'العنوان', 'body' => 'النص', 'level' => 'النوع', 'audience' => 'المستهدفون', 'starts_at' => 'يبدأ', 'ends_at' => 'ينتهي']);
        $ids = array_values(array_unique(array_filter(array_map('intval', $request->arr('tenants')))));
        if ($d['audience'] === 'selected' && !$ids) {
            return $this->backWithErrors($request, ['tenants' => 'اختر حساباً واحداً على الأقل.'], '/admin/announcements');
        }
        $data = [
            'title' => $d['title'], 'body' => $d['body'], 'level' => $d['level'], 'audience' => $d['audience'],
            'starts_at' => $d['starts_at'] ? date('Y-m-d 00:00:00', (int) strtotime((string) $d['starts_at'])) : null,
            'ends_at' => $d['ends_at'] ? date('Y-m-d 23:59:59', (int) strtotime((string) $d['ends_at'])) : null,
            'is_active' => $request->bool('is_active') ? 1 : 0,
        ];
        if ($id > 0) {
            $this->notFoundUnless(DB::value('SELECT id FROM platform_announcements WHERE id = ?', [$id]));
            DB::update('platform_announcements', $data, 'id = ?', [$id]);
        } else {
            $id = DB::insert('platform_announcements', $data + ['admin_name' => mb_substr($this->admin(), 0, 120), 'created_at' => now()]);
        }
        DB::delete('announcement_tenants', 'announcement_id = ?', [$id]);
        if ($d['audience'] === 'selected') {
            $valid = array_map('intval', DB::column('SELECT id FROM tenants WHERE id IN (' . DB::in($ids) . ')', $ids));
            foreach ($valid as $tid) {
                DB::insert('announcement_tenants', ['announcement_id' => $id, 'tenant_id' => $tid]);
            }
        }
        Platform::log(null, 'admin', 'announcement.save', 'إعلان للزبائن: ' . $d['title'], $this->admin());
        Session::pull('_old');
        return $this->ok('تم حفظ الإعلان.', '/admin/announcements');
    }

    public function announcementDelete(Request $request, int $id): Response
    {
        DB::delete('platform_announcements', 'id = ?', [$id]);
        return $this->ok('تم حذف الإعلان.', '/admin/announcements');
    }

    public function announcementToggle(Request $request, int $id): Response
    {
        DB::query('UPDATE platform_announcements SET is_active = 1 - is_active WHERE id = ?', [$id]);
        return $this->ok('تم التحديث.', '/admin/announcements');
    }
}
