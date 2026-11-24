<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Platform;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Tenant;
use App\Platform\Support;
use Throwable;

/**
 * تذاكر الدعم من نظام الزبون إلى مسؤول المنصة.
 */
final class SupportController extends Controller
{
    private function tenantId(): int
    {
        $id = Tenant::id();
        if ($id === null) {
            throw new HttpException(404);
        }
        return $id;
    }

    private function ticket(int $id): array
    {
        $t = Platform::row('SELECT * FROM support_tickets WHERE id = ? AND tenant_id = ?', [$id, $this->tenantId()]);
        $this->notFoundUnless($t);
        return $t;
    }

    public function index(Request $request): Response
    {
        return view('support/index', ['rows' => Platform::rows('SELECT * FROM support_tickets WHERE tenant_id = ? ORDER BY last_message_at DESC LIMIT 100', [$this->tenantId()])]);
    }

    public function store(Request $request): Response
    {
        $d = $this->validate($request, ['subject' => 'required|max:160', 'category' => 'required|in:' . implode(',', array_keys(Support::CATEGORIES)), 'body' => 'required|max:5000'],
            ['subject' => 'العنوان', 'category' => 'التصنيف', 'body' => 'التفاصيل']);
        try {
            $id = Support::open((array) Tenant::current(), $d['subject'], $d['category'], $d['body'], (string) (Auth::user()['name'] ?? ''));
        } catch (Throwable $e) {
            return $this->backWithErrors($request, ['subject' => $e->getMessage()], '/support');
        }
        Session::pull('_old');
        return $this->ok('تم فتح التذكرة.', '/support/' . $id);
    }

    public function show(Request $request, int $id): Response
    {
        $t = $this->ticket($id);
        if ((int) $t['tenant_unread'] === 1) {
            Platform::update('support_tickets', ['tenant_unread' => 0], 'id = ?', [$id]);
        }
        return view('support/show', ['t' => $t, 'messages' => Support::thread($id)]);
    }

    public function reply(Request $request, int $id): Response
    {
        $this->ticket($id);
        try {
            Support::reply($id, 'tenant', (string) (Auth::user()['name'] ?? ''), (string) $request->input('body', ''));
        } catch (Throwable $e) {
            return $this->backWithErrors($request, ['body' => $e->getMessage()], '/support/' . $id);
        }
        Session::pull('_old');
        return $this->ok('تم إرسال الرد.', '/support/' . $id);
    }

    public function close(Request $request, int $id): Response
    {
        $this->ticket($id);
        Support::setClosed($id, true);
        return $this->ok('تم إغلاق التذكرة.', '/support');
    }
}
