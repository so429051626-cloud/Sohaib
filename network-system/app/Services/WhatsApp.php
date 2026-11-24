<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Platform;

/**
 * واتساب عبر WhatsApp Cloud API الرسمي من Meta: استقبال رسائل الزبائن على رابط Webhook، والرد عليها.
 * رقم واحد للمنصة يُضبط من لوحة المسؤول، والرمز (Access Token) وسر التطبيق (App Secret) يُحفظان مشفّرين بمفتاح المنصة ولا يُعرضان.
 * الرد الحر مسموح خلال 24 ساعة من آخر رسالة للزبون؛ بعدها تحتاج Meta قالباً معتمداً.
 */
final class WhatsApp
{
    public const GRAPH = 'https://graph.facebook.com/v23.0';
    /** أقصى طول لرسالة نصية في واتساب */
    private const MAX = 4000;

    /** عنوان Graph API (قابل للتغيير في ملف الإعداد للاختبار) */
    private static function graph(): string
    {
        return rtrim((string) Config::get('whatsapp.graph', self::GRAPH), '/');
    }

    public static function token(): string
    {
        return Platform::secret('bot.wa_token');
    }

    public static function appSecret(): string
    {
        return Platform::secret('bot.wa_secret');
    }

    public static function phoneId(): string
    {
        $id = trim(Platform::setting('bot.wa_phone_id'));
        return preg_match('/^\d{5,25}$/', $id) ? $id : '';
    }

    public static function configured(): bool
    {
        return Platform::enabled() && Platform::setting('bot.whatsapp') === '1' && self::phoneId() !== '' && self::token() !== '';
    }

    /** مسار Webhook السري ورمز التحقق: يُولَّدان مرة واحدة */
    public static function ensureKeys(): void
    {
        $set = [];
        if (!preg_match('/^[a-f0-9]{32}$/', Platform::setting('bot.wa_hook'))) {
            $set['bot.wa_hook'] = bin2hex(random_bytes(16));
        }
        if (Platform::setting('bot.wa_verify') === '') {
            $set['bot.wa_verify'] = bin2hex(random_bytes(12));
        }
        if ($set) {
            Platform::setSettings($set);
        }
    }

    public static function webhookUrl(): string
    {
        return BotAgent::publicUrl() . '/api/whatsapp/' . Platform::setting('bot.wa_hook');
    }

    public static function scrub(string $text, ?string $token = null): string
    {
        foreach (array_unique([$token ?? '', self::token(), self::appSecret()]) as $s) {
            if ($s !== '') {
                $text = str_replace($s, '***', $text);
            }
        }
        return (string) preg_replace('/EAA[A-Za-z0-9]{20,}/', '***', $text);
    }

    /** توقيع Meta على جسم الطلب (X-Hub-Signature-256). بلا سر التطبيق لا يُفحص */
    public static function signatureValid(string $raw, ?string $header): bool
    {
        $secret = self::appSecret();
        if ($secret === '') {
            return true;
        }
        $header = (string) $header;
        return $header !== '' && hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $header);
    }

    /**
     * الرسائل الواردة في تحديث Webhook.
     * @return list<array{id:string, from:string, name:string, type:string, text:string}>
     */
    public static function parse(array $payload): array
    {
        $out = [];
        $phoneId = self::phoneId();
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                // رقم آخر على نفس التطبيق: ليس لهذا الحساب
                $to = (string) ($value['metadata']['phone_number_id'] ?? '');
                if ($phoneId !== '' && $to !== '' && $to !== $phoneId) {
                    continue;
                }
                $names = [];
                foreach ((array) ($value['contacts'] ?? []) as $c) {
                    $names[(string) ($c['wa_id'] ?? '')] = (string) ($c['profile']['name'] ?? '');
                }
                foreach ((array) ($value['messages'] ?? []) as $m) {
                    $from = preg_replace('/\D/', '', (string) ($m['from'] ?? ''));
                    $id = (string) ($m['id'] ?? '');
                    if ($from === '' || $id === '') {
                        continue;
                    }
                    $type = (string) ($m['type'] ?? '');
                    $text = match ($type) {
                        'text' => (string) ($m['text']['body'] ?? ''),
                        'button' => (string) ($m['button']['text'] ?? ''),
                        'interactive' => (string) ($m['interactive']['button_reply']['title'] ?? ($m['interactive']['list_reply']['title'] ?? '')),
                        default => '',
                    };
                    if ($text === '' && in_array($type, ['image', 'video', 'document', 'audio', 'sticker'], true)) {
                        $text = (string) ($m[$type]['caption'] ?? '');
                    }
                    $out[] = ['id' => $id, 'from' => $from, 'name' => mb_substr($names[$from] ?? '', 0, 120), 'type' => $type, 'text' => trim($text)];
                }
            }
        }
        return $out;
    }

    /**
     * @param array{phone_id?:string, token?:string} $with قيم مكتوبة قبل الحفظ
     * @return array{status:int, data:?array, error:string}
     */
    private static function http(string $method, string $path, ?array $body, array $with = [], int $timeout = 15): array
    {
        $token = $with['token'] ?? self::token();
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'data' => null, 'error' => 'cURL غير متوفر'];
        }
        $ch = curl_init(self::graph() . '/' . ltrim($path, '/'));
        $o = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        ];
        if ($method === 'POST') {
            $o[CURLOPT_POST] = true;
            $o[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        curl_setopt_array($ch, $o);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        unset($ch);
        if ($raw === false) {
            return ['status' => 0, 'data' => null, 'error' => $err !== '' ? $err : 'connection failed'];
        }
        $data = json_decode((string) $raw, true);
        $error = is_array($data) && isset($data['error']) ? (string) ($data['error']['message'] ?? 'error') : (is_array($data) ? '' : 'unexpected response');
        return ['status' => $status, 'data' => is_array($data) ? $data : null, 'error' => self::scrub($error, $token)];
    }

    private static function explain(array $res): string
    {
        $e = strtolower($res['error']);
        return match (true) {
            $res['status'] === 0 => 'تعذّر الاتصال بخوادم واتساب.',
            $res['status'] === 401 || str_contains($e, 'access token') => 'رمز الوصول (Access Token) غير صحيح أو منتهي.',
            str_contains($e, 're-engagement') || str_contains($e, '24 hours') => 'مرّ أكثر من 24 ساعة على آخر رسالة من الزبون؛ واتساب لا يسمح بالرد الحر بعدها.',
            str_contains($e, 'unsupported post request') || $res['status'] === 404 => 'معرّف رقم الهاتف (Phone number ID) غير صحيح.',
            $res['status'] === 429 => 'تم تجاوز حد الإرسال في واتساب. حاول بعد قليل.',
            default => 'تعذّر الإرسال عبر واتساب: ' . mb_substr($res['error'], 0, 160),
        };
    }

    /** تقسيم الرد الطويل على أسطر */
    private static function chunks(string $text): array
    {
        if (mb_strlen($text) <= self::MAX) {
            return [$text];
        }
        $out = [];
        $cur = '';
        foreach (preg_split('/\n/u', $text) ?: [] as $line) {
            if (mb_strlen($cur . "\n" . $line) > self::MAX && $cur !== '') {
                $out[] = $cur;
                $cur = '';
            }
            $cur = $cur === '' ? mb_substr($line, 0, self::MAX) : $cur . "\n" . $line;
        }
        if ($cur !== '') {
            $out[] = $cur;
        }
        return $out;
    }

    /** @return array{ok:bool, message:string, id:string} */
    public static function send(string $to, string $text): array
    {
        $to = preg_replace('/\D/', '', $to);
        if (!self::configured() || $to === '' || trim($text) === '') {
            return ['ok' => false, 'message' => 'واتساب غير مضبوط.', 'id' => ''];
        }
        $id = '';
        foreach (self::chunks($text) as $part) {
            $res = self::http('POST', self::phoneId() . '/messages', [
                'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to,
                'type' => 'text', 'text' => ['preview_url' => false, 'body' => $part],
            ]);
            if ($res['status'] !== 200) {
                log_message('warning', 'whatsapp send: HTTP ' . $res['status'] . ' ' . $res['error']);
                return ['ok' => false, 'message' => self::explain($res), 'id' => $id];
            }
            $id = (string) ($res['data']['messages'][0]['id'] ?? $id);
        }
        return ['ok' => true, 'message' => '', 'id' => $id];
    }

    /** إشعار القراءة (العلامتان الزرقاوان) */
    public static function markRead(string $messageId): void
    {
        if (!self::configured() || $messageId === '') {
            return;
        }
        self::http('POST', self::phoneId() . '/messages', ['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $messageId], [], 6);
    }

    /**
     * فحص الرقم والرمز.
     * @param array{phone_id?:string, token?:string} $with
     * @return array{ok:bool, message:string}
     */
    public static function test(array $with = []): array
    {
        $phoneId = $with['phone_id'] ?? self::phoneId();
        $token = $with['token'] ?? self::token();
        if ($phoneId === '' || $token === '') {
            return ['ok' => false, 'message' => 'أدخل معرّف رقم الهاتف ورمز الوصول.'];
        }
        $res = self::http('GET', $phoneId . '?fields=display_phone_number,verified_name,quality_rating', null, ['token' => $token], 10);
        if ($res['status'] !== 200 || !is_array($res['data'])) {
            return ['ok' => false, 'message' => self::explain($res)];
        }
        $d = $res['data'];
        $parts = array_filter([(string) ($d['verified_name'] ?? ''), (string) ($d['display_phone_number'] ?? '')]);
        return ['ok' => true, 'message' => 'الرقم يعمل' . ($parts ? ': ' . implode(' · ', $parts) : '') . '.'];
    }
}
