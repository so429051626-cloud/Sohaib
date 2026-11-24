<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * سجل العمليات: من فعل ماذا ومتى.
 */
final class Audit
{
    public const ACTIONS = [
        'auth.login'          => 'تسجيل دخول',
        'auth.logout'         => 'تسجيل خروج',
        'auth.failed'         => 'دخول فاشل',
        'auth.device'         => 'الدخول بالبصمة',
        'auth.device_add'     => 'تفعيل البصمة على جهاز',
        'auth.device_revoke'  => 'إلغاء جهاز موثوق',
        'auth.2fa_on'         => 'تفعيل التحقق بخطوتين',
        'auth.2fa_off'        => 'إيقاف التحقق بخطوتين',
        'auth.2fa_codes'      => 'رموز استرجاع جديدة',
        'auth.2fa_reset'      => 'إلغاء التحقق بخطوتين لمستخدم',
        'auth.recovery'       => 'دخول برمز استرجاع',
        'employee.create'     => 'إضافة موظف',
        'employee.update'     => 'تعديل موظف',
        'employee.delete'     => 'حذف موظف',
        'employee.logout'     => 'إنهاء جلسات موظف',
        'settings.update'     => 'تعديل الإعدادات',
        'backup.download'     => 'تنزيل نسخة احتياطية',
        'backup.restore'      => 'استعادة نسخة احتياطية',
        'router.create'       => 'إضافة جهاز MikroTik',
        'router.update'       => 'تعديل جهاز MikroTik',
        'router.delete'       => 'حذف جهاز MikroTik',
        'router.action'       => 'إجراء على جهاز MikroTik',
        'area.save'           => 'حفظ منطقة',
        'area.delete'         => 'حذف منطقة',
        'category.create'     => 'إضافة باقة',
        'category.update'     => 'تعديل باقة',
        'category.delete'     => 'حذف باقة',
        'cards.generate'      => 'توليد كروت',
        'cards.import'        => 'استيراد كروت',
        'cards.export'        => 'تصدير كروت',
        'cards.bulk'          => 'إجراء جماعي على كروت',
        'cards.print'         => 'طباعة كروت',
        'cards.short'         => 'اختصار أرقام',
        'cards.account'       => 'الحسابات الخاصة',
        'hotspot.ads'         => 'إعلانات صفحة الهوتسبوت',
        'router.connect'      => 'ربط جهاز MikroTik',
        'usage.cap'           => 'تحديد السرعة',
        'card.update'         => 'تعديل كرت',
        'card.password'       => 'تغيير كلمة مرور كرت',
        'batch.delete'        => 'حذف دفعة',
        'batch.routers'       => 'تعديل أجهزة دفعة',
        'plan.save'           => 'حفظ باقة اشتراك',
        'plan.delete'         => 'حذف باقة اشتراك',
        'subscriber.create'   => 'إضافة مشترك',
        'subscriber.import'   => 'استيراد ذكي للمشتركين',
        'subscriber.update'   => 'تعديل مشترك',
        'subscriber.delete'   => 'حذف مشترك',
        'subscriber.renew'    => 'تجديد اشتراك',
        'subscriber.payment'  => 'دفعة مشترك',
        'subscriber.status'   => 'تغيير حالة مشترك',
        'subscriber.export'   => 'تصدير المشتركين',
        'distributor.save'    => 'حفظ موزع',
        'distributor.delete'  => 'حذف موزع',
        'distributor.payment' => 'دفعة موزع',
        'sale.create'         => 'عملية بيع',
        'sale.cancel'         => 'إلغاء بيع',
        'template.save'       => 'حفظ قالب طباعة',
        'template.delete'     => 'حذف قالب طباعة',
        'hotspot.deploy'      => 'نشر ملفات الربط',
        'device.block'        => 'حظر جهاز',
        'device.unblock'      => 'إلغاء حظر جهاز',
        'session.disconnect'  => 'فصل جلسة',
        'job.create'          => 'بدء عملية',
        'report.export'       => 'تصدير تقرير',
        'site.save'           => 'حفظ موقع',
        'site.delete'         => 'حذف موقع',
        'map.move'            => 'تحريك على الخريطة',
        'sms.send'            => 'إرسال رسالة نصية',
        'inventory.item'      => 'صنف مخزون',
        'inventory.move'      => 'حركة مخزون',
        'inventory.unit'      => 'جهاز مرقّم',
        'inventory.export'    => 'تصدير المخزون',
        'power.thresholds'    => 'حدود جهد جهاز',
        'outage.create'       => 'تسجيل انقطاع',
        'outage.update'       => 'تعديل انقطاع',
        'outage.close'        => 'إغلاق انقطاع',
        'outage.delete'       => 'حذف انقطاع',
        'outage.compensate'   => 'تعويض المشتركين عن انقطاع',
        'assistant.action'    => 'إجراء عبر المساعد الذكي',
        'router.diagnose'     => 'فحص ذكي لجهاز MikroTik',
        'maintenance.create'  => 'إنشاء مهمة صيانة',
        'maintenance.update'  => 'تعديل مهمة صيانة',
        'maintenance.status'  => 'تغيير حالة مهمة صيانة',
        'maintenance.part'    => 'قطع مهمة صيانة',
        'maintenance.delete'  => 'حذف مهمة صيانة',
        'expense.create'      => 'تسجيل مصروف',
        'expense.update'      => 'تعديل مصروف',
        'expense.delete'      => 'حذف مصروف',
        'partner.create'      => 'إضافة شريك',
        'partner.update'      => 'تعديل شريك',
        'partner.delete'      => 'حذف شريك',
        'outage.settings'     => 'إعدادات الانقطاعات والتعويض',
        'billing.transfer'    => 'طلب اشتراك بتحويل',
        'auth.switch'         => 'الانتقال إلى شبكة أخرى',
    ];

    public static function log(
        string $action,
        string $summary,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = [],
        ?int $employeeId = null
    ): void {
        try {
            DB::insert('audit_log', [
                'employee_id' => $employeeId ?? Auth::id(),
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'summary'     => mb_substr($summary, 0, 255),
                'details'     => $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'ip'          => PHP_SAPI === 'cli' ? 'cli' : client_ip(),
                'created_at'  => now(),
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Audit log failed: ' . $e->getMessage());
        }
    }

    public static function label(string $action): string
    {
        return self::ACTIONS[$action] ?? $action;
    }
}
