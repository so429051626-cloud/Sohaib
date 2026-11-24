<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Brand;
use App\Core\Platform;
use App\Core\Server;
use Throwable;

/**
 * إشعارات مسؤول المنصة على تيليجرام: تسجيل جديد، تحويل بانتظار الموافقة، انتهاء حساب، بلوغ الحد، تذكرة دعم.
 * تعمل من أي سياق (المنصة أو حساب زبون) لأن الإعدادات في قاعدة المنصة والرمز مشفّر بمفتاحها.
 */
final class AdminNotify
{
    public const EVENTS = [
        'signup'   => 'تسجيل حساب جديد',
        'transfer' => 'تحويل بانتظار الموافقة',
        'expired'  => 'انتهاء حساب',
        'limit'    => 'حساب وصل إلى حدّه',
        'ticket'   => 'تذكرة دعم جديدة',
        'bot'      => 'محادثة بوت تحتاج رداً',
        'weekly'   => 'التقرير الأسبوعي',
    ];

    public static function token(): string
    {
        $t = Platform::secret('notify.token');
        if ($t === '' && Server::managed()) {
            $t = Platform::unseal(Platform::setting('server.tg_token', ''));
        }
        return $t;
    }

    public static function hasOwnToken(): bool
    {
        return Platform::secret('notify.token') !== '';
    }

    /** @return list<string> */
    public static function chats(): array
    {
        $raw = Platform::setting('notify.chat');
        if (trim($raw) === '' && Server::managed()) {
            $raw = Platform::setting('server.tg_chat', '');
        }
        $ids = preg_split('/[\s,،;]+/u', latin_digits($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter($ids, static fn ($id) => (bool) preg_match('/^-?\d{3,20}$|^@\w{4,}$/', $id))));
    }

    public static function configured(): bool
    {
        return Platform::enabled() && self::token() !== '' && self::chats() !== [];
    }

    public static function on(string $event): bool
    {
        return isset(self::EVENTS[$event]) && Platform::setting('notify.' . $event) === '1';
    }

    /** إرسال إشعار حدث إن كان مفعّلاً؛ لا يرمي استثناءً أبداً */
    public static function send(string $event, string $html): bool
    {
        try {
            if (!self::configured() || !self::on($event)) {
                return false;
            }
            return self::push('<b>' . Telegram::esc(Brand::NAME) . ' · ' . Telegram::esc(self::EVENTS[$event]) . '</b>' . "\n" . $html);
        } catch (Throwable $e) {
            log_message('warning', 'admin notify: ' . $e->getMessage());
            return false;
        }
    }

    public static function push(string $html): bool
    {
        $ok = false;
        $token = self::token();
        foreach (self::chats() as $chat) {
            try {
                $r = Telegram::call('sendMessage', ['chat_id' => $chat, 'text' => mb_strlen($html) > 4000 ? mb_substr($html, 0, 3990) . "\n…" : $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true], 15, $token);
                $ok = $ok || !empty($r['ok']);
            } catch (Throwable $e) {
                log_message('warning', 'admin notify: ' . str_replace($token, '***', $e->getMessage()));
            }
        }
        return $ok;
    }

    /**
     * رسالة تجريبية بالرمز والمحادثات المحفوظة، أو بما كُتب في النموذج قبل الحفظ.
     * @return array{ok:bool, message:string}
     */
    public static function test(?string $token = null, ?string $chats = null): array
    {
        $token = $token !== null && $token !== '' ? $token : self::token();
        $list = $chats !== null && trim($chats) !== '' ? Telegram::parseChats($chats) : self::chats();
        if ($chats !== null && trim($chats) !== '' && !$list) {
            return ['ok' => false, 'message' => 'معرّف المحادثة غير صالح: يكون أرقاماً مثل 123456789 أو -1001234567890.'];
        }
        $r = Telegram::deliver($token, $list, '✅ <b>' . Telegram::esc(Brand::NAME) . '</b>' . "\n" . 'إشعارات المسؤول تعمل.');
        return ['ok' => $r['ok'], 'message' => str_replace($token, '***', $r['message'])];
    }
}
