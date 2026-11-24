<?php
declare(strict_types=1);

namespace App\Core;

/**
 * هوية النظام (MADIX NEXUS) وهوية الشبكة (شعار الشبكة واسمها).
 */
final class Brand
{
    public const NAME = 'MADIX NEXUS';
    public const TAGLINE = 'نظام إدارة الشبكات';
    public const COLOR = '#1E3A8A';
    public const ACCENT = '#06B6D4';

    /** مسار شعار الشبكة المرفوع أو فارغ */
    /** صفحات المنصة نفسها (خارج أي حساب زبون) */
    public static function onPlatform(): bool
    {
        return Platform::enabled() && Tenant::current() === null;
    }

    public static function networkLogo(): string
    {
        if (self::onPlatform()) {
            return '';
        }
        try {
            return Config::installed() ? (string) Settings::get('general.logo', '') : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * علامة الشبكة: شعارها المرفوع، وإلا أول حرف من اسمها.
     */
    public static function networkMark(string $class = ''): string
    {
        $logo = self::networkLogo();
        $cls = trim('net-mark ' . $class);
        if ($logo !== '') {
            return '<span class="' . e($cls) . ' has-logo"><img src="' . e(upload_url($logo)) . '" alt=""></span>';
        }
        $name = self::networkName();
        $initial = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', $name) ?: 'N', 0, 1));
        return '<span class="' . e($cls) . '" aria-hidden="true">' . e($initial) . '</span>';
    }

    public static function networkName(): string
    {
        if (self::onPlatform()) {
            return self::NAME;
        }
        try {
            return Config::installed() ? Settings::networkName() : self::NAME;
        } catch (\Throwable) {
            return self::NAME;
        }
    }

    /** رابط صورة شعار النظام: الشعار الذي وضعه مسؤول المنصة، وإلا الأصلي */
    public static function markSrc(): string
    {
        return \App\Services\BrandLogo::url('mark-512') ?? asset('img/brand/mark.svg');
    }

    /** شعار النظام كصورة */
    public static function mark(int $size = 40, string $class = ''): string
    {
        return '<img class="' . e(trim('brand-logo ' . $class)) . '" src="' . e(self::markSrc()) . '" width="' . $size . '" height="' . $size . '" alt="' . self::NAME . '">';
    }

    /** علامة التوثيق الزرقاء بجانب اسم المطوّر (بنمط علامة التوثيق في إنستغرام) */
    public static function verified(int $size = 14): string
    {
        return '<img class="verified-badge" src="' . e(asset('img/brand/verified.svg')) . '" width="' . $size . '" height="' . $size . '" alt="Verified" title="Verified" decoding="async">';
    }

    /** اسم المطوّر مع علامة التوثيق: لا ينفصلان عند التفاف السطر */
    public static function developer(int $size = 14): string
    {
        return '<span class="dev-name"><b>Sohaib Qudeih</b>' . self::verified($size) . '</span>';
    }

    /** اسم النظام بخطّين */
    public static function wordmark(string $class = ''): string
    {
        return '<span class="' . e(trim('wordmark ' . $class)) . '" dir="ltr"><b>MADIX</b> <span>NEXUS</span></span>';
    }

    /** وسوم الأيقونة والتطبيق في رأس الصفحة */
    public static function headTags(): string
    {
        $logo = self::networkLogo();
        $custom = \App\Services\BrandLogo::dir() !== null;
        if ($logo !== '') {
            $icon = upload_url($logo);
            $type = '';
            $alt = '';
            $apple = upload_url($logo);
        } elseif ($custom) {
            $icon = (string) \App\Services\BrandLogo::url('mark-192');
            $type = ' type="image/png"';
            $alt = (string) \App\Services\BrandLogo::url('mark-32');
            $apple = (string) \App\Services\BrandLogo::url('apple-180');
        } else {
            $icon = asset('img/brand/mark.svg');
            $type = ' type="image/svg+xml"';
            $alt = asset('img/brand/favicon-32.png');
            $apple = asset('img/brand/apple-touch-icon.png');
        }
        return '<link rel="icon"' . $type . ' href="' . e($icon) . '">' . "\n"
            . ($alt !== '' ? '<link rel="alternate icon" href="' . e($alt) . '">' . "\n" : '')
            . '<link rel="apple-touch-icon" href="' . e($apple) . '">' . "\n"
            . '<link rel="manifest" href="' . e(url('/manifest.webmanifest')) . '">' . "\n"
            . '<meta name="theme-color" content="' . self::COLOR . '">' . "\n"
            . '<meta name="application-name" content="' . self::NAME . '">';
    }
}
