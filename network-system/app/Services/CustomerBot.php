<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Settings;
use App\Core\Throttle;

/**
 * بوت الزبائن: فحص رصيد الكروت، ربط حساب المشترك ومتابعته، والتذكير بالانتهاء.
 */
final class CustomerBot
{
    private const KEYBOARD = [
        ['رصيد كرت', 'اشتراكي'],
        ['ربط حسابي', 'تواصل معنا'],
    ];

    private const WORDS = [
        'menu'    => ['/start', '/menu', 'القائمة', 'ابدأ', 'مرحبا', 'السلام عليكم'],
        'card'    => ['/card', 'رصيد كرت', 'رصيد', 'فحص كرت'],
        'me'      => ['/me', 'اشتراكي', 'حسابي'],
        'link'    => ['/link', 'ربط حسابي', 'ربط'],
        'unlink'  => ['/unlink', 'إلغاء الربط', 'الغاء الربط'],
        'contact' => ['/contact', 'تواصل معنا', 'تواصل'],
    ];

    public static function enabled(): bool
    {
        return Settings::bool('telegram.customers') && (Telegram::customerToken() !== '' || Telegram::token() !== '');
    }

    public static function handle(string $chat, string $text, array $msg = []): void
    {
        $clean = trim(preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', '', $text) ?? $text);
        $cmd = self::command($clean);
        $kb = ['reply_markup' => ['keyboard' => self::KEYBOARD, 'resize_keyboard' => true, 'is_persistent' => true]];
        switch ($cmd) {
            case 'menu':
                Telegram::send($chat, self::welcome(), $kb);
                return;
            case 'card':
                $arg = self::argument($clean, 'card');
                if ($arg === '') {
                    Telegram::send($chat, 'أرسل رقم الكرت أو الرقم المختصر.');
                    return;
                }
                $clean = $arg;
                break;
            case 'me':
                Telegram::send($chat, self::status($chat), $kb);
                return;
            case 'link':
                Telegram::send($chat, 'أرسل اسم المستخدم وكلمة المرور في رسالة واحدة يفصل بينهما مسافة.');
                return;
            case 'unlink':
                $n = DB::query('DELETE FROM telegram_links WHERE chat_id = ?', [$chat])->rowCount();
                Telegram::send($chat, $n ? 'تم إلغاء ربط الحساب.' : 'لا يوجد حساب مربوط.', $kb);
                return;
            case 'contact':
                Telegram::send($chat, self::contact(), $kb);
                return;
        }

        $key = 'tgc:' . $chat;
        $wait = Throttle::lockedFor($key);
        if ($wait > 0) {
            Telegram::send($chat, 'تم إيقاف المحاولات مؤقتاً. حاول بعد ' . max(1, (int) ceil($wait / 60)) . ' دقيقة.');
            return;
        }
        $parts = preg_split('/\s+/u', trim(latin_digits($clean)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$parts || count($parts) > 2 || mb_strlen($clean) > 80) {
            Telegram::send($chat, self::welcome(), $kb);
            return;
        }
        $code = PortalService::normalizeCode($parts[0]);
        $pass = $parts[1] ?? '';
        $owner = LoginRegistry::owner($code);

        // ربط حساب مشترك: اسم المستخدم + كلمة المرور
        if ($owner && $owner[0] === 'subscriber') {
            if ($pass === '') {
                Telegram::send($chat, 'أرسل اسم المستخدم وكلمة المرور في رسالة واحدة يفصل بينهما مسافة.');
                return;
            }
            $sub = DB::row('SELECT * FROM subscribers WHERE id = ?', [$owner[1]]);
            self::deleteMessage($chat, $msg);
            if (!$sub || !hash_equals((string) $sub['password'], $pass)) {
                Throttle::fail($key, 5, 900, 1800);
                Telegram::send($chat, 'اسم المستخدم أو كلمة المرور غير صحيحة.');
                return;
            }
            Throttle::clear($key);
            DB::query('INSERT IGNORE INTO telegram_links (chat_id, subscriber_id, linked_at) VALUES (?, ?, ?)', [$chat, $sub['id'], now()]);
            Telegram::send($chat, "تم ربط حسابك بنجاح.\n\n" . self::status($chat), $kb);
            return;
        }

        // فحص كرت
        if ($owner && in_array($owner[0], ['card', 'card_short'], true)) {
            $card = DB::row('SELECT * FROM cards WHERE id = ?', [$owner[1]]);
            if ($card && $owner[0] === 'card_short' && (string) $card['short_password'] !== '') {
                if ($pass === '') {
                    Telegram::send($chat, 'أرسل الرقم المختصر وكلمة المرور في رسالة واحدة.');
                    return;
                }
                if (!hash_equals((string) $card['short_password'], $pass) && !hash_equals((string) $card['password'], $pass)) {
                    Throttle::fail($key, 8, 900, 1800);
                    Telegram::send($chat, 'الرقم أو كلمة المرور غير صحيحة.');
                    return;
                }
            }
            if ($card) {
                Telegram::send($chat, self::cardInfo($card), $kb);
                return;
            }
        }
        Throttle::fail($key, 8, 900, 1800);
        Telegram::send($chat, 'لا يوجد كرت أو حساب بهذا الرقم.');
    }

    private static function command(string $text): string
    {
        $lower = mb_strtolower($text);
        foreach (self::WORDS as $cmd => $words) {
            foreach ($words as $w) {
                if ($lower === $w || str_starts_with($lower, $w . ' ') || str_starts_with($lower, $w . '@')) {
                    return $cmd;
                }
            }
        }
        return '';
    }

    private static function argument(string $text, string $cmd): string
    {
        foreach (self::WORDS[$cmd] as $w) {
            if (str_starts_with(mb_strtolower($text), $w . ' ')) {
                return trim(mb_substr($text, mb_strlen($w)));
            }
        }
        return '';
    }

    private static function deleteMessage(string $chat, array $msg): void
    {
        if (isset($msg['message_id'])) {
            try {
                Telegram::call('deleteMessage', ['chat_id' => $chat, 'message_id' => $msg['message_id']], 5);
            } catch (\Throwable) {
                // ليس حرجاً
            }
        }
    }

    private static function welcome(): string
    {
        return '<b>' . Telegram::esc(Settings::networkName()) . "</b>\n"
            . "أهلاً بك في خدمة الزبائن.\n\n"
            . "- رصيد كرت: أرسل رقم الكرت مباشرة.\n"
            . "- اشتراكي: حالة اشتراكك بعد ربط حسابك.\n"
            . '- ربط حسابي: باسم المستخدم وكلمة المرور.';
    }

    private static function contact(): string
    {
        $lines = ['<b>' . Telegram::esc(Settings::networkName()) . '</b>'];
        foreach (['general.phone' => 'الهاتف', 'general.whatsapp' => 'واتساب', 'general.address' => 'العنوان'] as $k => $label) {
            $v = trim((string) Settings::get($k, ''));
            if ($v !== '') {
                $lines[] = '- ' . $label . ': ' . ($k === 'general.address' ? Telegram::esc($v) : '<code>' . Telegram::esc($v) . '</code>');
            }
        }
        return count($lines) > 1 ? implode("\n", $lines) : $lines[0];
    }

    public static function cardInfo(array $card): string
    {
        $cat = DB::row('SELECT * FROM categories WHERE id = ?', [$card['category_id']]);
        $info = PortalService::cardInfo($card, $cat);
        $state = CardRules::lifecycleStatus($card, $cat);
        $states = ['new' => 'جديد لم يُستخدم', 'sold' => 'جديد لم يُستخدم', 'active' => 'فعّال', 'expired' => 'منتهي الصلاحية', 'depleted' => 'نفد الرصيد'];
        $lines = [
            '<b>الكرت ' . Telegram::esc($card['username']) . '</b>',
            '- الباقة: ' . Telegram::esc($info['category']),
            '- الحالة: ' . ($states[$state] ?? Telegram::esc($state)) . ((int) $card['is_disabled'] ? ' (موقوف)' : ''),
        ];
        if ($info['remaining_time'] !== null) {
            $lines[] = '- الوقت المتبقي: ' . Telegram::esc(fmt_duration((int) $info['remaining_time']));
        }
        if ($info['remaining_data'] !== null) {
            $lines[] = '- البيانات المتبقية: ' . Telegram::esc(fmt_bytes((int) $info['remaining_data']));
        }
        if ($info['expires_at']) {
            $lines[] = '- ينتهي في: ' . Telegram::esc(fmt_dt($info['expires_at']));
        }
        if ($info['used_time'] > 0 || $info['used_data'] > 0) {
            $lines[] = '- المستخدم: ' . Telegram::esc(fmt_duration($info['used_time'])) . '، ' . Telegram::esc(fmt_bytes($info['used_data']));
        }
        if ($info['speed'] !== '') {
            $lines[] = '- السرعة: ' . Telegram::esc($info['speed']);
        }
        return implode("\n", $lines);
    }

    /** حالة الحسابات المربوطة بالمحادثة */
    public static function status(string $chat): string
    {
        $subs = DB::rows('SELECT s.*, p.name AS plan, p.data_limit FROM telegram_links l JOIN subscribers s ON s.id = l.subscriber_id JOIN plans p ON p.id = s.plan_id
                          WHERE l.chat_id = ? ORDER BY s.full_name', [$chat]);
        if (!$subs) {
            return "لا يوجد حساب مربوط بهذه المحادثة.\nأرسل اسم المستخدم وكلمة المرور في رسالة واحدة لربط حسابك.";
        }
        $states = ['active' => 'فعّال', 'expiring' => 'ينتهي قريباً', 'expired' => 'منتهي', 'suspended' => 'موقوف', 'new' => 'بانتظار التفعيل'];
        $out = [];
        foreach ($subs as $s) {
            $lines = ['<b>' . Telegram::esc($s['full_name']) . '</b> — <code>' . Telegram::esc($s['username']) . '</code>'];
            $lines[] = '- الحالة: ' . ($states[SubscriberRules::displayState($s)] ?? '');
            $lines[] = '- الباقة: ' . Telegram::esc($s['plan']);
            if ($s['expires_at']) {
                $left = (int) strtotime($s['expires_at']) - time();
                $lines[] = '- ينتهي في: ' . Telegram::esc(fmt_dt($s['expires_at'])) . ($left > 0 ? ' (بعد ' . Telegram::esc(fmt_duration($left)) . ')' : '');
            }
            if ($s['data_limit']) {
                $lines[] = '- الاستهلاك: ' . Telegram::esc(fmt_bytes((int) $s['period_bytes'])) . ' من ' . Telegram::esc(fmt_bytes((int) $s['data_limit']));
            }
            if ((float) $s['balance'] > 0) {
                $lines[] = '- المستحق: ' . Telegram::esc(money($s['balance']));
            }
            $out[] = implode("\n", $lines);
        }
        return implode("\n\n", $out);
    }

    // ------------------------------------------------------------------
    // الإشعارات للمشتركين
    // ------------------------------------------------------------------

    private static function token(): string
    {
        return Telegram::customerToken() !== '' ? Telegram::customerToken() : Telegram::token();
    }

    /** إرسال باستخدام بوت الزبائن */
    public static function notify(string $chat, string $html): bool
    {
        $token = self::token();
        return $token !== '' && Telegram::using($token, static fn () => Telegram::send($chat, $html));
    }

    /** تذكير قبل الانتهاء وإشعار عند الانتهاء (من المهمة المجدولة) */
    public static function reminders(): int
    {
        if (!Settings::bool('telegram.customers') || !Settings::bool('telegram.remind') || self::token() === '') {
            return 0;
        }
        $days = max(1, Settings::int('telegram.remind_days', 3));
        $sent = 0;
        $soon = DB::rows(
            "SELECT l.id AS link_id, l.chat_id, s.full_name, s.expires_at, p.name AS plan FROM telegram_links l JOIN subscribers s ON s.id = l.subscriber_id JOIN plans p ON p.id = s.plan_id
             WHERE s.status = 'active' AND s.expires_at > ? AND s.expires_at <= ? AND (l.reminded_for IS NULL OR l.reminded_for <> s.expires_at) LIMIT 200",
            [now(), date('Y-m-d H:i:s', time() + $days * 86400)]
        );
        foreach ($soon as $r) {
            DB::update('telegram_links', ['reminded_for' => $r['expires_at']], 'id = ?', [$r['link_id']]);
            $ok = self::notify($r['chat_id'], '<b>تذكير بانتهاء الاشتراك</b>' . "\n"
                . 'عزيزنا ' . Telegram::esc($r['full_name']) . '، ينتهي اشتراكك في باقة ' . Telegram::esc($r['plan']) . ' بتاريخ ' . Telegram::esc(fmt_dt($r['expires_at'])) . ".\n"
                . 'للتجديد يرجى التواصل مع ' . Telegram::esc(Settings::networkName()) . '.');
            $sent += $ok ? 1 : 0;
        }
        $expired = DB::rows(
            "SELECT l.id AS link_id, l.chat_id, s.full_name, s.expires_at FROM telegram_links l JOIN subscribers s ON s.id = l.subscriber_id
             WHERE s.expires_at IS NOT NULL AND s.expires_at <= ? AND s.expires_at > ? AND (l.expired_for IS NULL OR l.expired_for <> s.expires_at) LIMIT 200",
            [now(), date('Y-m-d H:i:s', time() - 2 * 86400)]
        );
        foreach ($expired as $r) {
            DB::update('telegram_links', ['expired_for' => $r['expires_at']], 'id = ?', [$r['link_id']]);
            $ok = self::notify($r['chat_id'], '<b>انتهى الاشتراك</b>' . "\n"
                . 'عزيزنا ' . Telegram::esc($r['full_name']) . '، انتهى اشتراكك بتاريخ ' . Telegram::esc(fmt_dt($r['expires_at'])) . ".\n"
                . 'يرجى التجديد لاستمرار الخدمة.');
            $sent += $ok ? 1 : 0;
        }
        return $sent;
    }

    /** تأكيد التجديد للمشترك */
    public static function renewed(int $subscriberId, string $expiresAt): void
    {
        if (!Settings::bool('telegram.customers') || self::token() === '') {
            return;
        }
        $sub = DB::row('SELECT s.full_name, p.name AS plan FROM subscribers s JOIN plans p ON p.id = s.plan_id WHERE s.id = ?', [$subscriberId]);
        if (!$sub) {
            return;
        }
        foreach (DB::column('SELECT chat_id FROM telegram_links WHERE subscriber_id = ?', [$subscriberId]) as $chat) {
            self::notify((string) $chat, '<b>تم تجديد الاشتراك</b>' . "\n"
                . 'عزيزنا ' . Telegram::esc($sub['full_name']) . '، تم تجديد اشتراكك في باقة ' . Telegram::esc($sub['plan']) . ' حتى ' . Telegram::esc(fmt_dt($expiresAt)) . ".\n"
                . 'شكراً لثقتك بـ ' . Telegram::esc(Settings::networkName()) . '.');
        }
    }
}
