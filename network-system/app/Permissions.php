<?php
declare(strict_types=1);

namespace App;

/**
 * دليل الصلاحيات والأدوار الجاهزة.
 */
final class Permissions
{
    public const GROUPS = [
        'لوحة التحكم' => [
            'dashboard.view'         => 'عرض لوحة التحكم',
        ],
        'أجهزة MikroTik' => [
            'routers.view'           => 'عرض أجهزة MikroTik وبياناتها',
            'routers.manage'         => 'إضافة أجهزة MikroTik وتعديلها وحذفها',
            'routers.control'        => 'التحكم بجهاز MikroTik (المستخدمون المحليون، ربط الأجهزة، الأدوات)',
            'routers.reboot'         => 'إعادة تشغيل جهاز MikroTik',
            'routers.backup'         => 'النسخ الاحتياطي لجهاز MikroTik',
            'routers.cables'         => 'فحص الكابلات ومنافذ Ethernet وإعداد الفحص الدوري',
            'areas.manage'           => 'إدارة المناطق',
            'map.view'               => 'عرض خريطة الشبكة',
            'map.manage'             => 'إدارة المواقع والأبراج والروابط',
        ],
        'المخزون' => [
            'inventory.view'         => 'عرض المخزون والحركات',
            'inventory.manage'       => 'إدارة الأصناف والأجهزة وحركات المخزون',
        ],
        'المتصلون والأجهزة' => [
            'sessions.view'          => 'عرض المتصلين والجلسات',
            'sessions.disconnect'    => 'فصل المتصلين',
            'devices.view'           => 'عرض دليل الأجهزة',
            'devices.block'          => 'حظر الأجهزة وإلغاء حظرها',
            'usage.view'             => 'عرض الاستهلاك الزائد وأكثر المستهلكين',
            'usage.limit'            => 'تحديد سرعة المستهلكين ورفع التحديد',
        ],
        'الكروت' => [
            'categories.view'        => 'عرض الباقات',
            'categories.manage'      => 'إدارة الباقات',
            'cards.view'             => 'عرض الكروت',
            'cards.monitor'          => 'متابعة الكروت (النشطة والمنتهية والمُدخلة خطأ في الهوتسبوت)',
            'cards.passwords'        => 'عرض كلمات مرور الكروت',
            'cards.generate'         => 'توليد الكروت',
            'cards.import'           => 'استيراد الكروت',
            'cards.short'            => 'اختصار أرقام الكروت',
            'cards.export'           => 'تصدير الكروت',
            'cards.manage'           => 'تعديل الكروت (إيقاف، تمديد، نقل، رفع لجهاز MikroTik)',
            'cards.delete'           => 'حذف الكروت والدفعات',
            'cards.print'            => 'طباعة الكروت',
            'cards.accounts'         => 'الحسابات الخاصة (إنشاء حسابات هوتسبوت باسم مستخدم وكلمة مرور وسرعة ومدة خاصة، وتعديلها وحذفها)',
            'print.templates'        => 'إدارة قوالب الطباعة',
        ],
        'فحص الكرت' => [
            'check.use'              => 'فحص الكروت والمشتركين',
            'check.password'         => 'تغيير كلمة المرور من صفحة الفحص',
            'check.actions'          => 'إجراءات الفحص (فك الربط، التصفير، التمديد، الإيقاف)',
        ],
        'المشتركون' => [
            'subscribers.view'       => 'عرض المشتركين',
            'subscribers.passwords'  => 'عرض كلمات مرور المشتركين',
            'subscribers.manage'     => 'إضافة المشتركين وتعديلهم',
            'subscribers.delete'     => 'حذف المشتركين',
            'subscribers.renew'      => 'تجديد الاشتراكات',
            'subscribers.payments'   => 'تسجيل الدفعات والقيود المالية',
            'subscribers.status'     => 'إيقاف المشتركين وتفعيلهم',
            'subscribers.messages'   => 'رسائل المشتركين على واتساب المنصة والرد عليها',
            'plans.view'             => 'عرض باقات الاشتراك',
            'plans.manage'           => 'إدارة باقات الاشتراك',
        ],
        'المبيعات والموزعون' => [
            'sales.view'             => 'عرض المبيعات',
            'sales.create'           => 'تسجيل عمليات البيع',
            'sales.cancel'           => 'إلغاء عمليات البيع',
            'distributors.view'      => 'عرض الموزعين وكشوف الحساب',
            'distributors.manage'    => 'إدارة الموزعين',
            'distributors.payments'  => 'تسجيل دفعات الموزعين',
        ],
        'مهام الصيانة' => [
            'maintenance.view'       => 'عرض كل مهام الصيانة',
            'maintenance.manage'     => 'إنشاء مهام الصيانة وإسنادها وتعديلها وحذفها',
            'maintenance.work'       => 'تنفيذ المهام المسندة إليه (الحالة والقطع المصروفة)',
        ],
        'المصاريف والأرباح' => [
            'expenses.view'          => 'عرض المصاريف',
            'expenses.manage'        => 'تسجيل المصاريف وتعديلها وحذفها',
            'profit.view'            => 'عرض صافي الربح وحصص الشركاء',
            'profit.manage'          => 'إدارة الشركاء ونسبهم',
        ],
        'ربط الهوتسبوت' => [
            'hotspot.manage'         => 'إدارة ربط الهوتسبوت ونشر الملفات',
            'hotspot.log'            => 'عرض سجل محاولات الدخول',
            'hotspot.ads'            => 'إعلانات صفحة الهوتسبوت (عروض الشبكة وإعلانات المحلات)',
        ],
        'التقارير' => [
            'reports.view'           => 'عرض التقارير التشغيلية',
            'reports.financial'      => 'عرض التقارير المالية',
        ],
        'الرسائل النصية' => [
            'sms.send'               => 'إرسال الرسائل النصية',
            'sms.log'                => 'عرض سجل الرسائل',
            'sms.manage'             => 'إعدادات الرسائل والقوالب',
        ],
        'الطاقة والانقطاعات' => [
            'power.view'             => 'عرض مراقبة البطاريات والطاقة الشمسية',
            'power.manage'           => 'ضبط حدود الجهد لكل جهاز',
            'outages.view'           => 'عرض سجل الانقطاعات',
            'outages.manage'         => 'إضافة الانقطاعات وتعديلها',
            'outages.compensate'     => 'تعويض المشتركين عن الانقطاعات',
        ],
        'الذكاء الاصطناعي' => [
            'assistant.use'          => 'استخدام المساعد الذكي (يرى ما تسمح به بقية صلاحياته فقط)',
        ],
        'الاشتراك والدعم' => [
            'billing.manage'         => 'اشتراك المنصة ورفع التحويلات والفواتير',
            'support.use'            => 'تذاكر الدعم',
        ],
        'الإدارة' => [
            'employees.manage'       => 'إدارة الموظفين والصلاحيات',
            'audit.view'             => 'عرض سجل العمليات',
            'alerts.view'            => 'عرض التنبيهات',
            'jobs.view'              => 'عرض العمليات الجارية',
            'settings.manage'        => 'إدارة الإعدادات',
            'telegram.manage'        => 'إدارة بوت تيليجرام',
            'backup.manage'          => 'النسخ الاحتياطي لقاعدة البيانات',
        ],
    ];

    public const PRESETS = [
        'manager' => [
            'label' => 'مدير',
            'permissions' => '*',
        ],
        'accountant' => [
            'label' => 'محاسب',
            'permissions' => [
                'dashboard.view', 'categories.view', 'cards.view', 'cards.export', 'check.use',
                'subscribers.view', 'subscribers.renew', 'subscribers.payments', 'plans.view',
                'sales.view', 'sales.create', 'sales.cancel', 'distributors.view', 'distributors.manage',
                'distributors.payments', 'reports.view', 'reports.financial', 'alerts.view', 'inventory.view', 'sms.send', 'sms.log',
                'expenses.view', 'expenses.manage', 'profit.view', 'assistant.use',
            ],
        ],
        'technician' => [
            'label' => 'فني شبكة',
            'permissions' => [
                'dashboard.view', 'routers.view', 'routers.control', 'routers.reboot', 'routers.backup', 'routers.cables',
                'sessions.view', 'sessions.disconnect', 'devices.view', 'devices.block', 'usage.view', 'usage.limit',
                'categories.view', 'cards.view', 'cards.monitor', 'check.use', 'check.actions',
                'subscribers.view', 'subscribers.status', 'plans.view', 'hotspot.manage', 'hotspot.log',
                'reports.view', 'alerts.view', 'jobs.view', 'map.view', 'map.manage', 'inventory.view', 'inventory.manage',
                'power.view', 'power.manage', 'outages.view', 'outages.manage', 'assistant.use', 'maintenance.work',
            ],
        ],
        'seller' => [
            'label' => 'بائع',
            'permissions' => [
                'dashboard.view', 'categories.view', 'cards.view', 'cards.print', 'check.use',
                'subscribers.view', 'subscribers.renew', 'subscribers.payments',
                'sales.view', 'sales.create', 'distributors.view',
            ],
        ],
        'viewer' => [
            'label' => 'مراقب',
            'permissions' => [
                'dashboard.view', 'routers.view', 'sessions.view', 'devices.view', 'usage.view', 'categories.view',
                'cards.view', 'check.use', 'subscribers.view', 'plans.view', 'sales.view',
                'distributors.view', 'reports.view', 'alerts.view', 'map.view', 'inventory.view', 'power.view', 'outages.view',
            ],
        ],
    ];

    public static function all(): array
    {
        $all = [];
        foreach (self::GROUPS as $perms) {
            foreach ($perms as $key => $label) {
                $all[] = $key;
            }
        }
        return $all;
    }

    public static function presetPermissions(string $preset): array
    {
        $p = self::PRESETS[$preset]['permissions'] ?? [];
        return $p === '*' ? self::all() : $p;
    }

    public static function label(string $permission): string
    {
        foreach (self::GROUPS as $perms) {
            if (isset($perms[$permission])) {
                return $perms[$permission];
            }
        }
        return $permission;
    }

    /** تصفية قائمة صلاحيات واردة من نموذج إلى المعروفة فقط */
    public static function filter(array $input): array
    {
        return array_values(array_intersect(self::all(), array_map('strval', $input)));
    }
}
