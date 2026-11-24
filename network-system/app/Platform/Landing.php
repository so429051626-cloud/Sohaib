<?php
declare(strict_types=1);

namespace App\Platform;

use App\Core\Platform;

/**
 * محتوى صفحة الهبوط القابل للتعديل من لوحة المسؤول.
 * النصوص في إعدادات المنصة (landing.*)؛ القوائم مخزّنة نصاً: سطر لكل عنصر «العنوان | الوصف».
 */
final class Landing
{
    public const DEFAULTS = [
        'landing.hero_badge'    => 'منصة إدارة الشبكات',
        'landing.hero_title'    => 'أدِر شبكتك كاملة من مكان واحد',
        'landing.hero_text'     => 'أجهزة MikroTik، الكروت والهوتسبوت، المشتركون والمبيعات، الخريطة والمخزون، والتقارير — في نظام واحد أنيق يعمل على الكمبيوتر والهاتف.',
        'landing.cta_primary'   => 'ابدأ تجربتك المجانية',
        'landing.cta_secondary' => 'تسجيل الدخول',
        'landing.trial_note'    => '14 يوماً مجاناً · بدون بطاقة دفع',
        'landing.features_title' => 'كل ما تحتاجه شبكتك',
        'landing.features_text' => 'أدوات احترافية صُممت لأصحاب الشبكات والموزعين والفنيين.',
        'landing.features'      => "ربط MikroTik | مزامنة لحظية لأجهزة MikroTik والمتصلين والسرعات وتجهيز تلقائي.\nالكروت والهوتسبوت | توليد وطباعة بتصاميم احترافية ودخول بالرقم المختصر.\nالمشتركون والفواتير | تجديد وتنبيهات انتهاء وكشوف حساب ومستحقات.\nخريطة الشبكة | الأبراج والروابط ومواقع المشتركين على خريطة حية.\nالمخزون والمعدات | الأجهزة بأرقامها التسلسلية والكوابل بالمتر والحركات.\nبوت تيليجرام | تقارير يومية وأسبوعية وشهرية وخدمة للزبائن.\nنفق آمن | ربط أجهزة MikroTik التي لا تملك عنواناً عاماً باتصال مشفّر.\nالتقارير | المبيعات والأرباح والاستهلاك والمخزون مع طباعة وتصدير.\nالرسائل النصية | تذكير بانتهاء الاشتراك وإشعارات التجديد وحملات للمشتركين.",
        'landing.steps_title'   => 'ابدأ في ثلاث خطوات',
        'landing.steps'         => "أنشئ حسابك | اختر اسم شبكتك واحصل على رابطك الخاص فوراً.\nاربط أجهزة MikroTik | أضف أجهزة MikroTik ويبدأ النظام بالمزامنة.\nابدأ البيع | ولّد الكروت واطبعها وتابع كل شيء من لوحة واحدة.",
        'landing.stats'         => "14 يوماً | تجربة مجانية كاملة\n24/7 | مراقبة لأجهزة MikroTik\n3 | منصات: ويب، ويندوز، أندرويد\n100% | قاعدة بيانات مستقلة لكل حساب",
        'landing.pricing_title' => 'باقات تناسب حجم شبكتك',
        'landing.pricing_text'  => 'ابدأ بالتجربة المجانية، ثم اختر الباقة التي تناسبك.',
        'landing.apps_title'    => 'تطبيقات Windows وAndroid',
        'landing.apps_text'     => 'ادخل إلى حسابك من أي جهاز، مع الدخول بالبصمة والطباعة المباشرة.',
        'landing.faq_title'     => 'أسئلة شائعة',
        'landing.faq'           => "هل أحتاج بطاقة دفع للتجربة؟ | لا، التجربة مجانية لمدة 14 يوماً دون أي بيانات دفع.\nهل بياناتي منفصلة عن غيري؟ | نعم، لكل حساب قاعدة بيانات مستقلة تماماً.\nهل يعمل مع أي جهاز MikroTik؟ | نعم، مع أنظمة RouterOS 6 و7 عبر واجهة API.\nكيف أفعّل الاشتراك بعد التجربة؟ | تواصل معنا عبر واتساب ويتم التفعيل مباشرة.",
        'landing.cta_title'     => 'جاهز لتنظيم شبكتك؟',
        'landing.cta_text'      => 'أنشئ حسابك الآن وابدأ خلال دقائق.',
        'landing.footer_text'   => 'نظام متكامل لإدارة شبكات الإنترنت بأجهزة MikroTik: الكروت والمشتركون والمبيعات والتقارير في مكان واحد.',
        'landing.show_features' => '1',
        'landing.show_steps'    => '1',
        'landing.show_stats'    => '1',
        'landing.show_pricing'  => '1',
        'landing.show_apps'     => '1',
        'landing.show_faq'      => '1',
    ];

    public const TEXT_FIELDS = [
        'hero_badge' => 'شارة الواجهة', 'hero_title' => 'العنوان الرئيسي', 'hero_text' => 'النص الرئيسي', 'cta_primary' => 'زر التسجيل',
        'cta_secondary' => 'زر الدخول', 'trial_note' => 'ملاحظة التجربة', 'features_title' => 'عنوان المميزات', 'features_text' => 'وصف المميزات',
        'steps_title' => 'عنوان الخطوات', 'pricing_title' => 'عنوان الباقات', 'pricing_text' => 'وصف الباقات',
        'apps_title' => 'عنوان التطبيقات', 'apps_text' => 'وصف التطبيقات', 'faq_title' => 'عنوان الأسئلة',
        'cta_title' => 'عنوان الدعوة الأخيرة', 'cta_text' => 'نص الدعوة الأخيرة', 'footer_text' => 'نص التذييل',
    ];

    public const LIST_FIELDS = ['features' => 'المميزات', 'steps' => 'الخطوات', 'stats' => 'الأرقام', 'faq' => 'الأسئلة الشائعة'];
    /** تصميم الصفحة: الجديد (سماء الباستيل ولقطات حقيقية) أو الكلاسيكي الداكن؛ يُبدَّل بينهما من لوحة المسؤول */
    public const STYLES = ['pastel' => 'الجديد — سماء الباستيل', 'classic' => 'الكلاسيكي — الداكن'];

    public const SECTIONS = ['stats' => 'الأرقام', 'features' => 'المميزات', 'steps' => 'الخطوات', 'pricing' => 'الباقات والأسعار', 'apps' => 'التطبيقات', 'faq' => 'الأسئلة الشائعة'];

    /**
     * نص الصفحة بلغة الواجهة الحالية: في الإنجليزية النسخة الإنجليزية إن كُتبت،
     * وإلا النص العربي (والنصوص الافتراضية مترجمة في القاموس).
     */
    public static function get(string $key): string
    {
        if (str_starts_with($key, 'show_') || !\App\Core\Lang::isEn()) {
            return self::raw($key, 'ar');
        }
        $en = self::raw($key, 'en');
        return $en !== '' ? $en : self::raw($key, 'ar');
    }

    /** النص المحفوظ بلغة محددة (للتحرير): العربية بقيمتها أو الافتراضية، والإنجليزية فارغة إن لم تُكتب */
    public static function raw(string $key, string $locale): string
    {
        if ($locale === 'en') {
            return Platform::setting('landing_en.' . $key, '');
        }
        return Platform::setting('landing.' . $key, self::DEFAULTS['landing.' . $key] ?? '');
    }

    /** هل صفحة الهبوط مفعّلة؟ (عند الإيقاف يُفتح تسجيل الدخول مباشرة) */
    public static function enabled(): bool
    {
        return Platform::setting('landing.enabled', '1') !== '0';
    }

    /** التصميم المختار للصفحة (الجديد ما لم يُختر الكلاسيكي) */
    public static function style(): string
    {
        $style = Platform::setting('landing.style', 'pastel');
        return isset(self::STYLES[$style]) ? $style : 'pastel';
    }

    /** معرّف فيديو العرض على YouTube (يظهر زر «شاهد العرض» في الواجهة متى وُجد)، أو نص فارغ */
    public static function video(): string
    {
        $id = Platform::setting('landing.video', '');
        return preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : '';
    }

    /** معرّف الفيديو من رابط YouTube بأشكاله (watch وyoutu.be وshorts وembed) أو من المعرّف نفسه؛ null لغير ذلك */
    public static function videoId(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input)) {
            return $input;
        }
        $patterns = [
            '#^(?:https?://)?(?:www\.|m\.|music\.)?youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)([A-Za-z0-9_-]{11})(?:[?&\#/].*)?$#i',
            '#^(?:https?://)?youtu\.be/([A-Za-z0-9_-]{11})(?:[?&\#/].*)?$#i',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $input, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    /** عناصر قائمة بلغة محددة (للتحرير) */
    public static function rawItems(string $key, string $locale): array
    {
        return self::parse(self::raw($key, $locale));
    }

    public static function on(string $section): bool
    {
        return self::get('show_' . $section) === '1';
    }

    /** @return array<int, array{0:string,1:string}> */
    public static function items(string $key): array
    {
        return self::parse(self::get($key));
    }

    private static function parse(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line, 2));
            $out[] = [$parts[0], $parts[1] ?? ''];
        }
        return array_slice($out, 0, 12);
    }
}
