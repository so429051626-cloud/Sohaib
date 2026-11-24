<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * بوابة TweetSMS (نسخة form): POST https://tweetsms.ps/api.php/maan/<العملية>
 * - المفتاح في جسم الطلب (api_key) ولا يُطبع في أي سجل.
 * - النجاح = code 999 فقط، حتى لو كان HTTP 200.
 * - الأرقام بصيغة 972XXXXXXXXX، وحتى 100 رقم في الطلب الواحد.
 * - لا إعادة محاولة إلا لأخطاء الاتصال.
 */
final class TweetSms
{
    public const BATCH = 100;

    /** رسائل رموز عملية الإرسال (sendsms) فقط */
    public const SEND_CODES = [
        999  => 'تم الإرسال بنجاح.',
        -100 => 'بيانات ناقصة في الطلب.',
        -110 => 'مفتاح الربط غير صحيح.',
        -111 => 'حساب الرسائل غير مفعّل لدى الشركة.',
        -112 => 'حساب الرسائل محظور لدى الشركة.',
        -114 => 'الإرسال موقوف من حساب الرسائل.',
        -115 => 'اسم المرسل غير معتمد.',
        -116 => 'اسم المرسل غير معتمد.',
        -120 => 'لا توجد أرقام صالحة.',
        -124 => 'رصيد الرسائل لا يكفي.',
        -126 => 'يوجد إرسال آخر قيد التنفيذ حالياً.',
    ];

    /** رموز توقف الدفعات التالية (لن تنجح بإعادة المحاولة) */
    public const FATAL = [-110, -111, -112, -114, -115, -116, -124, -126];

    private static function base(): string
    {
        return rtrim((string) Config::get('sms.api_base', 'https://tweetsms.ps/api.php/maan'), '/');
    }

    /**
     * تنظيف رقم إلى 972XXXXXXXXX أو null إن كان غير صالح.
     */
    public static function normalize(string $raw): ?string
    {
        $s = strtr($raw, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        $s = (string) preg_replace('/\D+/', '', $s);
        if (str_starts_with($s, '00')) {
            $s = substr($s, 2);
        }
        if (str_starts_with($s, '970') || str_starts_with($s, '972')) {
            $s = substr($s, 3);
        }
        $s = ltrim($s, '0');
        if (!preg_match('/^5\d{8}$/', $s)) {
            return null;
        }
        return '972' . $s;
    }

    /**
     * تقسيم نص حر إلى أرقام: [صالحة فريدة، غير صالحة]
     * @return array{0:string[],1:string[]}
     */
    public static function parseNumbers(string $text): array
    {
        $ok = [];
        $bad = [];
        foreach (preg_split('/[\s,،;]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $n = self::normalize($part);
            if ($n === null) {
                $bad[] = $part;
            } else {
                $ok[$n] = true;
            }
        }
        return [array_map('strval', array_keys($ok)), array_values(array_unique($bad))];
    }

    /** عدد المقاطع: 70 حرفاً للعربي و160 للإنجليزي */
    public static function segments(string $message): int
    {
        $len = mb_strlen($message);
        if ($len === 0) {
            return 0;
        }
        $unicode = (bool) preg_match('/[^\x00-\x7F]/u', $message);
        return (int) ceil($len / ($unicode ? 70 : 160));
    }

    /**
     * استدعاء عملية.
     * @return array{ok:bool, code:?int, desc:string, raw:string, data:array, transport:bool}
     */
    public static function call(string $op, array $fields, string $apiKey, int $timeout = 25): array
    {
        $payload = http_build_query(['api_key' => $apiKey] + $fields);
        $url = self::base() . '/' . $op;
        $body = null;
        $error = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            ]);
            $res = curl_exec($ch);
            if ($res === false) {
                $error = curl_error($ch);
            } else {
                $body = (string) $res;
            }
            unset($ch);
        } else {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json", 'content' => $payload, 'timeout' => $timeout, 'ignore_errors' => true]]);
            $res = @file_get_contents($url, false, $ctx);
            if ($res === false) {
                $error = 'connection failed';
            } else {
                $body = (string) $res;
            }
        }
        if ($body === null) {
            return ['ok' => false, 'code' => null, 'desc' => 'تعذّر الاتصال ببوابة الرسائل.', 'raw' => self::scrub($error, $apiKey), 'data' => [], 'transport' => true];
        }
        $raw = self::scrub($body, $apiKey);
        $data = json_decode(trim($body), true);
        $code = null;
        $desc = '';
        if (is_array($data)) {
            $code = isset($data['code']) && is_numeric($data['code']) ? (int) $data['code'] : null;
            $desc = self::scrub((string) ($data['desc'] ?? $data['message'] ?? ''), $apiKey);
        } elseif (is_numeric(trim($body))) {
            $code = (int) trim($body);
            $data = [];
        } else {
            $data = [];
        }
        return ['ok' => $code === 999, 'code' => $code, 'desc' => $desc, 'raw' => mb_substr($raw, 0, 2000), 'data' => $data, 'transport' => false];
    }

    /** إزالة المفتاح من أي نص قبل حفظه أو عرضه */
    public static function scrub(string $text, string $apiKey): string
    {
        if ($apiKey !== '') {
            $text = str_replace([$apiKey, rawurlencode($apiKey)], '***', $text);
        }
        return (string) preg_replace('/api_key=[^&\s"]+/', 'api_key=***', $text);
    }

    /** رسالة عربية لنتيجة عملية الإرسال */
    public static function sendMessage(array $r): string
    {
        if ($r['transport']) {
            return 'تعذّر الاتصال ببوابة الرسائل.';
        }
        if ($r['code'] !== null && isset(self::SEND_CODES[$r['code']])) {
            return self::SEND_CODES[$r['code']];
        }
        return $r['desc'] !== '' ? $r['desc'] : 'رد غير متوقع من بوابة الرسائل' . ($r['code'] !== null ? ' (' . $r['code'] . ')' : '') . '.';
    }

    /** رسالة لنتيجة العمليات الأخرى (الرموز تختلف بين العمليات) */
    public static function otherMessage(array $r): string
    {
        if ($r['transport']) {
            return 'تعذّر الاتصال ببوابة الرسائل.';
        }
        return match ($r['code']) {
            999 => 'تم بنجاح.',
            -100 => 'بيانات ناقصة في الطلب.',
            -110 => 'مفتاح الربط غير صحيح.',
            default => $r['desc'] !== '' ? $r['desc'] : 'رد غير متوقع' . ($r['code'] !== null ? ' (' . $r['code'] . ')' : '') . '.',
        };
    }

    /**
     * إرسال نص واحد لمجموعة أرقام بدفعات من 100.
     * @param string[] $numbers أرقام منظّفة
     * @return array<int, array{numbers:string[], result:array}>
     */
    public static function sendBatches(string $apiKey, string $sender, string $message, array $numbers, ?string $date = null, ?string $time = null): array
    {
        $out = [];
        $stop = null;
        foreach (array_chunk(array_values($numbers), self::BATCH) as $i => $chunk) {
            if ($stop !== null) {
                $out[] = ['numbers' => $chunk, 'result' => $stop];
                continue;
            }
            if ($i > 0) {
                usleep(300000);
            }
            $r = self::sendOnce($apiKey, $sender, $message, $chunk, $date, $time);
            $out[] = ['numbers' => $chunk, 'result' => $r];
            if (!$r['ok'] && in_array($r['code'], self::FATAL, true)) {
                $stop = $r;
            }
        }
        return $out;
    }

    /** طلب إرسال واحد مع إعادة المحاولة لأخطاء الاتصال فقط */
    public static function sendOnce(string $apiKey, string $sender, string $message, array $numbers, ?string $date = null, ?string $time = null): array
    {
        $fields = ['sender' => $sender, 'message' => $message, 'to' => implode(',', $numbers)];
        if ($date !== null && $time !== null) {
            $fields['date'] = $date;
            $fields['time'] = $time;
        }
        $r = self::call('sendsms', $fields, $apiKey);
        for ($try = 1; $r['transport'] && $try <= 2; $try++) {
            usleep(1500000 * $try);
            $r = self::call('sendsms', $fields, $apiKey);
        }
        return $r;
    }

    /** الرصيد المتبقي: [ok, رصيد أو null، الرسالة] */
    public static function balance(string $apiKey): array
    {
        $r = self::call('chk_balance', [], $apiKey, 15);
        if (!$r['ok']) {
            return [false, null, self::otherMessage($r)];
        }
        foreach (['balance', 'credit', 'points', 'sms'] as $k) {
            if (isset($r['data'][$k]) && is_numeric($r['data'][$k])) {
                return [true, (float) $r['data'][$k], ''];
            }
        }
        if (preg_match('/-?\d+(?:\.\d+)?/', $r['desc'], $m)) {
            return [true, (float) $m[0], $r['desc']];
        }
        return [true, null, $r['desc']];
    }

    /** أسماء المرسل المعتمدة */
    public static function senders(string $apiKey): array
    {
        $r = self::call('usersender', [], $apiKey, 15);
        if (!$r['ok']) {
            return [];
        }
        $list = [];
        $walk = static function ($v) use (&$walk, &$list): void {
            if (is_string($v) && preg_match('/^[0-9A-Za-z_.,;:?@ -]{2,11}$/', $v)) {
                $list[] = trim($v);
            } elseif (is_array($v)) {
                foreach (['sender', 'sender_text', 'name', 'title'] as $k) {
                    if (isset($v[$k]) && is_string($v[$k])) {
                        $list[] = trim($v[$k]);
                        return;
                    }
                }
                foreach ($v as $x) {
                    $walk($x);
                }
            }
        };
        $walk($r['data']['data'] ?? $r['data']['senders'] ?? $r['data']['result'] ?? array_diff_key($r['data'], array_flip(['status', 'code', 'desc'])));
        return array_values(array_unique(array_filter($list)));
    }
}
