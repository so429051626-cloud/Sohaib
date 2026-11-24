<?php
declare(strict_types=1);

namespace App;

/**
 * صلاحيات فريق إدارة المنصة والأدوار الجاهزة.
 * المالك (أول مسؤول) له كل الصلاحيات، وهو وحده من يدير الفريق. المسؤول بلا قائمة صلاحيات (null) مدير كامل،
 * وهكذا يبقى المسؤولون الذين سبقوا هذه الميزة كما كانوا.
 */
final class AdminPermissions
{
    public const GROUPS = [
        'الحسابات' => [
            'tenants.view'   => 'عرض الحسابات ومستخدميها والنشاط',
            'tenants.manage' => 'إنشاء الحسابات وتعديلها وإيقافها وحذفها ونسخها الاحتياطي',
            'tenants.access' => 'الدخول إلى نظام الزبون، وتغيير كلمة مروره وإلغاء تحققه بخطوتين',
        ],
        'الاشتراكات والمال' => [
            'billing.view'   => 'عرض المدفوعات والفواتير وتحويلات الزبائن',
            'billing.manage' => 'تفعيل الاشتراكات والموافقة على التحويلات والدفعات والباقات والفوترة',
        ],
        'التواصل' => [
            'support.manage' => 'تذاكر الدعم والإعلانات للزبائن',
            'messages.send'  => 'إرسال الرسائل النصية والبريد لأصحاب الحسابات',
            'bot.chats'      => 'محادثات بوت خدمة الزبائن (واتساب والموقع) والرد عليها',
            'bot.manage'     => 'إعدادات بوت خدمة الزبائن وقنواته والربط مع MADIX Cloud',
        ],
        'الذكاء والتحليلات' => [
            'ai.manage'      => 'إعداد الذكاء الاصطناعي ومساعد المسؤول والتحليلات',
        ],
        'المظهر' => [
            'design.manage'  => 'التصاميم والحركة وصفحة الهبوط',
        ],
        'الإعدادات' => [
            'settings.manage' => 'إعدادات المنصة والخادم وخوادم البريد والرسائل وإشعارات تيليجرام',
        ],
    ];

    /** الأدوار الجاهزة: المفتاح => [الاسم، الوصف، الصلاحيات أو * للكل] */
    public const PRESETS = [
        'full'    => ['مدير كامل', 'كل شيء عدا إدارة الفريق', '*'],
        'sales'   => ['المبيعات والاشتراكات', 'الحسابات والاشتراكات والدفعات ومراسلة الزبائن', ['tenants.view', 'tenants.manage', 'billing.view', 'billing.manage', 'messages.send']],
        'support' => ['الدعم الفني', 'تذاكر الدعم ومحادثات البوت والدخول إلى أنظمة الزبائن لمساعدتهم', ['tenants.view', 'tenants.access', 'support.manage', 'messages.send', 'bot.chats']],
        'viewer'  => ['مراقب', 'عرض الحسابات والمدفوعات دون أي تعديل', ['tenants.view', 'billing.view']],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        $out = [];
        foreach (self::GROUPS as $perms) {
            array_push($out, ...array_keys($perms));
        }
        return $out;
    }

    /** صلاحيات مسؤول: null في القاعدة = الكل */
    public static function of(array $admin): array
    {
        if (!empty($admin['is_owner']) || ($admin['permissions'] ?? null) === null) {
            return self::all();
        }
        $list = json_decode((string) $admin['permissions'], true);
        return is_array($list) ? array_values(array_intersect(self::all(), $list)) : [];
    }

    /** تصفية صلاحيات واردة من نموذج */
    public static function filter(array $input): array
    {
        return array_values(array_intersect(self::all(), array_map('strval', $input)));
    }

    /** اسم الدور الذي تطابقه الصلاحيات، أو «مخصّص» */
    public static function roleOf(array $admin): string
    {
        if (!empty($admin['is_owner'])) {
            return 'المالك';
        }
        $have = self::of($admin);
        sort($have);
        foreach (self::PRESETS as [$label, , $perms]) {
            $p = $perms === '*' ? self::all() : $perms;
            sort($p);
            if ($p === $have) {
                return $label;
            }
        }
        return 'مخصّص';
    }

    public static function label(string $perm): string
    {
        foreach (self::GROUPS as $perms) {
            if (isset($perms[$perm])) {
                return $perms[$perm];
            }
        }
        return $perm;
    }
}
