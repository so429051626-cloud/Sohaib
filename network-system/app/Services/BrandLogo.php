<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Platform;

/**
 * شعار النظام الذي يضعه مسؤول المنصة: يُطبَّق على كل الحسابات (القائمة، شاشات الدخول، صفحة الهبوط، أيقونة المتصفح والتطبيق).
 * يُقبل PNG أو WebP بخلفية شفافة (ملف SVG يحوّله المتصفح إلى PNG قبل الرفع، فلا يُخدم من مجلد الرفع أي SVG).
 * تُولَّد منه كل المقاسات المطلوبة مرة واحدة، وتبقى الشفافية كما هي (أيقونة الشاشة الرئيسية بخلفية بيضاء لأن iOS يجعل الشفاف أسود).
 */
final class BrandLogo
{
    public const MAX_BYTES = 3145728;
    private const KEY = 'brand.mark';

    /** مجلد الشعار الحالي (نسبي من public) أو null للشعار الأصلي */
    public static function dir(): ?string
    {
        if (!Platform::enabled()) {
            return null;
        }
        try {
            $dir = trim(Platform::setting(self::KEY, ''));
        } catch (\Throwable) {
            return null;
        }
        return $dir !== '' && preg_match('#^uploads/brand/[a-f0-9]{16}$#', $dir) && is_file(PUBLIC_PATH . '/' . $dir . '/mark-512.png') ? $dir : null;
    }

    /** رابط ملف من الشعار المرفوع: mark-512 | mark-192 | mark-32 | apple-180 | maskable-512 */
    public static function url(string $file): ?string
    {
        $dir = self::dir();
        return $dir === null ? null : upload_url($dir . '/' . $file . '.png');
    }

    /**
     * حفظ شعار جديد من ملف مرفوع.
     * @return array{ok:bool, error?:string}
     */
    public static function store(string $tmp, int $bytes): array
    {
        if ($bytes <= 0 || $bytes > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'حجم الشعار يجب ألا يتجاوز 3 ميغابايت.'];
        }
        $info = @getimagesize($tmp);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return ['ok' => false, 'error' => 'الشعار يجب أن يكون صورة PNG أو WebP أو SVG.'];
        }
        if ($info[0] < 64 || $info[1] < 64 || $info[0] > 6000 || $info[1] > 6000) {
            return ['ok' => false, 'error' => 'أبعاد الشعار بين 64 و6000 بكسل (يُفضَّل 512×512 أو أكبر).'];
        }
        $src = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($tmp) : @imagecreatefromwebp($tmp);
        if (!$src) {
            return ['ok' => false, 'error' => 'تعذّرت قراءة صورة الشعار.'];
        }
        if (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }
        imagealphablending($src, false);
        imagesavealpha($src, true);
        // إزالة الهوامش الشفافة تماماً حتى يملأ الشعار مساحته
        $crop = self::trimBox($src);
        if ($crop !== null) {
            $c = imagecrop($src, $crop);
            if ($c) {
                imagedestroy($src);
                $src = $c;
                imagealphablending($src, false);
                imagesavealpha($src, true);
            }
        }
        $rel = 'uploads/brand/' . bin2hex(random_bytes(8));
        $dir = PUBLIC_PATH . '/' . $rel;
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            imagedestroy($src);
            return ['ok' => false, 'error' => 'تعذّر حفظ الشعار على الخادم.'];
        }
        $ok = self::render($src, 512, 1.0, null, $dir . '/mark-512.png')
            && self::render($src, 192, 1.0, null, $dir . '/mark-192.png')
            && self::render($src, 32, 1.0, null, $dir . '/mark-32.png')
            && self::render($src, 180, .78, [255, 255, 255], $dir . '/apple-180.png')
            && self::render($src, 512, .62, [255, 255, 255], $dir . '/maskable-512.png');
        imagedestroy($src);
        if (!$ok) {
            self::removeDir($dir);
            return ['ok' => false, 'error' => 'تعذّر تجهيز مقاسات الشعار.'];
        }
        $old = self::dir();
        Platform::setSettings([self::KEY => $rel]);
        if ($old !== null && $old !== $rel) {
            self::removeDir(PUBLIC_PATH . '/' . $old);
        }
        return ['ok' => true];
    }

    /** العودة إلى الشعار الأصلي */
    public static function reset(): void
    {
        $old = self::dir();
        Platform::setSettings([self::KEY => '']);
        if ($old !== null) {
            self::removeDir(PUBLIC_PATH . '/' . $old);
        }
    }

    /** مستطيل المحتوى غير الشفاف تماماً، أو null إن لم يكن هناك ما يُقص */
    private static function trimBox(\GdImage $im): ?array
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $step = max(1, (int) floor(min($w, $h) / 512));
        $minX = $w;
        $minY = $h;
        $maxX = -1;
        $maxY = -1;
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) < 120) {
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                    $minY = min($minY, $y);
                    $maxY = max($maxY, $y);
                }
            }
        }
        if ($maxX < 0) {
            return null;
        }
        $minX = max(0, $minX - $step);
        $minY = max(0, $minY - $step);
        $maxX = min($w - 1, $maxX + $step);
        $maxY = min($h - 1, $maxY + $step);
        if ($minX === 0 && $minY === 0 && $maxX === $w - 1 && $maxY === $h - 1) {
            return null;
        }
        return ['x' => $minX, 'y' => $minY, 'width' => $maxX - $minX + 1, 'height' => $maxY - $minY + 1];
    }

    /** مربع بالمقاس المطلوب والشعار في وسطه بنسبة $fill (شفاف، أو بلون خلفية) */
    private static function render(\GdImage $src, int $size, float $fill, ?array $bg, string $path): bool
    {
        $out = imagecreatetruecolor($size, $size);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        $color = $bg === null ? imagecolorallocatealpha($out, 0, 0, 0, 127) : imagecolorallocate($out, $bg[0], $bg[1], $bg[2]);
        imagefilledrectangle($out, 0, 0, $size, $size, $color);
        imagealphablending($out, $bg !== null);
        $w = imagesx($src);
        $h = imagesy($src);
        $box = $size * $fill;
        $scale = min($box / $w, $box / $h);
        $dw = max(1, (int) round($w * $scale));
        $dh = max(1, (int) round($h * $scale));
        imagecopyresampled($out, $src, (int) round(($size - $dw) / 2), (int) round(($size - $dh) / 2), 0, 0, $dw, $dh, $w, $h);
        imagesavealpha($out, true);
        $ok = imagepng($out, $path, 9);
        imagedestroy($out);
        if ($ok) {
            @chmod($path, 0644);
        }
        return $ok;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir) || !str_contains($dir, '/uploads/brand/')) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
