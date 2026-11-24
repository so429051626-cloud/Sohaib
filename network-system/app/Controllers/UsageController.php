<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\Units;
use App\Services\UsageGuard;
use RuntimeException;

/**
 * الاستهلاك الزائد: الأعلى سحباً الآن، أكثر المستهلكين، وتحديد السرعة.
 */
final class UsageController extends Controller
{
    public const PERIODS = [1 => 'اليوم (24 ساعة)', 7 => '7 أيام', 30 => '30 يوماً'];

    public function index(Request $request): Response
    {
        $days = array_key_exists($request->int('days', 1), self::PERIODS) ? $request->int('days', 1) : 1;
        $live = UsageGuard::live(20);
        return view('usage/index', [
            'days' => $days,
            'live' => $live,
            'top' => UsageGuard::top($days, 30),
            'caps' => UsageGuard::activeCaps(),
            'threshold' => UsageGuard::threshold(),
            'defaults' => UsageGuard::defaultCap(),
            'heavyNow' => count(array_filter($live, static fn ($s) => $s['heavy_since'] !== null)),
        ]);
    }

    /** تحويل رقم وميغابت/كيلوبت إلى صيغة RouterOS */
    private function rate(Request $request, string $field): ?string
    {
        return CardAccountController::rate($request->str($field), $request->str($field . '_unit') === 'k' ? 'k' : 'M');
    }

    public function cap(Request $request): Response
    {
        $kind = $request->str('kind');
        $id = $request->int('ref_id');
        $minutes = $request->int('minutes', 60);
        if (!in_array($kind, ['card', 'subscriber'], true) || $id <= 0 || !array_key_exists($minutes, UsageGuard::DURATIONS)) {
            return $this->backWithErrors($request, ['kind' => 'طلب غير صالح.'], '/usage');
        }
        $down = $this->rate($request, 'down');
        $up = $this->rate($request, 'up');
        if ($down === null || $up === null) {
            return $this->backWithErrors($request, ['down' => 'حدد سرعة التنزيل والرفع.'], '/usage');
        }
        try {
            UsageGuard::cap($kind, $id, $down, $up, $minutes, $request->str('reason') ?: null);
        } catch (RuntimeException $e) {
            return $this->backWithErrors($request, ['kind' => $e->getMessage()], '/usage');
        }
        return $this->ok('تم تحديد السرعة إلى ' . $down . ' تنزيل / ' . $up . ' رفع' . ($minutes > 0 ? ' لمدة ' . UsageGuard::DURATIONS[$minutes] : ' حتى ترفعه') . '. يُعاد اتصال المستخدم ليعمل بالسرعة الجديدة.', $this->back($request));
    }

    public function uncap(Request $request): Response
    {
        $kind = $request->str('kind');
        $id = $request->int('ref_id');
        if (!in_array($kind, ['card', 'subscriber'], true) || $id <= 0) {
            return $this->backWithErrors($request, ['kind' => 'طلب غير صالح.'], '/usage');
        }
        $done = UsageGuard::uncap($kind, $id);
        return $this->ok($done ? 'رُفع تحديد السرعة، ويعود المستخدم إلى سرعة باقته.' : 'لا يوجد تحديد فعّال لهذا المستخدم.', $this->back($request));
    }

    /** العودة إلى الصفحة التي أُرسل منها الطلب (الاستهلاك الزائد، أو صفحة المشترك/الكرت) */
    private function back(Request $request): string
    {
        $to = $request->str('return');
        return preg_match('#^/(usage|subscribers/\d+|check/card/\d+)(\?[a-z0-9=&]*)?$#', $to) ? $to : '/usage';
    }

    public function settings(Request $request): Response
    {
        $d = $this->validate($request, [
            'heavy_mbps'    => 'required|numeric|between:1,10000',
            'heavy_minutes' => 'required|int|between:1,60',
            'cap_down'      => 'required|numeric|min:0',
            'cap_up'        => 'required|numeric|min:0',
            'cap_minutes'   => 'required|int|in:' . implode(',', array_keys(UsageGuard::DURATIONS)),
        ], ['heavy_mbps' => 'حد السحب', 'heavy_minutes' => 'المدة', 'cap_down' => 'تنزيل السرعة المحددة', 'cap_up' => 'رفع السرعة المحددة', 'cap_minutes' => 'مدة التحديد']);
        $down = CardAccountController::rate((string) $d['cap_down'], $request->str('cap_down_unit') === 'k' ? 'k' : 'M');
        $up = CardAccountController::rate((string) $d['cap_up'], $request->str('cap_up_unit') === 'k' ? 'k' : 'M');
        if ($down === null || $up === null) {
            return $this->backWithErrors($request, ['cap_down' => 'حدد سرعة التنزيل والرفع.'], '/usage');
        }
        $values = [
            'usage.heavy_enabled' => $request->bool('heavy_enabled') ? '1' : '0',
            'usage.heavy_mbps' => rtrim(rtrim(number_format((float) $d['heavy_mbps'], 2, '.', ''), '0'), '.'),
            'usage.heavy_minutes' => (string) (int) $d['heavy_minutes'],
            'usage.cap_down' => $down, 'usage.cap_up' => $up, 'usage.cap_minutes' => (string) (int) $d['cap_minutes'],
            'usage.auto_cap' => $request->bool('auto_cap') ? '1' : '0',
        ];
        Settings::setMany($values);
        Audit::log('settings.update', 'إعدادات كشف الاستهلاك الزائد', 'settings', null, $values);
        return $this->ok('تم حفظ الإعدادات.', '/usage');
    }
}
