<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Settings;
use Throwable;

/**
 * بوت تيليجرام: الأوامر، التقارير المجدولة، واستقبال الرسائل (Webhook أو سحب دوري).
 */
final class TelegramBot
{
    /** الأمر => [الوصف، الكلمات العربية المقابلة] */
    public const COMMANDS = [
        'today'    => ['تقرير اليوم', ['اليوم', 'تقرير اليوم']],
        'yesterday' => ['تقرير أمس', ['أمس', 'امس']],
        'week'     => ['تقرير آخر 7 أيام', ['الأسبوع', 'الاسبوع']],
        'month'    => ['تقرير هذا الشهر', ['الشهر']],
        'routers'  => ['حالة أجهزة MikroTik', ['أجهزة MikroTik', 'الراوترات']],
        'online'   => ['المتصلون الآن', ['المتصلون', 'المتصلين']],
        'expiring' => ['اشتراكات تنتهي قريباً', ['ينتهون', 'المنتهون قريبا']],
        'stock'    => ['المخزون المنخفض', ['المخزون']],
        'alerts'   => ['آخر التنبيهات', ['التنبيهات']],
        'card'     => ['فحص كرت برقمه أو رقمه المختصر', ['كرت', 'فحص']],
        'sub'      => ['بحث عن مشترك بالاسم أو الهاتف', ['مشترك']],
        'help'     => ['قائمة الأوامر', ['مساعدة', 'الأوامر', 'القائمة']],
    ];

    private const KEYBOARD = [
        ['اليوم', 'الأسبوع', 'الشهر'],
        ['أجهزة MikroTik', 'المتصلون', 'ينتهون'],
        ['المخزون', 'التنبيهات'],
    ];

    // ------------------------------------------------------------------
    // استقبال التحديثات
    // ------------------------------------------------------------------

    /**
     * @param bool $customerBot التحديث وارد من بوت الزبائن المنفصل
     */
    public static function handle(array $update, bool $customerBot = false): void
    {
        $msg = $update['message'] ?? null;
        if (!is_array($msg) || !isset($msg['chat']['id']) || ($msg['chat']['type'] ?? 'private') === 'channel') {
            return;
        }
        $chat = (string) $msg['chat']['id'];
        $text = trim((string) ($msg['text'] ?? ''));
        if ($text === '') {
            return;
        }
        if ($customerBot) {
            if (CustomerBot::enabled()) {
                CustomerBot::handle($chat, $text, $msg);
            }
            return;
        }
        [$cmd, $arg] = self::parse($text);
        self::remember($msg['chat']);
        if ($cmd === 'id') {
            Telegram::send($chat, 'معرّف هذه المحادثة: <code>' . Telegram::esc($chat) . '</code>');
            return;
        }
        if (!Telegram::allowed($chat)) {
            if (CustomerBot::enabled() && Telegram::customerToken() === '') {
                CustomerBot::handle($chat, $text, $msg);
                return;
            }
            Telegram::send($chat, "هذه المحادثة غير مصرّح لها.\nالمعرّف: <code>" . Telegram::esc($chat) . '</code>');
            return;
        }
        if (!Settings::bool('telegram.commands')) {
            return;
        }
        try {
            $reply = self::run($cmd, $arg);
        } catch (Throwable $e) {
            log_message('error', 'telegram command: ' . $e->getMessage());
            $reply = 'تعذّر تنفيذ الطلب.';
        }
        Telegram::send($chat, $reply, $cmd === 'start' || $cmd === 'help' ? ['reply_markup' => ['keyboard' => self::KEYBOARD, 'resize_keyboard' => true, 'is_persistent' => true]] : []);
    }

    /** @return array{0:string,1:string} */
    public static function parse(string $text): array
    {
        $text = trim(preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', '', $text) ?? $text);
        if (str_starts_with($text, '/')) {
            $parts = preg_split('/\s+/u', mb_substr($text, 1), 2);
            $cmd = strtolower(explode('@', (string) $parts[0])[0]);
            return [$cmd, trim((string) ($parts[1] ?? ''))];
        }
        foreach (self::COMMANDS as $cmd => [, $words]) {
            foreach ($words as $w) {
                if ($text === $w || str_starts_with($text, $w . ' ')) {
                    return [$cmd, trim(mb_substr($text, mb_strlen($w)))];
                }
            }
        }
        return ['', $text];
    }

    public static function run(string $cmd, string $arg): string
    {
        $today = date('Y-m-d');
        return match ($cmd) {
            'start', 'help', '' => self::help(),
            'today'     => Digest::telegram('تقرير اليوم', $today, $today),
            'yesterday' => Digest::telegram('تقرير أمس', date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))),
            'week'      => Digest::telegram('تقرير آخر 7 أيام', date('Y-m-d', strtotime('-6 days')), $today),
            'month'     => Digest::telegram('تقرير الشهر', date('Y-m-01'), $today),
            'routers'   => self::routers(),
            'online'    => self::online(),
            'expiring'  => self::expiring(),
            'stock'     => self::stock(),
            'alerts'    => self::alerts(),
            'card'      => self::card($arg),
            'sub'       => self::subscriber($arg),
            default     => self::help(),
        };
    }

    private static function help(): string
    {
        $lines = ['<b>' . Telegram::esc(Settings::networkName()) . '</b> — قائمة الأوامر', ''];
        foreach (self::COMMANDS as $cmd => [$label]) {
            $lines[] = '/' . $cmd . ' — ' . Telegram::esc($label);
        }
        return implode("\n", $lines);
    }

    private static function routers(): string
    {
        $rows = DB::rows('SELECT name, status, cpu_load, active_hotspot, active_ppp, last_seen_at, uptime_seconds FROM routers WHERE is_active = 1 ORDER BY status = \'online\', name');
        if (!$rows) {
            return 'لا توجد أجهزة MikroTik.';
        }
        $online = count(array_filter($rows, static fn ($r) => $r['status'] === 'online'));
        $lines = ['<b>حالة أجهزة MikroTik</b> — المتصلة ' . num($online) . ' من ' . num(count($rows)), ''];
        $labels = ['online' => 'متصل', 'offline' => 'منقطع', 'unknown' => 'غير معروف'];
        foreach ($rows as $r) {
            $info = $r['status'] === 'online'
                ? num((int) $r['active_hotspot'] + (int) $r['active_ppp']) . ' مستخدم' . ($r['cpu_load'] !== null ? '، المعالج ' . (int) $r['cpu_load'] . '%' : '')
                : 'آخر ظهور: ' . ($r['last_seen_at'] ? fmt_dt($r['last_seen_at']) : '—');
            $lines[] = '- <b>' . Telegram::esc($r['name']) . '</b>: ' . ($labels[$r['status']] ?? $r['status']) . ' — ' . Telegram::esc($info);
        }
        return implode("\n", $lines);
    }

    private static function online(): string
    {
        $total = (int) DB::value('SELECT COUNT(*) FROM sessions WHERE stopped_at IS NULL');
        $by = DB::rows("SELECT r.name, SUM(x.service = 'hotspot') AS hs, SUM(x.service = 'ppp') AS ppp FROM sessions x JOIN routers r ON r.id = x.router_id
                        WHERE x.stopped_at IS NULL GROUP BY r.id, r.name ORDER BY COUNT(*) DESC LIMIT 15");
        $lines = ['<b>المتصلون الآن:</b> ' . num($total)];
        if ($by) {
            $lines[] = '';
            foreach ($by as $r) {
                $lines[] = '- ' . Telegram::esc($r['name']) . ': ' . num((int) $r['hs']) . ' هوتسبوت، ' . num((int) $r['ppp']) . ' PPP';
            }
        }
        return implode("\n", $lines);
    }

    private static function expiring(): string
    {
        $days = max(1, Settings::int('subscribers.expiry_alert_days', 3));
        $rows = DB::rows("SELECT full_name, phone, expires_at FROM subscribers WHERE status = 'active' AND expires_at > ? AND expires_at <= ? ORDER BY expires_at LIMIT 30",
            [now(), date('Y-m-d H:i:s', time() + $days * 86400)]);
        if (!$rows) {
            return 'لا توجد اشتراكات تنتهي خلال ' . num($days) . ' أيام.';
        }
        $lines = ['<b>اشتراكات تنتهي خلال ' . num($days) . ' أيام</b> (' . num(count($rows)) . ')', ''];
        foreach ($rows as $r) {
            $lines[] = '- ' . Telegram::esc($r['full_name']) . ($r['phone'] ? '، <code>' . Telegram::esc($r['phone']) . '</code>' : '') . ' — ' . Telegram::esc(fmt_dt($r['expires_at']));
        }
        return implode("\n", $lines);
    }

    private static function stock(): string
    {
        $s = Inventory::summary();
        $lines = ['<b>المخزون</b>', '- الأصناف: ' . num($s['items']) . '، القيمة: ' . Telegram::esc(money($s['value'])), '- الأجهزة المركّبة: ' . num($s['installed']) . '، التالفة: ' . num($s['faulty'])];
        $low = Inventory::lowItems(20);
        if ($low) {
            $lines[] = '';
            $lines[] = '<b>أصناف منخفضة</b>';
            foreach ($low as $i) {
                $lines[] = '- ' . Telegram::esc($i['name']) . ': ' . Telegram::esc(Inventory::fmtQty($i['quantity'])) . ' ' . Telegram::esc(Inventory::UNITS[$i['unit']] ?? '') . ' (الحد الأدنى ' . Telegram::esc(Inventory::fmtQty($i['min_qty'])) . ')';
            }
        }
        $cards = DB::rows("SELECT c.name, COUNT(k.id) AS n, c.low_stock FROM categories c LEFT JOIN cards k ON k.category_id = c.id AND k.status = 'new' AND k.is_disabled = 0
                           WHERE c.is_active = 1 AND c.is_custom = 0 GROUP BY c.id, c.name, c.low_stock ORDER BY c.sort, c.name");
        if ($cards) {
            $lines[] = '';
            $lines[] = '<b>الكروت المتاحة</b>';
            foreach ($cards as $c) {
                $warn = $c['low_stock'] !== null && (int) $c['n'] <= (int) $c['low_stock'] ? ' (منخفض)' : '';
                $lines[] = '- ' . Telegram::esc($c['name']) . ': ' . num((int) $c['n']) . $warn;
            }
        }
        return implode("\n", $lines);
    }

    private static function alerts(): string
    {
        $rows = DB::rows('SELECT title, message, level, created_at FROM alerts ORDER BY id DESC LIMIT 10');
        if (!$rows) {
            return 'لا توجد تنبيهات.';
        }
        $levels = ['danger' => 'حرج', 'warning' => 'تحذير', 'success' => 'تم', 'info' => 'معلومة'];
        $lines = ['<b>آخر التنبيهات</b>', ''];
        foreach ($rows as $a) {
            $lines[] = '- [' . ($levels[$a['level']] ?? $a['level']) . '] ' . Telegram::esc($a['title']) . ' — <i>' . Telegram::esc(fmt_dt($a['created_at'])) . '</i>';
        }
        return implode("\n", $lines);
    }

    private static function card(string $code): string
    {
        $code = trim(latin_digits($code));
        if ($code === '') {
            return 'أرسل: /card رقم_الكرت';
        }
        $c = DB::row('SELECT k.*, c.name AS category FROM cards k JOIN categories c ON c.id = k.category_id WHERE k.username = ? OR k.short_code = ? LIMIT 1', [$code, $code]);
        if (!$c) {
            return 'لا يوجد كرت بهذا الرقم.';
        }
        [$status] = CardRules::STATUS[$c['status']] ?? [$c['status']];
        $lines = [
            '<b>الكرت ' . Telegram::esc($c['username']) . '</b>' . ($c['short_code'] ? '، الرقم المختصر <code>' . Telegram::esc($c['short_code']) . '</code>' : ''),
            '- الباقة: ' . Telegram::esc($c['category']),
            '- الحالة: ' . Telegram::esc($status) . ((int) $c['is_disabled'] ? ' (موقوف)' : ''),
        ];
        if ($c['first_login_at']) {
            $lines[] = '- أول دخول: ' . Telegram::esc(fmt_dt($c['first_login_at']));
        }
        if ($c['expires_at']) {
            $lines[] = '- ينتهي: ' . Telegram::esc(fmt_dt($c['expires_at']));
        }
        $used = (int) $c['used_bytes_in'] + (int) $c['used_bytes_out'];
        if ((int) $c['used_seconds'] > 0 || $used > 0) {
            $lines[] = '- الاستخدام: ' . Telegram::esc(fmt_duration((int) $c['used_seconds'])) . '، ' . Telegram::esc(fmt_bytes($used));
        }
        if ($c['last_mac']) {
            $lines[] = '- آخر جهاز: <code>' . Telegram::esc($c['last_mac']) . '</code>';
        }
        return implode("\n", $lines);
    }

    private static function subscriber(string $q): string
    {
        $q = trim(latin_digits($q));
        if ($q === '') {
            return 'أرسل: /sub الاسم أو الهاتف أو اسم المستخدم';
        }
        $rows = DB::rows('SELECT s.*, p.name AS plan FROM subscribers s JOIN plans p ON p.id = s.plan_id
                          WHERE s.username = ? OR s.phone = ? OR s.full_name LIKE ? ORDER BY s.full_name LIMIT 5', [$q, $q, DB::like($q)]);
        if (!$rows) {
            return 'لا يوجد مشترك مطابق.';
        }
        $states = ['active' => 'فعّال', 'expiring' => 'ينتهي قريباً', 'expired' => 'منتهي', 'suspended' => 'موقوف', 'new' => 'بانتظار التفعيل'];
        $out = [];
        foreach ($rows as $s) {
            $online = (int) DB::value('SELECT COUNT(*) FROM sessions WHERE subscriber_id = ? AND stopped_at IS NULL', [$s['id']]);
            $out[] = implode("\n", array_filter([
                '<b>' . Telegram::esc($s['full_name']) . '</b> — <code>' . Telegram::esc($s['username']) . '</code>',
                '- الحالة: ' . ($states[SubscriberRules::displayState($s)] ?? '') . ($online ? '، متصل الآن' : ''),
                '- الباقة: ' . Telegram::esc($s['plan']),
                $s['expires_at'] ? '- ينتهي: ' . Telegram::esc(fmt_dt($s['expires_at'])) : null,
                $s['phone'] ? '- الهاتف: <code>' . Telegram::esc($s['phone']) . '</code>' : null,
                (float) $s['balance'] > 0 ? '- المستحق عليه: ' . Telegram::esc(money($s['balance'])) : null,
            ]));
        }
        return implode("\n\n", $out);
    }

    // ------------------------------------------------------------------
    // التقارير المجدولة
    // ------------------------------------------------------------------

    /** إرسال التقارير المستحقة (يُستدعى من المهمة المجدولة) */
    public static function scheduled(?int $now = null): array
    {
        if (!Telegram::enabled()) {
            return [];
        }
        $now ??= time();
        $sent = [];
        $hm = date('H:i', $now);
        $today = date('Y-m-d', $now);
        if (Settings::bool('telegram.report_daily') && $hm >= self::time('telegram.daily_time', '23:00') && Settings::get('telegram.last_daily', '') !== $today) {
            Settings::set('telegram.last_daily', $today);
            Telegram::broadcast(Digest::telegram('التقرير اليومي', $today, $today, true));
            $sent[] = 'daily';
        }
        $week = date('o-W', $now);
        if (Settings::bool('telegram.report_weekly') && (int) date('w', $now) === Settings::int('telegram.weekly_day', 6)
            && $hm >= self::time('telegram.weekly_time', '22:00') && Settings::get('telegram.last_weekly', '') !== $week) {
            Settings::set('telegram.last_weekly', $week);
            Telegram::broadcast(Digest::telegram('التقرير الأسبوعي', date('Y-m-d', $now - 6 * 86400), $today));
            $sent[] = 'weekly';
        }
        $month = date('Y-m', $now);
        if (Settings::bool('telegram.report_monthly') && (int) date('j', $now) === 1
            && $hm >= self::time('telegram.monthly_time', '09:00') && Settings::get('telegram.last_monthly', '') !== $month) {
            Settings::set('telegram.last_monthly', $month);
            $prev = strtotime('first day of last month', $now);
            Telegram::broadcast(Digest::telegram('التقرير الشهري · ' . date('Y-m', $prev), date('Y-m-01', $prev), date('Y-m-t', $prev)));
            $sent[] = 'monthly';
        }
        return $sent;
    }

    private static function time(string $key, string $default): string
    {
        $v = (string) Settings::get($key, $default);
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $default;
    }

    /** إرسال تقرير فوري من الإعدادات */
    public static function sendReport(string $type): array
    {
        $today = date('Y-m-d');
        $html = match ($type) {
            'weekly'  => Digest::telegram('التقرير الأسبوعي', date('Y-m-d', strtotime('-6 days')), $today),
            'monthly' => Digest::telegram('تقرير الشهر', date('Y-m-01'), $today),
            default   => Digest::telegram('التقرير اليومي', $today, $today, true),
        };
        return Telegram::broadcast($html);
    }

    // ------------------------------------------------------------------
    // الربط
    // ------------------------------------------------------------------

    /**
     * تهيئة استقبال الأوامر: Webhook عند توفر رابط HTTPS، وإلا السحب الدوري.
     * @return array{ok:bool, message:string, mode:string}
     */
    /** آخر المحادثات التي راسلت بوت الإدارة (لزر اكتشاف المعرّف) */
    public static function seenChats(): array
    {
        $list = json_decode((string) Settings::get('telegram.seen_chats', '[]'), true);
        return is_array($list) ? array_values(array_filter($list, static fn ($c) => is_array($c) && isset($c['id']) && (int) ($c['at'] ?? 0) > time() - 7 * 86400)) : [];
    }

    private static function remember(array $chat): void
    {
        $id = (string) ($chat['id'] ?? '');
        if ($id === '') {
            return;
        }
        $name = trim((string) ($chat['title'] ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''))));
        $list = array_values(array_filter(self::seenChats(), static fn ($c) => (string) $c['id'] !== $id));
        array_unshift($list, ['id' => $id, 'name' => mb_substr($name !== '' ? $name : $id, 0, 60), 'type' => (string) ($chat['type'] ?? 'private'), 'at' => time()]);
        Settings::set('telegram.seen_chats', json_encode(array_slice($list, 0, 5), JSON_UNESCAPED_UNICODE));
    }

    public static function connect(): array
    {
        $me = Telegram::call('getMe');
        if (!($me['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'رمز البوت غير صحيح.', 'mode' => ''];
        }
        Settings::set('telegram.bot_username', (string) ($me['result']['username'] ?? ''));
        $commands = [];
        foreach (self::COMMANDS as $cmd => [$label]) {
            $commands[] = ['command' => $cmd, 'description' => mb_substr($label, 0, 60)];
        }
        Telegram::call('setMyCommands', ['commands' => $commands]);

        $panel = rtrim((string) Settings::get('general.panel_url', ''), '/');
        if (str_starts_with($panel, 'https://')) {
            $secret = (string) Settings::get('telegram.secret', '');
            if ($secret === '') {
                $secret = bin2hex(random_bytes(16));
                Settings::set('telegram.secret', $secret);
            }
            $r = Telegram::call('setWebhook', [
                'url' => $panel . '/telegram/webhook/' . $secret, 'secret_token' => $secret,
                'allowed_updates' => ['message'], 'drop_pending_updates' => true,
            ]);
            if ($r['ok'] ?? false) {
                Settings::set('telegram.mode', 'webhook');
                return ['ok' => true, 'message' => 'تم ربط البوت @' . $me['result']['username'] . '.', 'mode' => 'webhook'];
            }
        }
        Telegram::call('deleteWebhook', ['drop_pending_updates' => false]);
        Settings::set('telegram.mode', 'poll');
        return ['ok' => true, 'message' => 'تم ربط البوت @' . $me['result']['username'] . '.', 'mode' => 'poll'];
    }

    /**
     * تهيئة بوت الزبائن المنفصل (إن وُجد).
     * @return array{ok:bool, message:string, mode:string}
     */
    public static function connectCustomer(): array
    {
        $token = Telegram::customerToken();
        if ($token === '') {
            return ['ok' => true, 'message' => '', 'mode' => 'shared'];
        }
        return Telegram::using($token, static function () {
            $me = Telegram::call('getMe');
            if (!($me['ok'] ?? false)) {
                return ['ok' => false, 'message' => 'رمز بوت الزبائن غير صحيح.', 'mode' => ''];
            }
            Settings::set('telegram.customer_username', (string) ($me['result']['username'] ?? ''));
            Telegram::call('setMyCommands', ['commands' => [
                ['command' => 'start', 'description' => 'القائمة'], ['command' => 'me', 'description' => 'حالة اشتراكي'],
                ['command' => 'link', 'description' => 'ربط حسابي'], ['command' => 'unlink', 'description' => 'إلغاء الربط'],
                ['command' => 'contact', 'description' => 'تواصل معنا'],
            ]]);
            $panel = rtrim((string) Settings::get('general.panel_url', ''), '/');
            $secret = (string) Settings::get('telegram.secret', '');
            if (str_starts_with($panel, 'https://') && $secret !== '') {
                $r = Telegram::call('setWebhook', ['url' => $panel . '/telegram/customer/' . $secret, 'secret_token' => $secret, 'allowed_updates' => ['message'], 'drop_pending_updates' => true]);
                if ($r['ok'] ?? false) {
                    Settings::set('telegram.customer_mode', 'webhook');
                    return ['ok' => true, 'message' => '@' . $me['result']['username'], 'mode' => 'webhook'];
                }
            }
            Telegram::call('deleteWebhook', ['drop_pending_updates' => false]);
            Settings::set('telegram.customer_mode', 'poll');
            return ['ok' => true, 'message' => '@' . $me['result']['username'], 'mode' => 'poll'];
        });
    }

    /** سحب رسائل بوت الزبائن المنفصل */
    public static function pollCustomer(): int
    {
        $token = Telegram::customerToken();
        if ($token === '' || !CustomerBot::enabled() || Settings::get('telegram.customer_mode', 'poll') === 'webhook') {
            return 0;
        }
        return Telegram::using($token, static function () {
            $offset = Settings::int('telegram.customer_offset', 0);
            $r = Telegram::call('getUpdates', ['offset' => $offset, 'timeout' => 0, 'limit' => 50, 'allowed_updates' => ['message']], 12);
            $n = 0;
            foreach ((array) ($r['result'] ?? []) as $u) {
                $offset = max($offset, (int) ($u['update_id'] ?? 0) + 1);
                Settings::set('telegram.customer_offset', (string) $offset);
                self::handle($u, true);
                $n++;
            }
            return $n;
        });
    }

    /** سحب الرسائل الجديدة عند عدم استخدام Webhook */
    public static function poll(): int
    {
        if (Telegram::token() === '' || Settings::get('telegram.mode', 'poll') === 'webhook' || (!Settings::bool('telegram.commands') && !CustomerBot::enabled())) {
            return 0;
        }
        $offset = Settings::int('telegram.offset', 0);
        $r = Telegram::call('getUpdates', ['offset' => $offset, 'timeout' => 0, 'limit' => 50, 'allowed_updates' => ['message']], 12);
        if (!($r['ok'] ?? false)) {
            return 0;
        }
        $n = 0;
        foreach ((array) ($r['result'] ?? []) as $u) {
            $offset = max($offset, (int) ($u['update_id'] ?? 0) + 1);
            Settings::set('telegram.offset', (string) $offset);
            self::handle($u);
            $n++;
        }
        return $n;
    }
}
