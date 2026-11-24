<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Platform;

/**
 * الربط مع لوحة MADIX Cloud (نظام الكاشير MADIX ERP) عبر واجهة v1.php بمفتاح «مفتاح بوت»:
 * قراءة بيانات صاحب المحل (الترخيص والأجهزة) والباقات والإصدارات ومعلومات الشركة، وفتح تذاكر الدعم وطلبات التجديد فقط.
 * يُضبط من لوحة المسؤول (بوت خدمة الزبائن)، والمفتاح يُحفظ مشفّراً بمفتاح المنصة ولا يُعرض ولا يُسجَّل.
 */
final class MadixCloud
{
    public static function key(): string
    {
        return Platform::secret('bot.erp_key');
    }

    public static function base(): string
    {
        $url = trim(Platform::setting('bot.erp_url'));
        return preg_match('#^https?://[^\s/]+#i', $url) ? rtrim($url, '/') : '';
    }

    public static function enabled(): bool
    {
        return Platform::enabled() && Platform::setting('bot.erp') === '1' && self::base() !== '' && self::key() !== '';
    }

    /** حذف المفتاح وأي رمز يشبهه من نص قبل عرضه أو تسجيله */
    public static function scrub(string $text, ?string $key = null): string
    {
        $key ??= self::key();
        if ($key !== '') {
            $text = str_replace($key, '***', $text);
        }
        return (string) preg_replace('/mxk_[A-Za-z0-9_\-]{6,}/', '***', $text);
    }

    /**
     * طلب واحد إلى v1.php.
     * @param array{base?:string, key?:string} $with قيم مكتوبة قبل الحفظ (للاختبار)
     * @return array{ok:bool, status:int, data:mixed, message:string, code?:string}
     */
    public static function call(string $route, array $query = [], ?array $body = null, array $with = [], int $timeout = 12): array
    {
        $base = $with['base'] ?? self::base();
        $key = $with['key'] ?? self::key();
        if ($base === '' || $key === '') {
            return ['ok' => false, 'status' => 0, 'data' => null, 'message' => 'الربط مع MADIX Cloud غير مضبوط.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'message' => 'cURL غير متوفر'];
        }
        $url = $base . '/v1.php?' . http_build_query(['r' => $route] + array_filter($query, static fn ($v) => $v !== null && $v !== ''));
        $ch = curl_init($url);
        $headers = ['Authorization: Bearer ' . $key, 'Accept: application/json'];
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_USERAGENT => 'MADIX-NEXUS-Bot'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $o[CURLOPT_POST] = true;
            $o[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $o[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $o);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        unset($ch);
        if ($raw === false) {
            log_message('warning', 'madix cloud ' . $route . ': ' . self::scrub($err, $key));
            return ['ok' => false, 'status' => 0, 'data' => null, 'message' => 'تعذّر الاتصال بـ MADIX Cloud.'];
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            return ['ok' => false, 'status' => $status, 'data' => null, 'message' => 'رد غير متوقع من MADIX Cloud (HTTP ' . $status . ').'];
        }
        // الشكل الموحّد {"ok":true,"data":...} أو {"ok":false,"error":{"code","message"}}، ويُقبل الرد المباشر أيضاً
        $ok = $status >= 200 && $status < 300 && ($json['ok'] ?? true) !== false;
        $data = array_key_exists('data', $json) ? $json['data'] : $json;
        if ($ok) {
            return ['ok' => true, 'status' => $status, 'data' => $data, 'message' => ''];
        }
        $error = $json['error'] ?? ($json['message'] ?? '');
        $code = is_array($error) ? (string) ($error['code'] ?? '') : '';
        $message = is_array($error) ? (string) ($error['message'] ?? $code) : (string) $error;
        $message = match (true) {
            $status === 401 && $code === 'key_expired' => 'مفتاح MADIX Cloud منتهي الصلاحية.',
            $status === 401 => 'مفتاح MADIX Cloud غير صحيح أو ملغى.',
            $status === 403 && $code === 'ip_not_allowed' => 'عنوان هذا الخادم غير مسموح لمفتاح MADIX Cloud؛ أضفه في «عناوين IP المسموحة» أو اترك القائمة فارغة.',
            $status === 403 => 'المفتاح لا يملك صلاحية هذا الطلب' . ($message !== '' ? ': ' . self::scrub($message, $key) : '.'),
            $status === 404 && $message === '' => 'غير موجود.',
            $status === 429 => 'تم تجاوز حد الطلبات على MADIX Cloud. حاول بعد قليل.',
            $message !== '' => self::scrub(mb_substr($message, 0, 200), $key),
            default => 'تعذّر تنفيذ الطلب على MADIX Cloud (HTTP ' . $status . ').',
        };
        return ['ok' => false, 'status' => $status, 'data' => $json['data'] ?? null, 'message' => $message, 'code' => $code];
    }

    /** اختبار الاتصال: اسم المفتاح وصلاحياته */
    public static function test(array $with = []): array
    {
        $r = self::call('whoami', [], null, $with);
        if (!$r['ok']) {
            return ['ok' => false, 'message' => $r['message']];
        }
        $d = is_array($r['data']) ? $r['data'] : [];
        $name = (string) ($d['name'] ?? ($d['key']['name'] ?? ''));
        $scopes = $d['scopes'] ?? ($d['key']['scopes'] ?? []);
        $scopes = is_array($scopes) ? array_map('strval', $scopes) : array_filter(array_map('trim', explode(',', (string) $scopes)));
        $dangerous = array_values(array_intersect($scopes, ['licenses.issue', 'customers.control', 'notifications.send']));
        $message = 'الاتصال يعمل' . ($name !== '' ? ' · ' . $name : '') . ($scopes ? ' · ' . implode('، ', $scopes) : '')
            . (is_string($d['your_ip'] ?? null) && $d['your_ip'] !== '' ? ' · عنوان هذا الخادم: ' . $d['your_ip'] : '');
        if ($dangerous) {
            $message .= '. تنبيه: المفتاح يملك صلاحيات لا يحتاجها البوت (' . implode('، ', $dangerous) . ')، استعمل «مفتاح بوت».';
        }
        return ['ok' => true, 'message' => $message, 'scopes' => $scopes];
    }

    /**
     * محلات رد البحث بالهاتف: data.items[] وفي كل عنصر رقم المحل في customer.
     * @return list<array{customer:string, shop:string}>
     */
    public static function shops(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : (isset($data['customer']) ? [$data] : []);
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $id = $it['customer'] ?? ($it['id'] ?? null);
            if (is_scalar($id) && preg_match('/^[A-Za-z0-9\-]{2,40}$/', (string) $id)) {
                $out[] = ['customer' => strtoupper((string) $id), 'shop' => mb_substr((string) ($it['shop'] ?? ''), 0, 120)];
            }
        }
        return array_slice($out, 0, 10);
    }

    /** معلومات الشركة للبوت (تُخزَّن عشر دقائق حتى لا تُطلب مع كل رسالة) */
    public static function knowledge(): string
    {
        $cache = json_decode(Platform::setting('bot.erp_cache'), true);
        if (is_array($cache) && (int) ($cache['at'] ?? 0) > time() - 600) {
            return (string) ($cache['text'] ?? '');
        }
        $text = '';
        $r = self::call('knowledge', [], null, [], 8);
        if ($r['ok']) {
            $text = is_string($r['data']) ? $r['data'] : (string) json_encode($r['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $text = mb_substr(trim($text), 0, 12000);
        }
        // الفشل يُخزَّن أيضاً فلا يتأخر كل رد بانتظار خادم لا يستجيب
        Platform::setSettings(['bot.erp_cache' => (string) json_encode(['at' => time(), 'text' => $text], JSON_UNESCAPED_UNICODE)]);
        return $text;
    }
}
