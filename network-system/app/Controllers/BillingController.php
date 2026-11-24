<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Platform;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Tenant;
use App\Platform\Billing;
use Throwable;

/**
 * اشتراك الزبون في المنصة من داخل نظامه: الباقة الحالية، طلب التجديد أو الترقية برفع صورة التحويل، والفواتير.
 */
final class BillingController extends Controller
{
    private function tenant(): array
    {
        $t = Tenant::current();
        if ($t === null) {
            throw new HttpException(404);
        }
        return $t;
    }

    public function index(Request $request): Response
    {
        $t = $this->tenant();
        return view('billing/index', [
            't' => $t, 'phase' => Tenant::phase($t), 'phaseEnds' => Tenant::phaseEnds($t), 'daysLeft' => Tenant::daysLeft($t),
            'plan' => Platform::plan(isset($t['plan_id']) ? (int) $t['plan_id'] : null),
            'plans' => Billing::plans(true), 'banks' => Billing::banks(),
            'requests' => Platform::rows('SELECT * FROM transfer_requests WHERE tenant_id = ? ORDER BY id DESC LIMIT 20', [$t['id']]),
            'payments' => Platform::rows('SELECT * FROM tenant_payments WHERE tenant_id = ? ORDER BY id DESC LIMIT 30', [$t['id']]),
            'currency' => Platform::setting('platform.currency'),
        ]);
    }

    /** بريد الحساب الذي تصل إليه الفواتير والتذكيرات وردود الدعم (فارغ = بلا بريد) */
    public function email(Request $request): Response
    {
        $t = $this->tenant();
        $d = $this->validate($request, ['email' => 'nullable|email|max:120'], ['email' => 'البريد الإلكتروني']);
        $email = trim((string) ($d['email'] ?? ''));
        Platform::update('tenants', ['email' => $email !== '' ? $email : null, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$t['id']]);
        Platform::log((int) $t['id'], 'tenant', 'tenant.email', $email !== '' ? 'تغيير بريد الفواتير والتنبيهات' : 'حذف بريد الفواتير والتنبيهات', (string) (Auth::user()['name'] ?? ''));
        Audit::log('billing.email', $email !== '' ? 'تغيير بريد الفواتير إلى ' . $email : 'حذف بريد الفواتير', 'tenant', (int) $t['id']);
        return $this->ok($email !== '' ? 'تم حفظ البريد، وستصل إليه الفواتير والتنبيهات.' : 'تم حذف البريد.', '/billing');
    }

    public function submit(Request $request): Response
    {
        $t = $this->tenant();
        if ($err = $request->uploadError('receipt')) {
            return $this->backWithErrors($request, ['receipt' => $err], '/billing');
        }
        $file = $request->file('receipt');
        if (!$file) {
            return $this->backWithErrors($request, ['receipt' => 'ارفع صورة التحويل.'], '/billing');
        }
        $by = (string) (Auth::user()['name'] ?? '');
        try {
            $id = Billing::submitTransfer($t, [
                'plan_id' => $request->int('plan_id'), 'periods' => $request->int('periods', 1),
                'amount' => (float) str_replace(',', '.', latin_digits($request->str('amount'))),
                'bank_account_id' => $request->int('bank_account_id'), 'reference' => $request->str('reference'),
                'sender_name' => $request->str('sender_name'), 'note' => $request->str('note'),
            ], $file, $by);
        } catch (Throwable $e) {
            return $this->backWithErrors($request, ['receipt' => $e->getMessage()], '/billing');
        }
        Audit::log('billing.transfer', 'إرسال طلب اشتراك بتحويل بنكي #' . $id, 'transfer', $id);
        Session::pull('_old');
        return $this->ok('تم إرسال الطلب. يتمدد الاشتراك بعد موافقة الإدارة.', '/billing');
    }

    public function receipt(Request $request, int $id): Response
    {
        $r = Platform::row('SELECT file FROM transfer_requests WHERE id = ? AND tenant_id = ?', [$id, $this->tenant()['id']]);
        $path = $r ? Billing::receiptPath($r['file']) : null;
        if ($path === null) {
            throw new HttpException(404);
        }
        return AdminBillingController::fileResponse($path, (string) $r['file']);
    }

    public function invoice(Request $request, int $id): Response
    {
        if (!Platform::value('SELECT id FROM tenant_payments WHERE id = ? AND tenant_id = ?', [$id, $this->tenant()['id']])) {
            throw new HttpException(404);
        }
        return view('platform/invoice', ['p' => Billing::invoice($id), 'back' => url('/billing')], 'bare');
    }
}
