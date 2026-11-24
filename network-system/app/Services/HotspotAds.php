<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Settings;
use App\Core\Throttle;

/**
 * إعلانات صفحة الهوتسبوت: عروض الشبكة وإعلانات محلات المنطقة تظهر على صفحة الدخول عبر مكتبة الهوتسبوت.
 * لكل إعلان مدة عرض (من/إلى) وأجهزة MikroTik محددة اختيارياً، ويُحسب عدد مرات الظهور والضغط.
 */
final class HotspotAds
{
    public const MAX_SHOWN = 10;

    /** الأجهزة المسموحة لإعلان (فارغ = كل الأجهزة) */
    public static function routerIds(array $ad): array
    {
        $raw = trim((string) ($ad['routers'] ?? ''));
        if ($raw === '') {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), static fn ($v) => $v > 0)));
    }

    /** حالة الإعلان الآن: active | scheduled | expired | off */
    public static function state(array $ad, ?string $today = null): string
    {
        $today ??= date('Y-m-d');
        if ((int) $ad['is_active'] !== 1) {
            return 'off';
        }
        if ($ad['starts_on'] !== null && $ad['starts_on'] > $today) {
            return 'scheduled';
        }
        if ($ad['ends_on'] !== null && $ad['ends_on'] < $today) {
            return 'expired';
        }
        return 'active';
    }

    public const STATES = [
        'active'    => ['يُعرض الآن', 'badge-success'],
        'scheduled' => ['مجدول', 'badge-info'],
        'expired'   => ['انتهت مدته', 'badge-outline'],
        'off'       => ['موقوف', 'badge-warning'],
    ];

    /** الإعلانات المعروضة الآن على جهاز MikroTik */
    public static function forRouter(array $router): array
    {
        $today = date('Y-m-d');
        $rows = DB::rows(
            'SELECT * FROM hotspot_ads WHERE is_active = 1 AND (starts_on IS NULL OR starts_on <= ?) AND (ends_on IS NULL OR ends_on >= ?)
             ORDER BY sort, id DESC LIMIT 100',
            [$today, $today]
        );
        $out = [];
        foreach ($rows as $ad) {
            $only = self::routerIds($ad);
            if ($only && !in_array((int) $router['id'], $only, true)) {
                continue;
            }
            $out[] = $ad;
            if (count($out) >= self::MAX_SHOWN) {
                break;
            }
        }
        return $out;
    }

    /** رابط آمن للإعلان: http/https فقط */
    public static function safeLink(?string $link): ?string
    {
        $link = trim((string) $link);
        return $link !== '' && preg_match('#^https?://[^\s"<>]+$#i', $link) ? $link : null;
    }

    /** رقم الهاتف للاتصال (أرقام و+ فقط) */
    public static function telNumber(?string $phone): ?string
    {
        $n = preg_replace('/[^0-9+]/', '', latin_digits((string) $phone));
        return $n !== null && strlen($n) >= 5 ? $n : null;
    }

    /** بيانات الإعلان كما تُرسل لصفحة الهوتسبوت */
    public static function payload(array $ad): array
    {
        return [
            'id'         => (int) $ad['id'],
            'title'      => (string) $ad['title'],
            'body'       => (string) ($ad['body'] ?? ''),
            'advertiser' => (string) ($ad['advertiser'] ?? ''),
            'image'      => $ad['image'] ? self::imageUrl((string) $ad['image']) : null,
            'link'       => self::safeLink($ad['link'] ?? null),
            'phone'      => (string) ($ad['phone'] ?? ''),
            'tel'        => self::telNumber($ad['phone'] ?? null),
        ];
    }

    /** رابط الصورة الكامل من نطاق النظام (الصفحة تُفتح من نطاق جهاز MikroTik) */
    public static function imageUrl(string $rel): string
    {
        $origin = (string) preg_replace('#^(https?://[^/]+).*$#i', '$1', Settings::panelUrl());
        return $origin . upload_url($rel);
    }

    public static function interval(): int
    {
        return max(3, min(60, Settings::int('hotspot.ads_interval', 6)));
    }

    /** احتساب الظهور: مرة لكل جهاز زائر كل 10 دقائق على نفس نقطة الاتصال */
    public static function recordViews(array $ads, int $routerId, string $visitor): void
    {
        if (!$ads || !Throttle::rate('ads:v:' . $routerId . ':' . $visitor, 1, 600)) {
            return;
        }
        $ids = array_map(static fn ($a) => (int) $a['id'], $ads);
        DB::query('UPDATE hotspot_ads SET views = views + 1 WHERE id IN (' . DB::in($ids) . ')', $ids);
    }

    /** احتساب الضغط: مرة لكل زائر وإعلان كل دقيقة */
    public static function recordClick(int $adId, int $routerId, string $visitor): bool
    {
        $ad = DB::row('SELECT * FROM hotspot_ads WHERE id = ?', [$adId]);
        if (!$ad || self::state($ad) !== 'active') {
            return false;
        }
        $only = self::routerIds($ad);
        if ($only && !in_array($routerId, $only, true)) {
            return false;
        }
        if (Throttle::rate('ads:c:' . $adId . ':' . $visitor, 1, 60)) {
            DB::query('UPDATE hotspot_ads SET clicks = clicks + 1 WHERE id = ?', [$adId]);
        }
        return true;
    }

    /** حذف صورة إعلان من مجلد الرفع */
    public static function deleteImage(?string $rel): void
    {
        $rel = (string) $rel;
        if ($rel !== '' && str_starts_with($rel, 'uploads/') && !str_contains($rel, '..')) {
            @unlink(PUBLIC_PATH . '/' . $rel);
        }
    }
}
