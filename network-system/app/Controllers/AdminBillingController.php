<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminAuth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Platform;
use App\Core\Request;
use App\Core\Response;
use App\Platform\Billing;
use Throwable;

/**
 * لوحة المسؤول: باقات المنصة، الحسابات البنكية، تحويلات الزبائن، الفواتير، وإعدادات مسار الاشتراك.
 */
final class AdminBillingController extends Controller
{
    private function admin(): string
    {
        return (string) (AdminAuth::user()['name'] ?? '');
    }

    private static function nullableInt(mixed $v): ?int
    {
        $v = trim(latin_digits((string) $v));
        return $v === '' ? null : max(0, (int) $v);
    }

    // ------------------------------------------------------------------
    // الباقات
    // ------------------------------------------------------------------

    public function plans(Request $request): Response
    {
        $plans = DB::rows('SELECT p.*, (SELECT COUNT(*) FROM tenants t WHERE t.plan_id = p.id) AS tenants FROM platform_plans p ORDER BY p.sort, p.price, p.id');
        return view('admin/plans', ['plans' => $plans], 'admin');
    }

    public function planSave(Request $request): Response
    {
        $id = $request->int('id');
        $d = $this->validate($request, [
            'name' => 'required|max:80', 'tagline' => 'nullable|max:160', 'price' => 'required|numeric|between:0,99999999',
            'months' => 'required|int|between:1,60', 'sort' => 'nullable|int|between:-999,999', 'features' => 'nullable|max:2000',
        ], ['name' => 'اسم الباقة', 'tagline' => 'الوصف المختصر', 'price' => 'السعر', 'months' => 'المدة', 'sort' => 'الترتيب', 'features' => 'المزايا']);
        $modules = array_values(array_intersect(array_keys(Platform::MODULES), array_map('strval', $request->arr('modules'))));
        $data = [
            'name' => $d['name'], 'tagline' => $d['tagline'], 'price' => round((float) $d['price'], 2), 'months' => (int) $d['months'],
            'max_routers' => self::nullableInt($request->input('max_routers')), 'max_subscribers' => self::nullableInt($request->input('max_subscribers')),
            'max_employees' => self::nullableInt($request->input('max_employees')), 'ai_messages' => self::nullableInt($request->input('ai_messages')),
            // كل الأقسام مفعّلة = بلا قيد
            'modules' => count($modules) === count(Platform::MODULES) ? null : json_encode($modules),
            'features' => $d['features'], 'is_public' => $request->bool('is_public') ? 1 : 0, 'is_featured' => $request->bool('is_featured') ? 1 : 0,
            'is_active' => $request->bool('is_active') ? 1 : 0, 'sort' => (int) ($d['sort'] ?? 0),
        ];
        if ($id > 0) {
            $this->notFoundUnless(DB::value('SELECT id FROM platform_plans WHERE id = ?', [$id]));
            DB::update('platform_plans', $data + ['updated_at' => now()], 'id = ?', [$id]);
            if ($request->bool('apply_limits')) {
                DB::update('tenants', ['max_routers' => $data['max_routers'], 'max_subscribers' => $data['max_subscribers'], 'max_employees' => $data['max_employees']], 'plan_id = ?', [$id]);
            }
        } else {
            $id = DB::insert('platform_plans', $data + ['created_at' => now()]);
        }
        Platform::flushPlans();
        Platform::log(null, 'admin', 'plan.save', 'حفظ الباقة ' . $d['name'], $this->admin());
        return $this->ok('تم حفظ الباقة.', '/admin/plans');
    }

    public function planDelete(Request $request, int $id): Response
    {
        $p = DB::row('SELECT * FROM platform_plans WHERE id = ?', [$id]);
        $this->notFoundUnless($p);
        if ((int) DB::value('SELECT COUNT(*) FROM tenants WHERE plan_id = ?', [$id]) > 0) {
            DB::update('platform_plans', ['is_active' => 0, 'is_public' => 0, 'updated_at' => now()], 'id = ?', [$id]);
            Platform::flushPlans();
            return $this->ok('الباقة مستخدمة في حسابات، فتم إيقافها بدل حذفها.', '/admin/plans');
        }
        DB::delete('platform_plans', 'id = ?', [$id]);
        Platform::flushPlans();
        Platform::log(null, 'admin', 'plan.delete', 'حذف الباقة ' . $p['name'], $this->admin());
        return $this->ok('تم حذف الباقة.', '/admin/plans');
    }

    // ------------------------------------------------------------------
    // إعدادات الاشتراك والحسابات البنكية
    // ------------------------------------------------------------------

    public function settings(Request $request): Response
    {
        return view('admin/billing', ['banks' => Billing::banks(false)], 'admin');
    }

    public function settingsSave(Request $request): Response
    {
        $d = $this->validate($request, [
            'remind_days' => 'nullable|max:40|regex:/^[0-9٠-٩,، ]*$/u', 'grace_days' => 'required|int|between:0,60', 'readonly_days' => 'required|int|between:0,90',
            'invoice_prefix' => 'required|max:8|regex:/^[A-Za-z0-9]+$/', 'invoice_note' => 'nullable|max:300',
        ], ['remind_days' => 'أيام التذكير', 'grace_days' => 'فترة السماح', 'readonly_days' => 'مدة القراءة فقط', 'invoice_prefix' => 'بادئة رقم الفاتورة', 'invoice_note' => 'ملاحظة الفاتورة']);
        Platform::setSettings([
            'billing.remind_days' => (string) $d['remind_days'], 'billing.grace_days' => (string) $d['grace_days'], 'billing.readonly_days' => (string) $d['readonly_days'],
            'billing.remind_sms' => $request->bool('remind_sms') ? '1' : '0', 'billing.invoice_prefix' => strtoupper($d['invoice_prefix']), 'billing.invoice_note' => (string) $d['invoice_note'],
        ]);
        Platform::log(null, 'admin', 'billing.settings', 'تعديل إعدادات الاشتراك والفوترة', $this->admin());
        return $this->ok('تم حفظ الإعدادات.', '/admin/billing');
    }

    public function bankSave(Request $request): Response
    {
        $id = $request->int('id');
        $d = $this->validate($request, ['label' => 'required|max:80', 'holder' => 'required|max:120', 'number' => 'required|max:80', 'details' => 'nullable|max:255', 'sort' => 'nullable|int|between:-999,999'],
            ['label' => 'اسم البنك أو المحفظة', 'holder' => 'اسم صاحب الحساب', 'number' => 'رقم الحساب', 'details' => 'تفاصيل إضافية']);
        $data = ['label' => $d['label'], 'holder' => $d['holder'], 'number' => $d['number'], 'details' => $d['details'], 'is_active' => $request->bool('is_active') ? 1 : 0, 'sort' => (int) ($d['sort'] ?? 0)];
        if ($id > 0) {
            DB::update('bank_accounts', $data, 'id = ?', [$id]);
        } else {
            DB::insert('bank_accounts', $data + ['created_at' => now()]);
        }
        Platform::log(null, 'admin', 'bank.save', 'حفظ الحساب البنكي ' . $d['label'], $this->admin());
        return $this->ok('تم حفظ الحساب.', '/admin/billing');
    }

    public function bankDelete(Request $request, int $id): Response
    {
        DB::delete('bank_accounts', 'id = ?', [$id]);
        return $this->ok('تم حذف الحساب.', '/admin/billing');
    }

    // ------------------------------------------------------------------
    // تحويلات الزبائن
    // ------------------------------------------------------------------

    public function transfers(Request $request): Response
    {
        $status = isset(Billing::TRANSFER_STATUS[$request->str('status')]) ? $request->str('status') : ($request->str('status') === 'all' ? 'all' : 'pending');
        $where = $status === 'all' ? '1 = 1' : 'r.status = ?';
        $params = $status === 'all' ? [] : [$status];
        $total = (int) DB::value("SELECT COUNT(*) FROM transfer_requests r WHERE $where", $params);
        $pager = Paginator::fromRequest($request, $total, 30);
        $rows = DB::rows("SELECT r.*, t.slug, t.status AS tenant_status, t.paid_until, t.trial_ends_at FROM transfer_requests r JOIN tenants t ON t.id = r.tenant_id WHERE $where ORDER BY r.status = 'pending' DESC, r.id DESC" . $pager->limitSql(), $params);
        foreach ($rows as &$r) {
            $r['duplicates'] = Billing::duplicateReference($r['reference'], (int) $r['id']);
        }
        unset($r);
        return view('admin/transfers', [
            'rows' => $rows, 'pager' => $pager, 'request' => $request, 'status' => $status,
            'counts' => DB::pairs('SELECT status, COUNT(*) FROM transfer_requests GROUP BY status'),
        ], 'admin');
    }

    public function transferFile(Request $request, int $id): Response
    {
        $t = DB::row('SELECT file FROM transfer_requests WHERE id = ?', [$id]);
        $path = $t ? Billing::receiptPath($t['file']) : null;
        if ($path === null) {
            throw new HttpException(404);
        }
        return self::fileResponse($path, (string) $t['file']);
    }

    public static function fileResponse(string $path, string $name): Response
    {
        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => Billing::receiptMime($name), 'Content-Disposition' => 'inline; filename="receipt.' . pathinfo($name, PATHINFO_EXTENSION) . '"',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    public function transferApprove(Request $request, int $id): Response
    {
        try {
            $r = Billing::approveTransfer($id, $this->admin(), $request->str('note'));
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            return redirect('/admin/transfers');
        }
        flash('success', 'تمت الموافقة وتمديد الاشتراك' . ($r['until'] ? ' حتى ' . substr((string) $r['until'], 0, 10) : '') . '.');
        return $r['payment_id'] ? redirect('/admin/transfers', ['invoice' => $r['payment_id']]) : redirect('/admin/transfers');
    }

    public function transferReject(Request $request, int $id): Response
    {
        try {
            Billing::rejectTransfer($id, $this->admin(), $request->str('reason'));
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            return redirect('/admin/transfers');
        }
        return $this->ok('تم رفض الطلب.', '/admin/transfers');
    }

    // ------------------------------------------------------------------
    // الفواتير
    // ------------------------------------------------------------------

    public function invoice(Request $request, int $id): Response
    {
        $p = Billing::invoice($id);
        $this->notFoundUnless($p);
        return view('platform/invoice', ['p' => $p, 'back' => root_path() . '/admin/payments'], 'bare');
    }
}
