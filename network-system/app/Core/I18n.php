<?php
declare(strict_types=1);

namespace App\Core;

/**
 * ترجمة الواجهة إلى الإنجليزية من قاموس مكتوب يدوياً (app/Lang/en.php)، دون أي خدمة ترجمة خارجية.
 *
 * الشيفرة تكتب نصوصها بالعربية؛ وفي الوضع الإنجليزي تُمرَّر الصفحة (HTML) أو الاستجابة (JSON) على المترجم:
 *  1) كل مقطع عربي (حروف عربية مع ما بينها من مسافات وأرقام وعلامات) يُبحث عنه كاملاً في القاموس.
 *  2) إن لم يوجد: تُستبدل الأرقام فيه بمواضع {0} {1}… ويُبحث في قوالب القاموس (مثل «منذ {0} دقائق»).
 *  3) إن لم يوجد: يُترجم كل جزء عربي منه على حدة وتبقى الأرقام والعلامات كما هي.
 * ما لا يوجد في القاموس يبقى كما هو (بيانات المستخدم مثل الأسماء لا تُمس)، ويُسجَّل في وضع التطوير لإكماله.
 */
final class I18n
{
    /** مقطع عربي: يبدأ بحرف عربي، ويمتد عبر الحروف العربية والمسافات والأرقام والعلامات الشائعة، وينتهي بحرف عربي أو رقم أو علامة إغلاق */
    /** كلمات لاتينية شائعة داخل الجمل العربية (أسماء منتجات واختصارات) تبقى جزءاً من الجملة حتى تُترجم كاملة بترتيبها الصحيح */
    public const LATIN_WORDS = 'MikroTik|RouterOS|PPPoE|PoE|MAC|IP|API|SFP|PPP|WAN|LAN|SMS|AI|Excel|CSV|PDF|Telegram|WhatsApp|Google|Gemini|OpenAI|Claude|VPN|DHCP|LLDP|Windows|Android|Ethernet|QR|ID|FCS|CPU|RAM|SSL|HTTPS|SMTP|Gmail|MADIX|NEXUS|Madix|Nexus|Wi-Fi|PIN|OTP|URL|Hotspot|WireGuard|Fingerprint|Cron|cron';
    private const SEGMENT = '/[\x{0610}-\x{061A}\x{0620}-\x{065F}\x{066E}-\x{06DC}\x{06DF}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}](?:(?:[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}0-9\x{0660}-\x{0669} \x{00A0}\x{200C}\x{200F}\x{2066}-\x{2069}.,:;!?%+\-–—\/()«»×#*_&]|(?<![A-Za-z])(?:MikroTik|RouterOS|PPPoE|PoE|MAC|IP|API|SFP|PPP|WAN|LAN|SMS|AI|Excel|CSV|PDF|Telegram|WhatsApp|Google|Gemini|OpenAI|Claude|VPN|DHCP|LLDP|Windows|Android|Ethernet|QR|ID|FCS|CPU|RAM|SSL|HTTPS|SMTP|Gmail|MADIX|NEXUS|Madix|Nexus|Wi-Fi|PIN|OTP|URL|Hotspot|WireGuard|Fingerprint|Cron|cron)(?![A-Za-z]))*(?:[\x{0610}-\x{061A}\x{0620}-\x{065F}\x{066E}-\x{06DC}\x{06DF}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}0-9)»%.:!?\x{060C}\x{061F}]|(?<![A-Za-z])(?:MikroTik|RouterOS|PPPoE|PoE|MAC|IP|API|SFP|PPP|WAN|LAN|SMS|AI|Excel|CSV|PDF|Telegram|WhatsApp|Google|Gemini|OpenAI|Claude|VPN|DHCP|LLDP|Windows|Android|Ethernet|QR|ID|FCS|CPU|RAM|SSL|HTTPS|SMTP|Gmail|MADIX|NEXUS|Madix|Nexus|Wi-Fi|PIN|OTP|URL|Hotspot|WireGuard|Fingerprint|Cron|cron)(?![A-Za-z])))?/u';
    private const ARABIC = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u';
    private const RUN = '/[\x{0610}-\x{061A}\x{0620}-\x{065F}\x{066E}-\x{06DC}\x{06DF}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}](?:[\x{0610}-\x{061A}\x{0620}-\x{065F}\x{066E}-\x{06DC}\x{06DF}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF} \x{00A0}\x{200C}]*[\x{0610}-\x{061A}\x{0620}-\x{065F}\x{066E}-\x{06DC}\x{06DF}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}])?/u';

    private static ?array $dict = null;
    private static ?array $tpl = null;
    /** مدخلات قصيرة تجمع كلمة عربية وأخرى لاتينية، الأطول أولاً */
    private static array $mixed = [];
    private static array $missing = [];

    /** القاموس: نص عربي => إنجليزي، وقوالب بمواضع أرقام */
    private static function load(): void
    {
        if (self::$dict !== null) {
            return;
        }
        $file = BASE_PATH . '/app/Lang/en.php';
        $all = is_file($file) ? (array) require $file : [];
        self::$dict = [];
        self::$tpl = [];
        foreach ($all as $ar => $en) {
            $ar = self::norm((string) $ar);
            // الترجمة تُدرج داخل HTML وJSON ونصوص JavaScript: علامات الاقتباس المطبعية بدل ' و" حتى لا تكسر شيئاً
            $en = str_replace(['"', "'", '<', '>'], ['”', '’', '‹', '›'], (string) $en);
            if (str_contains($ar, '{0}')) {
                self::$tpl[$ar] = (string) $en;
            } else {
                self::$dict[$ar] = (string) $en;
            }
        }
        foreach (self::$dict as $ar => $en) {
            // عبارات تُبحث داخل الجمل المركّبة: ما فيه كلمة لاتينية، أو ثلاث كلمات فأكثر، أو أقواس
            if (preg_match('/[A-Za-z]/', $ar) && substr_count($ar, ' ') <= 3 || substr_count($ar, ' ') >= 2 || preg_match('/[()«»]/u', $ar)) {
                self::$mixed[$ar] = $en;
            }
        }
        uksort(self::$mixed, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        // صيغة بلا علامة الترقيم الأولى لكل مدخل يبدأ بها («، وطول الكابل» → «وطول الكابل»)
        foreach (self::$dict as $ar => $en) {
            if (preg_match('/^[،,.:؛]\s*(.+)$/u', $ar, $m) && mb_strlen($m[1]) > 1 && !isset(self::$dict[$m[1]])) {
                self::$dict[$m[1]] = ltrim($en, ',.:; ');
            }
        }
        // صيغة بلا علامة الترقيم الأخيرة لكل مدخل ينتهي بها (تظهر هكذا عند تجزئة النص حول الأرقام)
        foreach (self::$dict as $ar => $en) {
            if (preg_match('/^(.+?)\s*[.:،؛!؟?]$/u', $ar, $m) && mb_strlen($m[1]) > 1 && !isset(self::$dict[$m[1]])) {
                self::$dict[$m[1]] = rtrim($en, '.:,;!?');
            }
        }
    }

    /** توحيد المسافات وعلامات الاتجاه للمطابقة */
    private static function norm(string $s): string
    {
        $s = str_replace(["\u{00A0}", "\u{200F}", "\u{200E}", "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}"], [' ', '', '', '', '', '', ''], $s);
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** ترجمة نص واحد (رسالة، تسمية) */
    public static function text(string $s): string
    {
        if ($s === '' || !preg_match(self::ARABIC, $s)) {
            return $s;
        }
        self::load();
        $out = (string) preg_replace_callback(self::SEGMENT, static function ($m) use ($s) {
            [$seg, $at] = $m[0];
            // رموز القوالب مثل {الشبكة} تبقى كما هي (تُستبدل لاحقاً بقيمها)
            $after = substr($s, $at + strlen($seg), 2);
            if ($at > 0 && $s[$at - 1] === '{' && ($after[0] ?? '') === '}' || $at > 0 && $s[$at - 1] === '{' && $after === '\\}') {
                return $seg;
            }
            return self::segment($seg);
        }, $s, -1, $count, PREG_OFFSET_CAPTURE);
        // علامات الترقيم العربية المتبقية خارج المقاطع
        return strtr($out, ['،' => ',', '؛' => ';', '؟' => '?', '«' => '“', '»' => '”']);
    }

    /** ترجمة نص للوضع الحالي فقط (للاستخدام في الشيفرة عند الحاجة) */
    public static function t(string $s): string
    {
        return Lang::isEn() ? self::text($s) : $s;
    }

    private static function segment(string $seg): string
    {
        // المسافات حول المقطع تبقى كما هي حتى لا تلتصق الكلمات بما حولها
        preg_match('/^(\s*)(.*?)(\s*)$/su', $seg, $p);
        [$lead, $core, $trail] = [$p[1] ?? '', $p[2] ?? $seg, $p[3] ?? ''];
        $key = self::norm($core);
        if ($key === '') {
            return $seg;
        }
        $out = self::lookup($key);
        if ($out === null) {
            // بلا علامة الترقيم الأخيرة
            if (preg_match('/^(.*?)([.:،؛!?؟])$/u', $key, $m) && ($hit = self::lookup($m[1])) !== null) {
                $out = $hit . self::punct($m[2]);
            }
        }
        if ($out === null && preg_match('/\d/', $key)) {
            $nums = [];
            $tplKey = (string) preg_replace_callback('/\d+(?:[.,]\d+)*/', static function ($m) use (&$nums) {
                $nums[] = $m[0];
                return '{' . (count($nums) - 1) . '}';
            }, $key);
            $t = self::$tpl[$tplKey] ?? null;
            if ($t === null && preg_match('/^(.*?)([.:،؛!?؟])$/u', $tplKey, $m) && isset(self::$tpl[$m[1]])) {
                $t = self::$tpl[$m[1]] . self::punct($m[2]);
            }
            if ($t !== null) {
                $out = (string) preg_replace_callback('/\{(\d+)\}/', static fn ($m) => $nums[(int) $m[1]] ?? '', $t);
            }
        }
        if ($out === null) {
            // كل جزء عربي على حدة (بأطول عبارات معروفة)، والأرقام والعلامات بينها كما هي
            $core = (string) preg_replace('/(?<=^|[\s(])و(?=\d)/u', 'and ', $core);
            // العبارات المعروفة التي فيها كلمة لاتينية («أجهزة MikroTik») تُترجم أولاً كوحدة واحدة
            $held = [];
            foreach (self::$mixed as $ar => $en) {
                if (str_contains($core, $ar)) {
                    $core = str_replace($ar, "\u{E000}" . count($held) . "\u{E001}", $core);
                    $held[] = $en;
                }
            }
            $out = (string) preg_replace_callback(self::RUN, static fn ($m) => self::run($m[0]), $core);
            if ($held) {
                $out = (string) preg_replace_callback('/\x{E000}(\d+)\x{E001}/u', static fn ($m) => $held[(int) $m[1]] ?? '', $out);
            }
            $out = self::punct($out);
        }
        return $lead . $out . $trail;
    }

    /** ترجمة جزء عربي بأطول العبارات المعروفة من بدايته، كلمة بعد كلمة */
    private static function run(string $run): string
    {
        $k = self::norm($run);
        $hit = self::lookup($k);
        if ($hit !== null) {
            return $hit;
        }
        $words = explode(' ', $k);
        $n = count($words);
        $out = [];
        $i = 0;
        $missed = false;
        while ($i < $n) {
            $found = false;
            for ($j = min($n, $i + 14); $j > $i; $j--) {
                $phrase = implode(' ', array_slice($words, $i, $j - $i));
                $t = self::lookup($phrase);
                if ($t !== null) {
                    $out[] = $t;
                    $i = $j;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $out[] = $words[$i];
                $missed = true;
                $i++;
            }
        }
        if ($missed) {
            self::miss($k);
        }
        return implode(' ', $out);
    }

    private static function lookup(string $key): ?string
    {
        if (isset(self::$dict[$key])) {
            return self::$dict[$key];
        }
        // «الـ» التعريف الملتصقة بالواو: «والأجهزة» → «و» + «الأجهزة»
        if (str_starts_with($key, 'و') && mb_strlen($key) > 3 && isset(self::$dict[mb_substr($key, 1)])) {
            return 'and ' . self::$dict[mb_substr($key, 1)];
        }
        return null;
    }

    private static function punct(string $s): string
    {
        return strtr($s, ['،' => ',', '؛' => ';', '؟' => '?', '«' => '“', '»' => '”']);
    }

    private static function miss(string $k): void
    {
        if (mb_strlen($k) > 1) {
            self::$missing[$k] = true;
        }
    }

    /** ترجمة الاستجابة كاملة في الوضع الإنجليزي (HTML وJSON فقط؛ الملفات المصدّرة كما هي) */
    public static function response(Response $r): Response
    {
        if (!Lang::isEn() || $r->body === '' || !preg_match(self::ARABIC, $r->body)) {
            return $r;
        }
        $type = strtolower((string) ($r->headers['Content-Type'] ?? 'text/html'));
        if (!str_contains($type, 'text/html') && !str_contains($type, 'application/json') && !str_contains($type, 'text/plain')) {
            return $r;
        }
        self::load();
        $body = $r->body;
        // ما يُرسل إلى الخادم أو يُحرَّر لا يُترجم: قيم الحقول، ومحتوى مربعات النص، وبيانات JSON المضمّنة (عدا رسائل التنبيه)
        $held = [];
        if (str_contains($type, 'text/html')) {
            $body = (string) preg_replace_callback(
                '#(<textarea\b[^>]*>)(.*?)(</textarea>)|(<script\b[^>]*id="(?:designer-config|print-config)"[^>]*>)(.*?)(</script>)|\svalue="[^"]*"|\svalue=\'[^\']*\'#si',
                static function ($m) use (&$held) {
                    // وسم مربع النص نفسه (العنوان والتلميح) يُترجم، ومحتواه لا
                    if (($m[1] ?? '') !== '' || ($m[4] ?? '') !== '') {
                        $open = ($m[1] ?? '') !== '' ? $m[1] : $m[4];
                        $inner = ($m[1] ?? '') !== '' ? $m[2] : $m[5];
                        $close = ($m[1] ?? '') !== '' ? $m[3] : $m[6];
                        $held[] = $inner;
                        return $open . "\u{E002}" . (count($held) - 1) . "\u{E003}" . $close;
                    }
                    $held[] = $m[0];
                    return "\u{E002}" . (count($held) - 1) . "\u{E003}";
                },
                $body
            );
        }
        $body = self::text($body);
        if ($held) {
            $body = (string) preg_replace_callback('/\x{E002}(\d+)\x{E003}/u', static fn ($m) => $held[(int) $m[1]] ?? '', $body);
        }
        if (str_contains($type, 'application/json')) {
            // الترجمة لا تحوي علامات اقتباس مزدوجة؛ للاحتياط يُتحقق من صلاحية JSON وإلا تُعاد الأصلية
            if (json_decode($body) === null && json_last_error() !== JSON_ERROR_NONE) {
                return $r;
            }
        }
        $r->body = $body;
        if (isset($r->headers['Content-Length'])) {
            $r->headers['Content-Length'] = (string) strlen($body);
        }
        self::flushMissing();
        return $r;
    }

    /** في وضع التطوير: تسجيل النصوص غير المترجمة لإكمال القاموس */
    private static function flushMissing(): void
    {
        if (!self::$missing || !Config::get('app.debug')) {
            return;
        }
        $file = storage_path('logs/i18n-missing.txt');
        $known = is_file($file) ? array_flip(array_filter(explode("\n", (string) file_get_contents($file)))) : [];
        $new = array_diff_key(self::$missing, $known);
        if ($new) {
            @file_put_contents($file, implode("\n", array_keys($new)) . "\n", FILE_APPEND);
        }
        self::$missing = [];
    }

    /** للاختبارات وأداة التحقق: النصوص التي لم تُترجم في آخر استدعاء */
    public static function missing(): array
    {
        return array_keys(self::$missing);
    }
}
