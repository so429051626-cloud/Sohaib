<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminAuth;
use App\Core\DB;
use App\Core\Platform;
use App\Core\Request;
use App\Core\Response;
use App\Core\Tenant;
use App\Services\CookiePolicy;

/**
 * الكوكيز (الدخول التلقائي بالجهاز) لكل الشبكات: سياسة عامة، وتعيين أو إيقاف لكل حساب.
 * التطبيق على أجهزة MikroTik يتم تلقائياً من المهمة الدورية لكل حساب خلال دقيقة.
 */
final class AdminCookieController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim($request->str('q'));
        $sql = "SELECT id, name, slug, status, db_name FROM tenants WHERE 1 = 1";
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (name LIKE ? OR slug LIKE ?)';
            $params = ['%' . $q . '%', '%' . $q . '%'];
        }
        $tenants = DB::rows($sql . ' ORDER BY name LIMIT 300', $params);
        $rows = [];
        foreach ($tenants as $t) {
            $own = ['enabled' => null, 'duration' => ''];
            try {
                $st = Tenant::pdo($t)->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('hotspot.mac_cookie', 'hotspot.mac_cookie_timeout')");
                $kv = [];
                foreach ($st->fetchAll() as $r) {
                    $kv[$r['key']] = (string) $r['value'];
                }
                $own = [
                    'enabled'  => in_array($kv['hotspot.mac_cookie'] ?? '1', ['1', 'true', 'yes', 'on'], true),
                    'duration' => $kv['hotspot.mac_cookie_timeout'] ?? CookiePolicy::DEFAULT_DURATION,
                ];
            } catch (\Throwable) {
                // قاعدة الحساب غير متاحة
            }
            $rows[] = $t + [
                'assign' => CookiePolicy::forTenant((int) $t['id']),
                'forced' => CookiePolicy::forced((int) $t['id']),
                'own'    => $own,
            ];
        }
        return view('admin/cookies', [
            'policy' => CookiePolicy::platform(),
            'rows'   => $rows,
            'q'      => $q,
            'total'  => (int) DB::value("SELECT COUNT(*) FROM tenants WHERE 1 = 1"),
        ], 'admin');
    }

    /** السياسة العامة لكل الشبكات */
    public function save(Request $request): Response
    {
        $mode = $request->str('mode');
        if (!isset(CookiePolicy::MODES[$mode])) {
            $mode = 'tenant';
        }
        $duration = $this->duration($request);
        if ($duration === null) {
            return $this->backWithErrors($request, ['duration' => 'المدة غير صالحة: رقم ثم وحدة (m دقيقة، h ساعة، d يوم، w أسبوع)، مثل 12h أو 3d.'], '/admin/cookies');
        }
        Platform::setSettings(['cookies.mode' => $mode, 'cookies.duration' => $duration]);
        $text = match ($mode) {
            'on'    => 'تفعيل الكوكيز لكل الشبكات بمدة ' . CookiePolicy::durationLabel($duration),
            'off'   => 'إيقاف الكوكيز لكل الشبكات',
            default => 'إعادة التحكم بالكوكيز إلى كل شبكة',
        };
        Platform::log(null, 'admin', 'platform.cookies', $text, AdminAuth::user()['name']);
        return $this->ok('تم الحفظ: ' . $text . '. يُطبَّق على أجهزة MikroTik خلال دقيقة.', '/admin/cookies');
    }

    /** تعيين الكوكيز لحساب واحد أو إيقافها */
    public function tenant(Request $request, int $id): Response
    {
        $t = DB::row('SELECT id, name FROM tenants WHERE id = ?', [$id]);
        if (!$t) {
            throw new \App\Core\HttpException(404);
        }
        $mode = $request->str('mode');
        if (!isset(CookiePolicy::TENANT_MODES[$mode])) {
            $mode = '';
        }
        $duration = $this->duration($request);
        if ($duration === null) {
            flash('error', 'المدة غير صالحة: رقم ثم وحدة (m دقيقة، h ساعة، d يوم، w أسبوع)، مثل 12h أو 3d.');
            return redirect($this->back($request));
        }
        CookiePolicy::setTenant($id, $mode, $duration);
        $text = match ($mode) {
            'on'    => 'تعيين الكوكيز بمدة ' . CookiePolicy::durationLabel($duration),
            'off'   => 'إيقاف الكوكيز',
            default => 'إرجاع الكوكيز إلى سياسة المنصة',
        };
        Platform::log($id, 'admin', 'tenant.cookies', $text, AdminAuth::user()['name']);
        return $this->ok($t['name'] . ': ' . $text . '. يُطبَّق على أجهزته خلال دقيقة.', $this->back($request));
    }

    private function duration(Request $request): ?string
    {
        $d = strtolower(trim($request->str('duration')));
        if ($d === 'custom') {
            $d = strtolower(preg_replace('/\s+/', '', $request->str('duration_custom')));
        }
        if ($d === '') {
            return CookiePolicy::DEFAULT_DURATION;
        }
        return CookiePolicy::validDuration($d) ? $d : null;
    }

    private function back(Request $request): string
    {
        $to = $request->str('back');
        return preg_match('#^/admin/(cookies|tenants/\d+)(\?[^\s]*)?$#', $to) ? $to : '/admin/cookies';
    }
}
