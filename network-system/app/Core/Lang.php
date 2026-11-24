<?php
declare(strict_types=1);

namespace App\Core;

/**
 * لغة الواجهة: العربية (الأصل) أو الإنجليزية.
 * اللغة الأساسية يحددها مسؤول المنصة (أو صاحب التثبيت المفرد)، وكل مستخدم يبدّل بين اللغتين من متصفحه.
 * النصوص العربية في الشيفرة هي المرجع، وتُترجم إلى الإنجليزية من قاموس مكتوب يدوياً (App\Core\I18n) دون أي خدمة ترجمة خارجية.
 */
final class Lang
{
    public const LOCALES = ['ar' => ['العربية', 'rtl'], 'en' => ['English', 'ltr']];
    public const COOKIE = 'mx_lang';

    private static ?string $current = null;

    /** اللغة الأساسية للأنظمة */
    public static function default(): string
    {
        try {
            $v = Platform::enabled() ? Platform::setting('platform.locale', 'ar') : (Config::installed() ? (string) Settings::get('general.locale', 'ar') : 'ar');
        } catch (\Throwable) {
            $v = 'ar';
        }
        return isset(self::LOCALES[$v]) ? $v : 'ar';
    }

    /** هل يُسمح للمستخدم بالتبديل بين اللغتين؟ */
    public static function switchable(): bool
    {
        try {
            $v = Platform::enabled() ? Platform::setting('platform.locale_switch', '1') : (Config::installed() ? (string) Settings::get('general.locale_switch', '1') : '1');
        } catch (\Throwable) {
            $v = '1';
        }
        return $v !== '0';
    }

    public static function current(): string
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $c = (string) ($_COOKIE[self::COOKIE] ?? '');
        return self::$current = (self::switchable() && isset(self::LOCALES[$c])) ? $c : self::default();
    }

    /** لاختبارات سطر الأوامر ولتبديل اللغة داخل الطلب نفسه */
    public static function set(?string $locale): void
    {
        self::$current = $locale !== null && isset(self::LOCALES[$locale]) ? $locale : null;
    }

    public static function isEn(): bool
    {
        return self::current() === 'en';
    }

    public static function dir(): string
    {
        return self::LOCALES[self::current()][1];
    }

    /** lang و dir لوسم html */
    public static function htmlAttrs(): string
    {
        return 'lang="' . self::current() . '" dir="' . self::dir() . '"';
    }

    /** اختيار نص حسب اللغة دون المرور بالقاموس */
    public static function pick(string $ar, string $en): string
    {
        return self::isEn() ? $en : $ar;
    }

    /** رابط التبديل إلى لغة مع العودة إلى الصفحة الحالية */
    public static function switchUrl(string $locale): string
    {
        $req = Request::current();
        $back = $req ? (string) ($_SERVER['REQUEST_URI'] ?? '/') : '/';
        return root_path() . '/lang/' . $locale . '?back=' . rawurlencode($back);
    }

    /** حفظ اختيار المستخدم في متصفحه لسنة */
    public static function remember(string $locale, bool $secure): void
    {
        if (!isset(self::LOCALES[$locale]) || headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $locale, [
            'expires' => time() + 365 * 86400, 'path' => '/', 'secure' => $secure, 'httponly' => false, 'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $locale;
        self::$current = null;
    }
}
