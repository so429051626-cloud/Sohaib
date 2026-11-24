<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Brand;
use App\Core\Server;
use RuntimeException;
use Throwable;

/**
 * إشعارات الخادم والنسخ الاحتياطي الخارجي عبر بوت تيليجرام يملكه مسؤول الخادم.
 */
final class ServerPush
{
    /** الحد الأعلى لحجم الجزء الواحد (حد تيليجرام للبوتات 50 ميغابايت) */
    private const PART = 45 * 1024 * 1024;

    public const SERVICES = ['web' => 'خادم الويب', 'db' => 'قاعدة البيانات', 'cron' => 'المهام المجدولة', 'firewall' => 'الجدار الناري', 'guard' => 'الحماية من التخمين', 'tunnel' => 'نفق أجهزة MikroTik'];

    public static function token(): string
    {
        return Server::secret('tg_token');
    }

    /** @return list<string> */
    public static function chats(): array
    {
        $ids = preg_split('/[\s,،;]+/u', latin_digits(Server::setting('tg_chat')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter($ids, static fn ($id) => (bool) preg_match('/^-?\d{3,20}$|^@\w{4,}$/', $id))));
    }

    public static function configured(): bool
    {
        return self::token() !== '' && self::chats() !== [];
    }

    public static function offsiteEnabled(): bool
    {
        return self::configured() && Server::setting('offsite') === '1' && Server::secret('offsite_pass') !== '';
    }

    /** نص التنبيه بالعربية */
    public static function alertText(array $a): string
    {
        $args = is_array($a['args'] ?? null) ? $a['args'] : [];
        $name = self::SERVICES[$args['name'] ?? ''] ?? 'خدمة';
        return match ((string) ($a['code'] ?? '')) {
            'service_restarted' => 'توقّف ' . $name . ' وأُعيد تشغيله تلقائياً',
            'service_down'      => 'توقّف ' . $name . ' وتعذّرت إعادة تشغيله',
            'web_recovered'     => 'الموقع لم يكن يستجيب وأُعيد تشغيل خادم الويب',
            'web_unresponsive'  => 'الموقع لا يستجيب',
            'disk_high'         => 'مساحة القرص ممتلئة بنسبة ' . (int) ($args['percent'] ?? 0) . '٪',
            'ssl_expiring'      => 'شهادة SSL تنتهي خلال ' . (int) ($args['days'] ?? 0) . ' يوم',
            'backup_stale'      => 'لم تُنشأ نسخة احتياطية منذ أكثر من 36 ساعة',
            'backup_failed'     => 'فشل إنشاء النسخة الاحتياطية',
            'tasks_stale'       => 'المهام المجدولة متوقفة منذ ' . (int) ($args['minutes'] ?? 0) . ' دقيقة',
            'reboot_required'   => 'الخادم يحتاج إعادة تشغيل لإكمال تحديثات النظام',
            default             => 'تنبيه من الخادم',
        };
    }

    private static function send(string $html): bool
    {
        $ok = false;
        foreach (self::chats() as $chat) {
            try {
                $r = Telegram::call('sendMessage', ['chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true], 15, self::token());
                $ok = $ok || !empty($r['ok']);
            } catch (Throwable $e) {
                log_message('warning', 'server push: ' . $e->getMessage());
            }
        }
        return $ok;
    }

    /** @return array{ok:bool, message:string} */
    public static function test(?string $token = null, ?string $chats = null): array
    {
        $token = $token !== null && $token !== '' ? $token : self::token();
        $list = $chats !== null && trim($chats) !== '' ? Telegram::parseChats($chats) : self::chats();
        if ($chats !== null && trim($chats) !== '' && !$list) {
            return ['ok' => false, 'message' => 'معرّف المحادثة غير صالح: يكون أرقاماً مثل 123456789 أو -1001234567890.'];
        }
        $r = Telegram::deliver($token, $list, '✅ <b>' . Telegram::esc(Brand::NAME) . '</b>' . "\n" . 'إشعارات الخادم تعمل.');
        return ['ok' => $r['ok'], 'message' => str_replace($token, '***', $r['message'])];
    }

    /** إرسال التنبيهات الجديدة؛ يرجع عددها */
    public static function alerts(): int
    {
        $data = Server::alerts();
        $last = (int) Server::setting('alert_pushed', '0');
        if ($data['last_id'] < $last) {
            $last = 0; // بدأ المحرك عدّاً جديداً
        }
        $new = array_reverse(array_filter($data['items'], static fn ($a) => (int) ($a['id'] ?? 0) > $last));
        if (!$new) {
            return 0;
        }
        Server::setSettings(['alert_pushed' => (string) $data['last_id']]);
        if (Server::setting('alerts', '1') !== '1' || !self::configured()) {
            return 0;
        }
        $icons = ['danger' => '🔴', 'warning' => '🟠', 'info' => '🔵'];
        $lines = [];
        foreach ($new as $a) {
            if (($a['level'] ?? '') === 'info') {
                continue;
            }
            $lines[] = ($icons[$a['level'] ?? ''] ?? '🔵') . ' ' . Telegram::esc(self::alertText($a)) . ' · ' . date('H:i', (int) ($a['time'] ?? time()));
        }
        if (!$lines) {
            return 0;
        }
        self::send('<b>' . Telegram::esc(Brand::NAME) . ' · الخادم</b>' . "\n" . implode("\n", $lines));
        return count($lines);
    }

    /**
     * إرسال أرشيف النسخة الاحتياطية مشفّراً بكلمة المرور إلى تيليجرام.
     * @return array{ok:bool, message:string}
     */
    public static function offsite(string $tar): array
    {
        $result = self::doOffsite($tar);
        Server::setSettings([
            'offsite_last' => now(),
            'offsite_status' => $result['ok'] ? 'ok' : mb_substr($result['message'], 0, 180),
        ]);
        return $result;
    }

    private static function doOffsite(string $tar): array
    {
        if (!self::offsiteEnabled()) {
            return ['ok' => false, 'message' => 'النسخ الخارجي غير مفعّل.'];
        }
        $real = realpath($tar);
        if ($real === false || !str_starts_with($real, Server::dir() . '/outbox/') || !preg_match('/^madix-\d{8}-\d{6}\.tar$/', basename($real))) {
            return ['ok' => false, 'message' => 'ملف غير صالح.'];
        }
        $tmp = storage_path('tmp');
        if (!is_dir($tmp)) {
            @mkdir($tmp, 0775, true);
        }
        $base = basename($real) . '.enc';
        $enc = $tmp . '/' . $base;
        $files = [];
        try {
            self::encrypt($real, $enc, Server::secret('offsite_pass'));
            $size = (int) filesize($enc);
            if ($size <= self::PART) {
                $files[] = [$enc, $base];
            } else {
                $in = fopen($enc, 'rb');
                for ($i = 1; !feof($in); $i++) {
                    $part = sprintf('%s.%03d', $enc, $i);
                    $out = fopen($part, 'wb');
                    $left = self::PART;
                    while ($left > 0 && !feof($in)) {
                        $chunk = (string) fread($in, min(1048576, $left));
                        fwrite($out, $chunk);
                        $left -= strlen($chunk);
                    }
                    fclose($out);
                    if ((int) filesize($part) === 0) {
                        @unlink($part);
                        break;
                    }
                    $files[] = [$part, basename($part)];
                }
                fclose($in);
            }
            $total = count($files);
            foreach (self::chats() as $chat) {
                foreach ($files as $n => [$path, $name]) {
                    $caption = '🗄 ' . Brand::NAME . ' · نسخة احتياطية ' . date('Y-m-d H:i') . ($total > 1 ? ' · الجزء ' . ($n + 1) . '/' . $total : '') . ' · ' . fmt_bytes($size);
                    $sent = false;
                    $error = '';
                    for ($try = 1; $try <= 3 && !$sent; $try++) {
                        try {
                            $r = Telegram::sendDocument(self::token(), $chat, $path, $name, $caption);
                            $sent = !empty($r['ok']);
                            $error = (string) ($r['description'] ?? '');
                        } catch (Throwable $e) {
                            $error = $e->getMessage();
                        }
                        if (!$sent && $try < 3) {
                            sleep(3 * $try);
                        }
                    }
                    if (!$sent) {
                        throw new RuntimeException('تعذّر الإرسال إلى تيليجرام' . ($error !== '' ? ': ' . $error : '.'));
                    }
                }
            }
            return ['ok' => true, 'message' => 'تم إرسال النسخة.'];
        } catch (Throwable $e) {
            log_message('error', 'offsite backup: ' . $e->getMessage());
            return ['ok' => false, 'message' => $e->getMessage()];
        } finally {
            @unlink($enc);
            foreach ($files as [$path]) {
                @unlink($path);
            }
        }
    }

    /** تشفير AES-256 متوافق مع أداة openssl (يُفك بالأمر madix restore) */
    private static function encrypt(string $in, string $out, string $pass): void
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('تعذّر تشغيل أداة التشفير.');
        }
        $proc = @proc_open(
            ['openssl', 'enc', '-aes-256-cbc', '-pbkdf2', '-md', 'sha256', '-salt', '-pass', 'env:MX_PASS', '-in', $in, '-out', $out],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['MX_PASS' => $pass, 'PATH' => '/usr/local/bin:/usr/bin:/bin']
        );
        if (!is_resource($proc) || proc_close($proc) !== 0 || !is_file($out) || filesize($out) < 32) {
            @unlink($out);
            throw new RuntimeException('تعذّر تشفير النسخة الاحتياطية.');
        }
        @chmod($out, 0600);
    }
}
