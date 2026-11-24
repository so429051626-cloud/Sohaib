<?php
declare(strict_types=1);

namespace App\Platform;

use App\Core\Brand;
use App\Core\DB;
use App\Core\Platform;
use App\Services\TweetSms;

/**
 * رسائل المنصة النصية إلى أصحاب الحسابات ببوابة المسؤول:
 * تذكير الاشتراك، رمز تأكيد رقم الهاتف عند التسجيل، رسالة الترحيب، ورمز استعادة كلمة المرور.
 * الرسائل التلقائية الثلاث تُشغَّل وتُوقف من لوحة المسؤول ← الرسائل النصية.
 */
final class PlatformSms
{
    /** الرسائل التلقائية: المفتاح => [مفتاح الإعداد، التسمية] */
    public const FEATURES = [
        'verify'  => ['signup.verify_phone', 'تأكيد رقم الهاتف برمز تحقق عند إنشاء الحساب'],
        'welcome' => ['sms.welcome', 'رسالة ترحيب بعد إنشاء الحساب'],
        'reset'   => ['sms.password_reset', 'استعادة كلمة المرور برمز تحقق'],
    ];

    /** مدة صلاحية رمز التحقق بالدقائق */
    public const CODE_MINUTES = 10;

    public static function key(): string
    {
        return Platform::unseal(Platform::setting('sms.api_key', ''));
    }

    public static function sender(): string
    {
        return trim(Platform::setting('sms.sender', ''));
    }

    public static function configured(): bool
    {
        return self::key() !== '' && self::sender() !== '';
    }

    /** هل الرسالة التلقائية مفعّلة والبوابة مضبوطة */
    public static function enabled(string $feature): bool
    {
        $key = self::FEATURES[$feature][0] ?? null;
        return $key !== null && Platform::setting($key) === '1' && self::configured();
    }

    /** اسم المنصة كما يظهر في الرسائل */
    public static function name(): string
    {
        $name = trim(Platform::setting('platform.name'));
        return $name !== '' ? $name : Brand::NAME;
    }

    /** @return array{ok:bool, message:string} */
    public static function send(string $phone, string $text): array
    {
        $n = TweetSms::normalize($phone);
        if ($n === null) {
            return ['ok' => false, 'message' => 'رقم الجوال غير صالح.'];
        }
        if (!self::configured()) {
            return ['ok' => false, 'message' => 'الرسائل النصية غير مضبوطة.'];
        }
        $r = TweetSms::sendOnce(self::key(), self::sender(), $text, [$n]);
        return ['ok' => (bool) $r['ok'], 'message' => $r['ok'] ? 'تم الإرسال.' : TweetSms::sendMessage($r)];
    }

    // ------------------------------------------------------------------
    // نصوص الرسائل: يكتبها المسؤول بمتغيرات، والفارغ يعني النص الافتراضي
    // ------------------------------------------------------------------

    /** النصوص الافتراضية لكل رسالة */
    public const TEMPLATES = [
        'verify'  => "مرحباً بك في {المنصة}.\nرمز التحقق الخاص بك: {الرمز}\nالرمز صالح لمدة {الدقائق} دقائق، ونرجو عدم مشاركته مع أي شخص.",
        'welcome' => "أهلاً وسهلاً بك {الاسم} في {المنصة}.\nتم إنشاء حساب شبكتك «{الشبكة}» بنجاح.\nاسم المستخدم: {اسم_المستخدم}\nرابط الدخول: {الرابط}\nنرجو الاحتفاظ باسم المستخدم وكلمة المرور وعدم مشاركتهما مع أحد. نتمنى لك تجربة موفقة.",
        'reset'   => "{المنصة}: رمز استعادة كلمة المرور لحسابك هو {الرمز}\nالرمز صالح لمدة {الدقائق} دقائق. إن لم تطلب ذلك فتجاهل هذه الرسالة، ولا تشارك الرمز مع أحد.",
    ];

    /** المتغيرات المتاحة لكل رسالة: المتغير => وصفه */
    public const PLACEHOLDERS = [
        'verify'  => ['{الرمز}' => 'رمز التحقق', '{الدقائق}' => 'مدة الصلاحية', '{المنصة}' => 'اسم المنصة'],
        'welcome' => ['{الاسم}' => 'اسم المالك', '{الشبكة}' => 'اسم الشبكة', '{اسم_المستخدم}' => 'اسم المستخدم', '{الرابط}' => 'رابط الدخول', '{المنصة}' => 'اسم المنصة'],
        'reset'   => ['{الرمز}' => 'رمز التحقق', '{الدقائق}' => 'مدة الصلاحية', '{المنصة}' => 'اسم المنصة'],
    ];

    /** أقصى طول لنص الرسالة */
    public const TEMPLATE_MAX = 600;

    /** النص الذي كتبه المسؤول لرسالة، أو النص الافتراضي */
    public static function template(string $feature): string
    {
        $t = trim(Platform::setting('sms.tpl_' . $feature, ''));
        return $t !== '' ? $t : (self::TEMPLATES[$feature] ?? '');
    }

    /** خطأ في نص يكتبه المسؤول، أو null إن كان صالحاً */
    public static function templateError(string $feature, string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > self::TEMPLATE_MAX) {
            return 'النص أطول من ' . self::TEMPLATE_MAX . ' حرف.';
        }
        if (in_array($feature, ['verify', 'reset'], true) && !str_contains($text, '{الرمز}')) {
            return 'النص يجب أن يحتوي {الرمز}.';
        }
        return null;
    }

    /** تعبئة المتغيرات في نص الرسالة */
    public static function render(string $feature, array $vars): string
    {
        $vars += ['{المنصة}' => self::name(), '{الدقائق}' => (string) self::CODE_MINUTES, '{الرابط}' => Platform::rootUrl() . '/login'];
        return trim(strtr(self::template($feature), $vars));
    }

    public static function verifyText(string $code): string
    {
        return self::render('verify', ['{الرمز}' => $code]);
    }

    public static function resetText(string $code): string
    {
        return self::render('reset', ['{الرمز}' => $code]);
    }

    public static function welcomeText(string $ownerName, string $network, string $username): string
    {
        return self::render('welcome', ['{الاسم}' => trim($ownerName), '{الشبكة}' => trim($network), '{اسم_المستخدم}' => $username]);
    }

    // ------------------------------------------------------------------
    // رموز التحقق (للتسجيل ولاستعادة كلمة المرور، كلٌّ برموزه)
    // ------------------------------------------------------------------

    /**
     * إنشاء رمز تحقق من 6 أرقام وإرساله إلى الرقم.
     * @param string $purpose signup | reset
     * @return array{ok:bool, message:string}
     */
    public static function sendCode(string $phone, string $purpose, ?string $ip = null): array
    {
        $n = TweetSms::normalize($phone);
        if ($n === null) {
            return ['ok' => false, 'message' => 'رقم الهاتف غير صالح.'];
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::insert('phone_codes', [
            'phone' => $n, 'purpose' => $purpose, 'code_hash' => self::codeHash($n, $purpose, $code), 'attempts' => 0,
            'expires_at' => date('Y-m-d H:i:s', time() + self::CODE_MINUTES * 60), 'ip' => $ip, 'created_at' => now(),
        ]);
        $r = self::send($n, $purpose === 'reset' ? self::resetText($code) : self::verifyText($code));
        if (!$r['ok']) {
            log_message('error', 'platform sms (' . $purpose . ' code): ' . $r['message']);
        }
        return $r;
    }

    private static function codeHash(string $phone, string $purpose, string $code): string
    {
        // رموز التسجيل تبقى بصيغتها السابقة، وكل غرض آخر يدخل اسمه في البصمة فلا يصلح رمزه لغيره
        return hash('sha256', ($purpose === 'signup' ? '' : $purpose . ':') . $phone . ':' . $code);
    }

    /** التحقق من الرمز: آخر رمز غير منتهٍ لهذا الغرض، وخمس محاولات كحد أقصى، ويُستعمل مرة واحدة */
    public static function codeValid(string $phone, string $code, string $purpose): bool
    {
        $n = TweetSms::normalize($phone);
        $code = (string) preg_replace('/\D/', '', latin_digits($code));
        if ($n === null || strlen($code) !== 6) {
            return false;
        }
        $row = DB::row(
            'SELECT * FROM phone_codes WHERE phone = ? AND purpose = ? AND verified_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1',
            [$n, $purpose, now()]
        );
        if (!$row || (int) $row['attempts'] >= 5) {
            return false;
        }
        if (!hash_equals((string) $row['code_hash'], self::codeHash($n, $purpose, $code))) {
            DB::query('UPDATE phone_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            return false;
        }
        DB::update('phone_codes', ['verified_at' => now()], 'id = ?', [$row['id']]);
        return true;
    }

    /** إخفاء وسط الرقم عند عرضه: 0599•••456 */
    public static function mask(string $phone): string
    {
        $n = TweetSms::normalize($phone);
        if ($n === null) {
            return $phone;
        }
        $local = '0' . substr($n, 3);
        return substr($local, 0, 4) . '•••' . substr($local, -3);
    }

    /** رسالة الترحيب بعد إنشاء الحساب (لا تُوقف إنشاء الحساب إن تعذّر إرسالها) */
    public static function welcome(array $tenant, string $username): bool
    {
        if (!self::enabled('welcome') || TweetSms::normalize((string) ($tenant['phone'] ?? '')) === null) {
            return false;
        }
        try {
            $r = self::send((string) $tenant['phone'], self::welcomeText((string) $tenant['owner_name'], (string) $tenant['name'], $username));
        } catch (\Throwable $e) {
            $r = ['ok' => false, 'message' => $e->getMessage()];
        }
        if (!$r['ok']) {
            log_message('error', 'platform sms (welcome ' . ($tenant['slug'] ?? '') . '): ' . $r['message']);
        }
        return $r['ok'];
    }
}
