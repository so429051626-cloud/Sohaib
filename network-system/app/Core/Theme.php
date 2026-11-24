<?php
declare(strict_types=1);

namespace App\Core;

/**
 * التصميم العام: التصاميم الجاهزة، نمط النظام، الخط، لوحة الألوان، الزوايا، الكثافة،
 * القائمة الجانبية (لونها وشكلها وشكل روابطها)، الشريط العلوي، الخلفية، الأزرار، حجم الخط، وحركة ظهور العناصر.
 * في وضع المنصة يُدار من لوحة المسؤول ويُطبّق على كل الأنظمة؛ في التثبيت المفرد من الإعدادات.
 */
final class Theme
{
    /** المفتاح => [الاسم، اسم العائلة، ملف الخط، الأوزان] */
    public const FONTS = [
        'plex'    => ['IBM Plex Arabic', 'Plex Arabic', 'ibm-plex-sans-arabic', [400, 500, 600, 700]],
        'cairo'   => ['Cairo', 'Cairo', 'cairo', [400, 600, 700]],
        'tajawal' => ['Tajawal', 'Tajawal', 'tajawal', [400, 500, 700]],
        'almarai' => ['Almarai', 'Almarai', 'almarai', [400, 700]],
        'kufi'    => ['Noto Kufi Arabic', 'Noto Kufi Arabic', 'noto-kufi-arabic', [400, 600, 700]],
        'readex'  => ['Readex Pro', 'Readex Pro', 'readex-pro', [400, 500, 700]],
    ];

    /** الخطوط العالمية للحروف اللاتينية والأرقام (والواجهة الإنجليزية): المفتاح => [الاسم، اسم العائلة، ملف الخط] */
    public const FONTS_LATIN = [
        'auto'       => ['تلقائي (حروف الخط العربي)', '', ''],
        'inter'      => ['Inter', 'Inter', 'inter'],
        'poppins'    => ['Poppins', 'Poppins', 'poppins'],
        'manrope'    => ['Manrope', 'Manrope', 'manrope'],
        'jakarta'    => ['Plus Jakarta Sans', 'Plus Jakarta Sans', 'plus-jakarta-sans'],
        'dmsans'     => ['DM Sans', 'DM Sans', 'dm-sans'],
        'montserrat' => ['Montserrat', 'Montserrat', 'montserrat'],
        'rubik'      => ['Rubik', 'Rubik', 'rubik'],
        'outfit'     => ['Outfit', 'Outfit', 'outfit'],
    ];

    /** المفتاح => [الاسم، اللون الفاتح، اللون في الوضع الداكن، اللون المرافق فاتحاً، اللون المرافق داكناً] */
    public const PALETTES = [
        'royal'    => ['أزرق ملكي', '#2453d6', '#5383ff', '#7c3aed', '#a78bfa'],
        'ocean'    => ['محيطي', '#0e7490', '#22c3e6', '#2563eb', '#60a5fa'],
        'emerald'  => ['زمردي', '#047857', '#34d399', '#0d9488', '#5eead4'],
        'violet'   => ['بنفسجي', '#6d28d9', '#a78bfa', '#db2777', '#f472b6'],
        'indigo'   => ['نيلي', '#4338ca', '#818cf8', '#0891b2', '#67e8f9'],
        'sunset'   => ['برتقالي', '#c2410c', '#fb923c', '#be123c', '#fb7185'],
        'rose'     => ['وردي', '#be123c', '#fb7185', '#9333ea', '#c084fc'],
        'graphite' => ['جرافيتي', '#334155', '#a5b4c8', '#0f172a', '#e2e8f0'],
        'gold'     => ['ذهبي', '#a16207', '#eab308', '#c2410c', '#fb923c'],
        'sky'      => ['سماوي', '#0369a1', '#38bdf8', '#4f46e5', '#818cf8'],
        'teal'     => ['فيروزي', '#0f766e', '#2dd4bf', '#0369a1', '#38bdf8'],
        'fuchsia'  => ['فوشيا', '#a21caf', '#e879f9', '#7c3aed', '#a78bfa'],
    ];

    /** نمط النظام: شكل البطاقات والأسطح والظلال */
    public const SKINS = [
        'classic'  => 'كلاسيكي',
        'soft'     => 'ناعم',
        'glass'    => 'زجاجي',
        'elevated' => 'بارز بالظلال',
        'flat'     => 'مسطّح',
        'outline'  => 'خطوط رفيعة',
    ];

    public const RADIUS = ['sharp' => 'حادّة', 'normal' => 'متوسطة', 'round' => 'دائرية'];
    public const DENSITY = ['compact' => 'مضغوطة', 'normal' => 'متوازنة', 'comfortable' => 'مريحة'];
    public const SIDEBAR = [
        'light'    => 'فاتحة',
        'dark'     => 'داكنة',
        'brand'    => 'بلون الهوية',
        'gradient' => 'متدرّجة',
        'glass'    => 'زجاجية',
        'minimal'  => 'مدمجة بالخلفية',
    ];
    public const SIDEBAR_SHAPE = ['attached' => 'ملتصقة بالحافة', 'floating' => 'عائمة بزوايا'];
    public const NAV_STYLE = ['tiles' => 'بلاطات أيقونات', 'pill' => 'كبسولة ممتلئة', 'line' => 'خط جانبي'];
    public const TOPBAR = ['glass' => 'زجاجي', 'solid' => 'صلب', 'brand' => 'بلون الهوية', 'blend' => 'مدمج بالخلفية'];
    public const BACKGROUND = ['plain' => 'سادة', 'tinted' => 'ملوّنة بلطف', 'mesh' => 'تدرّج ضوئي', 'dots' => 'نقاط', 'grid' => 'شبكة'];
    public const BUTTONS = ['normal' => 'عادية', 'pill' => 'كبسولية', 'gradient' => 'متدرّجة'];
    public const FONT_SIZE = ['small' => 'صغير', 'normal' => 'متوسط', 'large' => 'كبير'];
    public const MOTION = [
        'rise'  => 'صعود من الأسفل',
        'drop'  => 'سقوط من الأعلى',
        'fade'  => 'تلاشي',
        'zoom'  => 'تكبير ناعم',
        'slide' => 'انزلاق جانبي',
        'none'  => 'بدون حركة',
    ];

    /**
     * التصاميم الجاهزة: كل تصميم يضبط كل تفاصيل الشكل دفعة واحدة.
     * المفتاح => [الاسم، الوصف، القيم]
     */
    public const PRESETS = [
        'nexus' => ['نيكسس', 'الأصلي: أبيض نقي وأزرق ملكي وبلاطات أيقونات أنيقة', [
            'skin' => 'classic', 'palette' => 'royal', 'font' => 'plex', 'radius' => 'normal', 'density' => 'normal',
            'sidebar' => 'light', 'sidebar_shape' => 'attached', 'nav_style' => 'tiles', 'topbar' => 'glass',
            'background' => 'plain', 'buttons' => 'normal', 'font_size' => 'normal', 'motion' => 'rise',
        ]],
        'aurora' => ['الشفق الزجاجي', 'زجاج شفاف فوق تدرّج ضوئي بنفسجي وردي، وقائمة عائمة', [
            'skin' => 'glass', 'palette' => 'violet', 'font' => 'readex', 'radius' => 'round', 'density' => 'normal',
            'sidebar' => 'glass', 'sidebar_shape' => 'floating', 'nav_style' => 'pill', 'topbar' => 'blend',
            'background' => 'mesh', 'buttons' => 'gradient', 'font_size' => 'normal', 'motion' => 'zoom',
        ]],
        'midnight' => ['منتصف الليل', 'قائمة داكنة فاخرة وبطاقات بارزة بلون نيلي هادئ', [
            'skin' => 'elevated', 'palette' => 'indigo', 'font' => 'plex', 'radius' => 'normal', 'density' => 'normal',
            'sidebar' => 'dark', 'sidebar_shape' => 'attached', 'nav_style' => 'pill', 'topbar' => 'solid',
            'background' => 'plain', 'buttons' => 'normal', 'font_size' => 'normal', 'motion' => 'rise',
        ]],
        'oasis' => ['الواحة', 'زمردي منعش بقائمة بلون الهوية وخلفية ملوّنة بلطف', [
            'skin' => 'soft', 'palette' => 'emerald', 'font' => 'tajawal', 'radius' => 'round', 'density' => 'normal',
            'sidebar' => 'brand', 'sidebar_shape' => 'attached', 'nav_style' => 'tiles', 'topbar' => 'glass',
            'background' => 'tinted', 'buttons' => 'pill', 'font_size' => 'normal', 'motion' => 'fade',
        ]],
        'sahara' => ['رمال الذهب', 'دفء ذهبي وقائمة متدرّجة وخلفية منقّطة ناعمة', [
            'skin' => 'soft', 'palette' => 'gold', 'font' => 'almarai', 'radius' => 'round', 'density' => 'comfortable',
            'sidebar' => 'gradient', 'sidebar_shape' => 'floating', 'nav_style' => 'tiles', 'topbar' => 'blend',
            'background' => 'dots', 'buttons' => 'gradient', 'font_size' => 'normal', 'motion' => 'rise',
        ]],
        'ocean' => ['المحيط', 'أزرق مائي عميق بشريط علوي بلون الهوية وبطاقات بارزة', [
            'skin' => 'elevated', 'palette' => 'ocean', 'font' => 'cairo', 'radius' => 'normal', 'density' => 'normal',
            'sidebar' => 'light', 'sidebar_shape' => 'attached', 'nav_style' => 'line', 'topbar' => 'brand',
            'background' => 'tinted', 'buttons' => 'normal', 'font_size' => 'normal', 'motion' => 'slide',
        ]],
        'rose' => ['زهر الربيع', 'وردي ناعم بلمسات بنفسجية وزوايا دائرية وأزرار كبسولية', [
            'skin' => 'soft', 'palette' => 'rose', 'font' => 'readex', 'radius' => 'round', 'density' => 'normal',
            'sidebar' => 'light', 'sidebar_shape' => 'floating', 'nav_style' => 'pill', 'topbar' => 'blend',
            'background' => 'mesh', 'buttons' => 'pill', 'font_size' => 'normal', 'motion' => 'zoom',
        ]],
        'graphite' => ['جرافيت', 'بساطة احترافية: خطوط رفيعة وزوايا حادة وكثافة عالية للبيانات', [
            'skin' => 'outline', 'palette' => 'graphite', 'font' => 'plex', 'radius' => 'sharp', 'density' => 'compact',
            'sidebar' => 'minimal', 'sidebar_shape' => 'attached', 'nav_style' => 'line', 'topbar' => 'blend',
            'background' => 'grid', 'buttons' => 'normal', 'font_size' => 'small', 'motion' => 'fade',
        ]],
        'sunset' => ['الغروب', 'تدرّج برتقالي قرمزي دافئ فوق زجاج شفاف وقائمة متدرّجة', [
            'skin' => 'glass', 'palette' => 'sunset', 'font' => 'cairo', 'radius' => 'round', 'density' => 'normal',
            'sidebar' => 'gradient', 'sidebar_shape' => 'attached', 'nav_style' => 'pill', 'topbar' => 'glass',
            'background' => 'mesh', 'buttons' => 'gradient', 'font_size' => 'normal', 'motion' => 'rise',
        ]],
        'frost' => ['الصقيع', 'سماوي بارد ونظيف: أسطح مسطّحة وقائمة عائمة وخط كوفي حديث', [
            'skin' => 'flat', 'palette' => 'sky', 'font' => 'kufi', 'radius' => 'normal', 'density' => 'normal',
            'sidebar' => 'minimal', 'sidebar_shape' => 'floating', 'nav_style' => 'line', 'topbar' => 'solid',
            'background' => 'plain', 'buttons' => 'pill', 'font_size' => 'normal', 'motion' => 'drop',
        ]],
    ];

    /** الخيارات القابلة للتعيين وقوائمها المسموحة */
    public const CHOICES = [
        'skin' => self::SKINS, 'palette' => self::PALETTES, 'font' => self::FONTS, 'font_latin' => self::FONTS_LATIN, 'radius' => self::RADIUS, 'density' => self::DENSITY,
        'sidebar' => self::SIDEBAR, 'sidebar_shape' => self::SIDEBAR_SHAPE, 'nav_style' => self::NAV_STYLE, 'topbar' => self::TOPBAR,
        'background' => self::BACKGROUND, 'buttons' => self::BUTTONS, 'font_size' => self::FONT_SIZE, 'motion' => self::MOTION,
    ];

    public const DEFAULTS = [
        'design.preset'        => 'nexus',
        'design.skin'          => 'classic',
        'design.font'          => 'plex',
        'design.font_latin'    => 'auto',
        'design.palette'       => 'royal',
        'design.radius'        => 'normal',
        'design.density'       => 'normal',
        'design.sidebar'       => 'light',
        'design.sidebar_shape' => 'attached',
        'design.nav_style'     => 'tiles',
        'design.topbar'        => 'glass',
        'design.background'    => 'plain',
        'design.buttons'       => 'normal',
        'design.font_size'     => 'normal',
        'design.motion'        => 'rise',
        'design.stagger'       => '20',
        'design.speed'         => '200',
        'design.page_fx'       => '0',
        'design.haptics'       => '1',
    ];

    /** مراجعة إعدادات الحركة: تُحفظ مع صفحة التصاميم (2 = بعد تخفيف التنقل بين الصفحات) */
    public const REV = 2;

    private static ?array $cache = null;

    public static function current(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $out = [];
        foreach (self::DEFAULTS as $k => $def) {
            try {
                $v = Platform::enabled() ? Platform::setting($k, $def) : (Config::installed() ? (string) Settings::get($k, $def) : $def);
            } catch (\Throwable) {
                $v = $def;
            }
            $out[substr($k, 7)] = $v;
        }
        // تصميم حُفظ قبل تخفيف التنقل: بلا تأثير انتقال بين الصفحات (يؤخر ظهور كل صفحة) وبحركة خفيفة،
        // حتى يحفظ المسؤول صفحة التصاميم من جديد فيُعتمد اختياره
        try {
            $rev = (int) (Platform::enabled() ? Platform::setting('design.rev', '0') : (Config::installed() ? Settings::get('design.rev', '0') : self::REV));
        } catch (\Throwable) {
            $rev = self::REV;
        }
        if ($rev < self::REV) {
            $out['page_fx'] = '0';
            $out['stagger'] = '20';
            $out['speed'] = '200';
        }
        return self::$cache = self::sanitize($out);
    }

    /** تنقية مجموعة قيم (من الإعدادات أو من نموذج أو من رابط المعاينة) */
    public static function sanitize(array $in): array
    {
        $out = [];
        foreach (self::DEFAULTS as $k => $def) {
            $key = substr($k, 7);
            $v = isset($in[$key]) && is_scalar($in[$key]) ? (string) $in[$key] : $def;
            if (isset(self::CHOICES[$key])) {
                $v = isset(self::CHOICES[$key][$v]) ? $v : $def;
            }
            $out[$key] = $v;
        }
        // التصميم الجاهز يُستنتج من القيم نفسها: أي تعديل على تفاصيله يجعله «مخصصاً»
        $out['preset'] = self::matchPreset($out);
        // حركة خفيفة دائماً: التنقل بين الصفحات لا ينتظر حركات طويلة (حد أعلى 30 و260 ملي ثانية)
        $out['stagger'] = (string) max(0, min(30, (int) $out['stagger']));
        $out['speed'] = (string) max(120, min(260, (int) $out['speed']));
        $out['page_fx'] = $out['page_fx'] === '1' ? '1' : '0';
        $out['haptics'] = $out['haptics'] === '1' ? '1' : '0';
        return $out;
    }

    /** قيم تصميم جاهز كاملة */
    public static function preset(string $key): ?array
    {
        if (!isset(self::PRESETS[$key])) {
            return null;
        }
        return ['preset' => $key] + self::PRESETS[$key][2];
    }

    /** هل تطابق القيم تصميماً جاهزاً؟ يعيد مفتاحه أو 'custom' */
    public static function matchPreset(array $t): string
    {
        foreach (self::PRESETS as $k => [, , $vals]) {
            $same = true;
            foreach ($vals as $f => $v) {
                if (($t[$f] ?? null) !== $v) {
                    $same = false;
                    break;
                }
            }
            if ($same) {
                return $k;
            }
        }
        return 'custom';
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** خصائص وسم html */
    public static function attrs(?array $t = null): string
    {
        $t ??= self::current();
        return ' data-motion="' . e($t['motion']) . '" data-density="' . e($t['density']) . '" data-radius="' . e($t['radius']) . '" data-sidebar-style="' . e($t['sidebar']) . '"'
            . ' data-skin="' . e($t['skin']) . '" data-sidebar-shape="' . e($t['sidebar_shape']) . '" data-nav-style="' . e($t['nav_style']) . '"'
            . ' data-topbar="' . e($t['topbar']) . '" data-bg="' . e($t['background']) . '" data-buttons="' . e($t['buttons']) . '" data-font-size="' . e($t['font_size']) . '"'
            . ' data-page-fx="' . ($t['page_fx'] === '1' ? '1' : '0') . '" data-haptics="' . ($t['haptics'] === '1' ? '1' : '0') . '"';
    }

    /** تعريفات @font-face لخط معيّن */
    public static function fontFaces(string $key, ?array $only = null): string
    {
        if ($key === 'plex' || !isset(self::FONTS[$key])) {
            return '';
        }
        [, $family, $file, $weights] = self::FONTS[$key];
        $css = '';
        foreach ($only !== null ? array_intersect($weights, $only) : $weights as $w) {
            foreach (['arabic' => 'U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0898-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC',
                'latin' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD'] as $sub => $range) {
                $css .= '@font-face{font-family:"' . $family . '";font-weight:' . $w . ';font-style:normal;font-display:swap;src:url("' . asset('fonts/' . $file . '-' . $sub . '-' . $w . '-normal.woff2') . '") format("woff2");unicode-range:' . $range . '}';
            }
        }
        return $css;
    }

    /** تعريفات @font-face لخط عالمي (الحروف اللاتينية فقط، فالعربية تبقى من الخط العربي) */
    public static function latinFaces(string $key, ?array $only = null): string
    {
        if (!isset(self::FONTS_LATIN[$key]) || $key === 'auto') {
            return '';
        }
        [, $family, $file] = self::FONTS_LATIN[$key];
        $range = 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+20AA, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD';
        $css = '';
        foreach ($only ?? [400, 500, 600, 700] as $w) {
            $css .= '@font-face{font-family:"' . $family . '";font-weight:' . $w . ';font-style:normal;font-display:swap;src:url("' . asset('fonts/' . $file . '-latin-' . $w . '-normal.woff2') . '") format("woff2");unicode-range:' . $range . '}';
        }
        return $css;
    }

    /** وسم style بالمتغيرات والخط المختار */
    public static function style(?array $t = null): string
    {
        $t ??= self::current();
        $family = self::FONTS[$t['font']][1];
        $css = self::fontFaces($t['font']);
        if ($t['font_latin'] !== 'auto') {
            // الخط العالمي أولاً للحروف اللاتينية والأرقام، ثم الخط العربي لما سواها
            $css .= self::latinFaces($t['font_latin']);
            $family = self::FONTS_LATIN[$t['font_latin']][1] . '", "' . $family;
        }
        [, $light, $dark, $light2, $dark2] = self::PALETTES[$t['palette']];
        $radius = ['sharp' => ['6px', '8px', '10px'], 'normal' => ['10px', '12px', '16px'], 'round' => ['14px', '18px', '22px']][$t['radius']];
        $css .= ':root{--font:"' . $family . '", system-ui, -apple-system, "Segoe UI", Tahoma, Arial, sans-serif;'
            . '--motion-stagger:' . (int) $t['stagger'] . 'ms;--motion-speed:' . (int) $t['speed'] . 'ms;'
            . '--radius-sm:' . $radius[0] . ';--radius:' . $radius[1] . ';--radius-lg:' . $radius[2] . ';}';
        // --brand و--brand-2: لونا الهوية العميقان للأسطح الملوّنة (القائمة والشريط العلوي) في الوضعين، فيبقى النص الأبيض فوقهما واضحاً
        $brand = '--brand:' . $light . ';--brand-2:' . $light2 . ';';
        $css .= ':root[data-theme="light"],:root:not([data-theme]){' . ($t['palette'] !== 'royal' ? self::accent($light, false) : '') . '--accent-2:' . $light2 . ';' . $brand . '}'
            . ':root[data-theme="dark"]{' . ($t['palette'] !== 'royal' ? self::accent($dark, true) : '') . '--accent-2:' . $dark2 . ';' . $brand . '}';
        // الانتقال بين الصفحات: المتصفحات الحديثة تمزج الصفحة القديمة بالجديدة بنعومة بدل الوميض (يتبع خيار «حركة الصفحات»)
        if ($t['page_fx'] === '1') {
            $css .= '@view-transition{navigation:auto}';
        }
        return '<style id="theme-vars">' . $css . '</style>';
    }

    private static function accent(string $hex, bool $dark): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        $hover = $dark ? self::mix([$r, $g, $b], [255, 255, 255], .14) : self::mix([$r, $g, $b], [0, 0, 0], .14);
        // لون النص فوق اللون الرئيسي: أبيض ما دام واضحاً (تباين 3:1 فأكثر)، وإلا فداكن
        $on = self::whiteContrast([$r, $g, $b]) >= 3.0 ? '#ffffff' : '#0b1220';
        return '--primary:' . $hex . ';--primary-hover:' . $hover . ';--on-primary:' . $on . ';'
            . '--primary-soft:' . ($dark ? "rgba($r, $g, $b, .16)" : self::mix([$r, $g, $b], [255, 255, 255], .9)) . ';'
            . "--primary-ring:rgba($r, $g, $b, " . ($dark ? '.34' : '.22') . ');';
    }

    /** تباين النص الأبيض فوق لون (WCAG) */
    private static function whiteContrast(array $rgb): float
    {
        $lin = static function (int $c): float {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $lin($rgb[0]) + 0.7152 * $lin($rgb[1]) + 0.0722 * $lin($rgb[2]);
        return 1.05 / ($l + 0.05);
    }

    private static function mix(array $a, array $b, float $t): string
    {
        return sprintf('#%02x%02x%02x', (int) round($a[0] + ($b[0] - $a[0]) * $t), (int) round($a[1] + ($b[1] - $a[1]) * $t), (int) round($a[2] + ($b[2] - $a[2]) * $t));
    }
}
