<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Settings;
use RuntimeException;
use Throwable;

/**
 * عميل Bot API لتيليجرام.
 */
final class Telegram
{
    private static ?string $override = null;

    public static function token(): string
    {
        return self::$override ?? trim((string) Settings::get('notify.telegram_token'));
    }

    /** رمز بوت الزبائن المنفصل (فارغ = نفس بوت الإدارة) */
    public static function customerToken(): string
    {
        $t = trim((string) Settings::get('telegram.customer_token'));
        return $t !== '' && $t !== trim((string) Settings::get('notify.telegram_token')) ? $t : '';
    }

    /** تنفيذ عمليات باستخدام رمز بوت آخر */
    public static function using(string $token, callable $fn): mixed
    {
        $prev = self::$override;
        self::$override = $token;
        try {
            return $fn();
        } finally {
            self::$override = $prev;
        }
    }

    /** معرّفات المحادثات المسموح لها (مفصولة بفواصل أو مسافات) */
    public static function chats(): array
    {
        return self::parseChats((string) Settings::get('notify.telegram_chat'));
    }

    public static function enabled(): bool
    {
        return Settings::bool('notify.telegram_enabled') && self::token() !== '' && self::chats() !== [];
    }

    public static function allowed(string|int $chatId): bool
    {
        return in_array((string) $chatId, self::chats(), true);
    }

    private static function base(): string
    {
        return rtrim((string) Config::get('telegram.api_base', 'https://api.telegram.org'), '/');
    }

    /**
     * استدعاء دالة من الواجهة.
     * @return array{ok:bool, result?:mixed, description?:string}
     */
    public static function call(string $method, array $params = [], int $timeout = 10, ?string $token = null): array
    {
        $token ??= self::token();
        if ($token === '') {
            throw new RuntimeException('أدخل رمز البوت.');
        }
        $url = self::base() . '/bot' . $token . '/' . $method;
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                $params[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($v)) {
                $params[$k] = $v ? 'true' : 'false';
            }
        }
        $payload = http_build_query($params);
        if (function_exists('curl_init')) {
            $request = static function (bool $ipv4) use ($url, $payload, $timeout): array {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                ] + ($ipv4 ? [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4] : []));
                $body = curl_exec($ch);
                $out = [$body, curl_errno($ch), curl_error($ch)];
                unset($ch);
                return $out;
            };
            [$body, $errno, $err] = $request(false);
            // خوادم بعنوان IPv6 معطّل تفشل في الاتصال بتيليجرام: محاولة ثانية عبر IPv4
            if ($body === false && in_array($errno, [6, 7, 28, 35, 56], true)) {
                [$body, $errno, $err] = $request(true);
            }
            if ($body === false) {
                throw new RuntimeException('تعذّر الاتصال بخوادم تيليجرام من الخادم: ' . str_replace($token, '***', $err));
            }
        } else {
            $ctx = stream_context_create(['http' => [
                'method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => $payload,
                'timeout' => $timeout, 'ignore_errors' => true,
            ]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) {
                throw new RuntimeException('تعذّر الاتصال بتيليجرام.');
            }
        }
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new RuntimeException('رد غير متوقع من تيليجرام.');
        }
        return $data;
    }

    public static function send(string|int $chatId, string $html, array $extra = []): bool
    {
        try {
            $r = self::call('sendMessage', ['chat_id' => $chatId, 'text' => self::clip($html), 'parse_mode' => 'HTML', 'disable_web_page_preview' => true] + $extra);
            if (!($r['ok'] ?? false)) {
                log_message('warning', 'telegram send: ' . ($r['description'] ?? 'failed'));
            }
            return (bool) ($r['ok'] ?? false);
        } catch (Throwable $e) {
            log_message('warning', 'telegram send: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * إرسال إلى كل المحادثات المسموح لها.
     * @return array{ok:bool, message:string}
     */
    public static function broadcast(string $html, array $extra = []): array
    {
        $chats = self::chats();
        if (self::token() === '' || !$chats) {
            return ['ok' => false, 'message' => 'أدخل رمز البوت ومعرّف المحادثة.'];
        }
        $sent = 0;
        $error = '';
        foreach ($chats as $chat) {
            try {
                $r = self::call('sendMessage', ['chat_id' => $chat, 'text' => self::clip($html), 'parse_mode' => 'HTML', 'disable_web_page_preview' => true] + $extra);
                if ($r['ok'] ?? false) {
                    $sent++;
                } else {
                    $error = (count($chats) > 1 ? 'المحادثة ' . $chat . ': ' : '') . self::explain($r);
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
        return $sent > 0
            ? ['ok' => true, 'message' => $sent === count($chats) ? 'تم الإرسال.' : 'تم الإرسال إلى ' . $sent . ' من ' . count($chats) . '.']
            : ['ok' => false, 'message' => $error !== '' ? $error : 'تعذّر الإرسال.'];
    }

    /** سبب رفض تيليجرام لطلب بالعربية */
    public static function explain(array $r): string
    {
        $d = (string) ($r['description'] ?? '');
        $l = strtolower($d);
        $code = (int) ($r['error_code'] ?? 0);
        return match (true) {
            $code === 401 || str_contains($l, 'unauthorized') => 'رمز البوت غير صحيح أو أُلغي من BotFather.',
            str_contains($l, 'chat not found') => 'معرّف المحادثة غير صحيح، أو لم تُرسل أي رسالة إلى البوت بعد.',
            str_contains($l, "can't initiate conversation") || str_contains($l, 'bot was blocked') || str_contains($l, 'user is deactivated')
                => 'لم تبدأ محادثة مع البوت أو قمت بحظره: افتح البوت في تيليجرام واضغط ابدأ (Start).',
            str_contains($l, "bots can't send messages to bots") => 'المعرّف لبوت وليس لحساب أو مجموعة.',
            str_contains($l, 'upgraded to a supergroup') => 'تغيّر معرّف المجموعة، والمعرّف الجديد: ' . (string) ($r['parameters']['migrate_to_chat_id'] ?? '') . '.',
            str_contains($l, 'kicked') || str_contains($l, 'not a member') => 'البوت ليس عضواً في هذه المجموعة أو القناة.',
            str_contains($l, 'not enough rights') || str_contains($l, 'have no rights') || str_contains($l, 'administrator rights')
                => 'البوت لا يملك صلاحية الإرسال في هذه المجموعة أو القناة.',
            $code === 429 || str_contains($l, 'too many requests') => 'طلبات كثيرة إلى تيليجرام. حاول بعد دقيقة.',
            $code === 409 || str_contains($l, 'conflict') => 'البوت مربوط بعنوان استقبال (Webhook)، فلا تُقرأ رسائله من هنا.',
            $d !== '' => 'رفض تيليجرام الطلب: ' . $d,
            default => 'رد غير متوقع من تيليجرام.',
        };
    }

    /**
     * رسالة تجريبية برمز معيّن إلى محادثات معيّنة، مع سبب واضح لكل فشل.
     * @param list<string> $chats
     * @return array{ok:bool, message:string, sent:int, bot:string}
     */
    public static function deliver(string $token, array $chats, string $html): array
    {
        if ($token === '') {
            return ['ok' => false, 'message' => 'أدخل رمز البوت.', 'sent' => 0, 'bot' => ''];
        }
        if (!$chats) {
            return ['ok' => false, 'message' => 'أدخل معرّف المحادثة.', 'sent' => 0, 'bot' => ''];
        }
        try {
            $me = self::call('getMe', [], 10, $token);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'sent' => 0, 'bot' => ''];
        }
        if (empty($me['ok'])) {
            return ['ok' => false, 'message' => self::explain($me), 'sent' => 0, 'bot' => ''];
        }
        $bot = (string) ($me['result']['username'] ?? '');
        $sent = 0;
        $errors = [];
        foreach ($chats as $chat) {
            try {
                $r = self::call('sendMessage', ['chat_id' => $chat, 'text' => self::clip($html), 'parse_mode' => 'HTML', 'disable_web_page_preview' => true], 15, $token);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                continue;
            }
            if (!empty($r['ok'])) {
                $sent++;
            } else {
                $why = self::explain($r);
                $l = strtolower((string) ($r['description'] ?? ''));
                if ($bot !== '' && (str_contains($l, 'chat not found') || str_contains($l, "can't initiate") || str_contains($l, 'bot was blocked'))) {
                    // ما يجب فعله بالضبط: فتح هذا البوت من الحساب نفسه والضغط على ابدأ
                    $why .= ' افتح @' . $bot . ' من حساب تيليجرام صاحب المعرّف ' . $chat . ' واضغط ابدأ (Start)، أو أرسل له /id ليرد بمعرّفك الصحيح.';
                }
                $errors[] = (count($chats) > 1 ? 'المحادثة ' . $chat . ': ' : '') . $why;
            }
        }
        if ($sent === 0) {
            return ['ok' => false, 'message' => implode(' ', array_unique($errors)), 'sent' => 0, 'bot' => $bot];
        }
        $msg = $sent === count($chats) ? 'تم الإرسال.' : 'تم الإرسال إلى ' . $sent . ' من ' . count($chats) . '. ' . implode(' ', array_unique($errors));
        return ['ok' => true, 'message' => $msg, 'sent' => $sent, 'bot' => $bot];
    }

    /** معرّفات المحادثات من نص (مفصولة بفواصل أو مسافات) */
    public static function parseChats(string $raw): array
    {
        $ids = preg_split('/[\s,،;]+/u', latin_digits($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter($ids, static fn ($id) => (bool) preg_match('/^-?\d{3,20}$|^@\w{4,}$/', $id))));
    }

    /** رمز بوت بصيغة صحيحة (يقبل الرمز ملصوقاً مع كلمة bot أو مسافات) أو فارغ */
    public static function cleanToken(string $raw): string
    {
        $t = preg_replace('/\s+/', '', $raw) ?? '';
        if (str_starts_with(strtolower($t), 'bot') && preg_match('/^bot\d/i', $t)) {
            $t = substr($t, 3);
        }
        return preg_match('/^\d{5,15}:[A-Za-z0-9_-]{20,80}$/', $t) ? $t : '';
    }

    /**
     * الرمز المكتوب في نموذج قبل الحفظ: '' إن كان الحقل فارغاً (يُستعمل المحفوظ)، و null إن كانت صيغته خاطئة.
     */
    public static function typedToken(string $raw): ?string
    {
        if (trim($raw) === '') {
            return '';
        }
        $t = self::cleanToken($raw);
        return $t !== '' ? $t : null;
    }

    /**
     * المحادثات التي راسلت البوت مؤخراً، لتعبئة معرّف المحادثة تلقائياً.
     * لا تؤكّد قراءة الرسائل، فلا تؤثر على استقبال البوت لها.
     * @param list<array{id:string,name:string,type:string}> $seen محادثات سجّلها النظام نفسه (عند استقبال البوت برابط Webhook)
     * @return array{ok:bool, message:string, bot:string, chats:list<array{id:string,name:string,type:string}>}
     */
    public static function recentChats(string $token, array $seen = []): array
    {
        if ($token === '') {
            return ['ok' => false, 'message' => 'أدخل رمز البوت.', 'bot' => '', 'chats' => []];
        }
        try {
            $me = self::call('getMe', [], 10, $token);
            if (empty($me['ok'])) {
                return ['ok' => false, 'message' => self::explain($me), 'bot' => '', 'chats' => []];
            }
            $r = self::call('getUpdates', ['limit' => 100, 'timeout' => 0, 'allowed_updates' => ['message', 'channel_post', 'my_chat_member']], 12, $token);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'bot' => '', 'chats' => []];
        }
        $bot = (string) ($me['result']['username'] ?? '');
        $chats = [];
        $add = static function (array $c) use (&$chats): void {
            $id = (string) ($c['id'] ?? '');
            if ($id === '' || isset($chats[$id])) {
                return;
            }
            $name = trim((string) ($c['name'] ?? $c['title'] ?? trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))));
            if ($name === '' && !empty($c['username'])) {
                $name = '@' . $c['username'];
            }
            $chats[$id] = ['id' => $id, 'name' => $name !== '' ? $name : $id, 'type' => (string) ($c['type'] ?? 'private')];
        };
        if (!empty($r['ok'])) {
            foreach (array_reverse((array) ($r['result'] ?? [])) as $u) {
                foreach (['message', 'channel_post', 'my_chat_member', 'edited_message'] as $k) {
                    if (isset($u[$k]['chat']) && is_array($u[$k]['chat'])) {
                        $add($u[$k]['chat']);
                    }
                }
            }
        } elseif ((int) ($r['error_code'] ?? 0) !== 409 || !$seen) {
            return ['ok' => false, 'message' => self::explain($r), 'bot' => $bot, 'chats' => []];
        }
        foreach ($seen as $c) {
            $add($c);
        }
        $list = array_values($chats);
        return [
            'ok' => true, 'bot' => $bot, 'chats' => $list,
            'message' => $list ? 'وُجدت ' . ar_plural(count($list), 'محادثة واحدة', 'محادثتان', 'محادثات', 'محادثة') . '.' : 'لا توجد رسائل حديثة: أرسل أي رسالة إلى البوت' . ($bot !== '' ? ' @' . $bot : '') . ' من تيليجرام ثم أعد المحاولة.',
        ];
    }

    /**
     * مشكلة في إيصال تيليجرام للرسائل إلى النظام (عنوان الاستقبال) إن وُجدت.
     * @param string $mode webhook | poll
     */
    public static function webhookProblem(string $token, string $mode): ?string
    {
        try {
            $info = self::call('getWebhookInfo', [], 10, $token);
        } catch (Throwable) {
            return null;
        }
        $w = (array) ($info['result'] ?? []);
        $url = (string) ($w['url'] ?? '');
        if ($mode === 'webhook' && $url === '') {
            return 'الأوامر غير مربوطة بالنظام: اضغط «ربط الأوامر».';
        }
        if ($mode !== 'webhook' && $url !== '') {
            return 'البوت مربوط بعنوان استقبال آخر، فلن يرد على الأوامر: اضغط «ربط الأوامر».';
        }
        $at = (int) ($w['last_error_date'] ?? 0);
        if ($url !== '' && $at > time() - 86400 && ($w['last_error_message'] ?? '') !== '') {
            return 'تيليجرام لا يستطيع إيصال الرسائل إلى النظام: ' . (string) $w['last_error_message'];
        }
        return null;
    }

    /**
     * إرسال ملف (حتى 50 ميغابايت) إلى محادثة.
     * @return array{ok:bool, description?:string}
     */
    public static function sendDocument(string $token, string|int $chatId, string $path, string $name, string $caption = '', int $timeout = 300): array
    {
        if ($token === '' || !is_file($path)) {
            throw new RuntimeException('أدخل رمز البوت.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('إرسال الملفات يتطلب إضافة cURL.');
        }
        $ch = curl_init(self::base() . '/bot' . $token . '/sendDocument');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS => ['chat_id' => (string) $chatId, 'caption' => self::clip($caption), 'document' => new \CURLFile($path, 'application/octet-stream', $name)],
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        unset($ch);
        if ($body === false) {
            throw new RuntimeException('تعذّر الاتصال بتيليجرام: ' . $err);
        }
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new RuntimeException('رد غير متوقع من تيليجرام.');
        }
        return $data;
    }

    /** الحد الأقصى لطول الرسالة 4096 حرفاً */
    private static function clip(string $html): string
    {
        return mb_strlen($html) > 4000 ? mb_substr($html, 0, 3990) . "\n…" : $html;
    }

    public static function esc(string|int|float|null $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
