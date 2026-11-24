<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

/**
 * إعدادات الأقسام داخل أقسامها: الكروت، المشتركون، الهوتسبوت.
 */
final class ModuleSettingsController extends Controller
{
    private const MODULES = [
        'cards' => [
            'title' => 'إعدادات الكروت', 'icon' => 'ticket', 'url' => '/cards/settings', 'parent' => ['الكروت', '/cards'],
            'fields' => [
                'cards.username_length' => ['required|int|between:4,32', 'طول اسم المستخدم'],
                'cards.password_mode'   => ['required|in:same,separate', 'نمط كلمة المرور'],
                'cards.password_length' => ['required|int|between:3,32', 'طول كلمة المرور'],
                'cards.charset'         => ['required|in:digits,lower,upper,alnum_lower,alnum', 'نوع الرموز'],
                'cards.remove_ended'    => ['bool', 'حذف الكروت المنتهية من أجهزة MikroTik'],
            ],
        ],
        'subscribers' => [
            'title' => 'إعدادات المشتركين', 'icon' => 'users', 'url' => '/subscribers/settings', 'parent' => ['المشتركون', '/subscribers'],
            'fields' => [
                'subscribers.expiry_alert_days' => ['required|int|between:0,30', 'التنبيه قبل الانتهاء'],
                'subscribers.grace_hours'       => ['required|int|between:0,240', 'فترة السماح'],
            ],
        ],
        'hotspot' => [
            'title' => 'إعدادات الهوتسبوت', 'icon' => 'wifi', 'url' => '/hotspot/settings', 'parent' => ['الهوتسبوت', '/hotspot'],
            'fields' => [
                'hotspot.code_case'          => ['required|in:none,lower,upper', 'حالة الأحرف'],
                'hotspot.fail_limit'         => ['required|int|between:3,100', 'حد المحاولات الفاشلة'],
                'hotspot.fail_window'        => ['required|int|between:1,1440', 'نافذة المحاولات'],
                'hotspot.lock_minutes'       => ['required|int|between:1,1440', 'مدة الحظر المؤقت'],
                'hotspot.mac_cookie'         => ['bool', 'الدخول التلقائي بالجهاز'],
                'hotspot.mac_cookie_timeout' => ['required|regex:/^\d+[mhdw]$/', 'مدة الدخول التلقائي'],
                'hotspot.events'             => ['bool', 'إشعارات الدخول والخروج من جهاز MikroTik'],
                'hotspot.fallback'           => ['bool', 'الدخول المباشر عند تعذّر الوصول للنظام'],
                'hotspot.timeout'            => ['required|int|between:2,30', 'مهلة الاتصال بالنظام'],
                'hotspot.allow_unsold'       => ['bool', 'السماح بالكروت غير المباعة'],
                'hotspot.local_users'        => ['bool', 'دخول مستخدمي MikroTik المحليين'],
            ],
        ],
    ];

    public function cards(Request $request): Response { return $this->show('cards'); }
    public function subscribers(Request $request): Response { return $this->show('subscribers'); }
    public function hotspot(Request $request): Response { return $this->show('hotspot'); }
    public function saveCards(Request $request): Response { return $this->save($request, 'cards'); }
    public function saveSubscribers(Request $request): Response { return $this->save($request, 'subscribers'); }
    public function saveHotspot(Request $request): Response { return $this->save($request, 'hotspot'); }

    private function show(string $module): Response
    {
        $m = self::MODULES[$module];
        return view('settings/module', ['module' => $module, 'm' => $m]);
    }

    private function save(Request $request, string $module): Response
    {
        $m = self::MODULES[$module];
        $input = $this->settingsInput($request, $m['fields']);
        Settings::setMany($input);
        Audit::log('settings.update', 'تعديل ' . $m['title'], 'settings', null, $input);
        return $this->ok('تم حفظ الإعدادات.', $m['url']);
    }
}
