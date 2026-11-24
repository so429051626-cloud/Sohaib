<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Settings;
use Throwable;

/**
 * الرسائل النصية للمشتركين: التجهيز والطابور والإرسال والرسائل التلقائية.
 */
final class SmsService
{
    /** المتغيرات المتاحة في نص الرسالة */
    public const PLACEHOLDERS = [
        '{الاسم}'            => 'الاسم',
        '{اسم_المستخدم}'     => 'اسم المستخدم',
        '{الباقة}'           => 'الباقة',
        '{تاريخ_الانتهاء}'   => 'تاريخ الانتهاء',
        '{الأيام_المتبقية}'  => 'الأيام المتبقية',
        '{المستحق}'          => 'المستحق',
        '{الشبكة}'           => 'اسم الشبكة',
    ];

    public const STATES = [
        'all'       => 'كل المشتركين',
        'active'    => 'الفعّالون',
        'expiring'  => 'ينتهون قريباً',
        'expired'   => 'المنتهون',
        'suspended' => 'الموقوفون',
        'pick'      => 'اختيار مشتركين',
        'none'      => 'بدون مشتركين',
    ];

    public const AUTO = [
        'remind'  => ['تذكير قبل الانتهاء', 'عزيزنا {الاسم}، ينتهي اشتراكك في {الباقة} بتاريخ {تاريخ_الانتهاء}. {الشبكة}'],
        'expired' => ['إشعار الانتهاء', 'عزيزنا {الاسم}، انتهى اشتراكك في {الباقة}. للتجديد تواصل معنا. {الشبكة}'],
        'renew'   => ['تأكيد التجديد', 'عزيزنا {الاسم}، تم تجديد اشتراكك في {الباقة} حتى {تاريخ_الانتهاء}. شكراً لك. {الشبكة}'],
    ];

    public static function apiKey(): string
    {
        $enc = (string) Settings::get('sms.api_key', '');
        if ($enc === '') {
            return '';
        }
        try {
            return (string) Crypto::decrypt($enc);
        } catch (Throwable) {
            return '';
        }
    }

    public static function sender(): string
    {
        return trim((string) Settings::get('sms.sender', ''));
    }

    public static function configured(): bool
    {
        return self::apiKey() !== '' && self::sender() !== '';
    }

    public static function personalized(string $body): bool
    {
        foreach (array_keys(self::PLACEHOLDERS) as $p) {
            if (str_contains($body, $p)) {
                return true;
            }
        }
        return false;
    }

    /** تعبئة المتغيرات لمشترك (أو بقيم فارغة للأرقام اليدوية) */
    public static function render(string $body, ?array $sub): string
    {
        $exp = $sub['expires_at'] ?? null;
        $left = $exp ? max(0, (int) ceil((strtotime($exp) - time()) / 86400)) : null;
        $vals = [
            '{الاسم}' => (string) ($sub['full_name'] ?? ''),
            '{اسم_المستخدم}' => (string) ($sub['username'] ?? ''),
            '{الباقة}' => (string) ($sub['plan_name'] ?? ''),
            '{تاريخ_الانتهاء}' => $exp ? date('Y-m-d', strtotime($exp)) : '',
            '{الأيام_المتبقية}' => $left !== null ? (string) $left : '',
            '{المستحق}' => $sub === null ? '' : (isset($sub['balance']) && (float) $sub['balance'] > 0 ? strip_tags(str_replace(["\u{2066}", "\u{2069}"], '', money($sub['balance']))) : '0'),
            '{الشبكة}' => Settings::networkName(),
        ];
        $text = strtr($body, $vals);
        $text = (string) preg_replace('/[ \t]{2,}/u', ' ', $text);
        $text = (string) preg_replace('/[ \t]+([،,.!؟?:؛])/u', '$1', $text);
        return trim($text);
    }

    /**
     * المشتركون المطابقون للفلاتر مع رقم صالح.
     * @param array{state?:string,plan?:int,area?:int,router?:int} $f
     */
    public static function audience(array $f): array
    {
        $state = $f['state'] ?? 'all';
        if ($state === 'none') {
            return [];
        }
        $where = ['1 = 1'];
        $params = [];
        if ($state === 'pick') {
            // مشتركون اختارهم المرسل بأعينهم
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($f['ids'] ?? [])), static fn ($x) => $x > 0)));
            if (!$ids) {
                return [];
            }
            $where[] = 's.id IN (' . DB::in($ids) . ')';
            $params = array_merge($params, $ids);
        }
        $now = now();
        $days = max(1, Settings::int('subscribers.expiry_alert_days', 3));
        switch ($state) {
            case 'active':
                $where[] = "s.status = 'active' AND s.expires_at > ?";
                $params[] = $now;
                break;
            case 'expiring':
                $where[] = "s.status = 'active' AND s.expires_at > ? AND s.expires_at <= ?";
                array_push($params, $now, date('Y-m-d H:i:s', time() + $days * 86400));
                break;
            case 'expired':
                $where[] = "s.status = 'active' AND s.expires_at IS NOT NULL AND s.expires_at <= ?";
                $params[] = $now;
                break;
            case 'suspended':
                $where[] = "s.status = 'suspended'";
                break;
        }
        foreach (['plan' => 's.plan_id', 'area' => 's.area_id', 'router' => 's.router_id'] as $k => $col) {
            if ($state !== 'pick' && !empty($f[$k]) && (int) $f[$k] > 0) {
                $where[] = "$col = ?";
                $params[] = (int) $f[$k];
            }
        }
        [$scope, $sp] = Auth::routerScope('s.router_id', true);
        $rows = DB::rows(
            'SELECT s.id, s.full_name, s.username, s.phone, s.phone2, s.expires_at, s.balance, p.name AS plan_name
             FROM subscribers s JOIN plans p ON p.id = s.plan_id WHERE ' . implode(' AND ', $where) . $scope . ' ORDER BY s.full_name',
            array_merge($params, $sp)
        );
        $out = [];
        foreach ($rows as $r) {
            $n = TweetSms::normalize((string) $r['phone']) ?? TweetSms::normalize((string) $r['phone2']);
            if ($n !== null) {
                $out[] = $r + ['msisdn' => $n];
            }
        }
        return $out;
    }

    /**
     * البحث عن مشتركين لاختيارهم مستلمين (بالاسم أو اسم المستخدم أو الهاتف)، ومن لهم رقم صالح فقط.
     * @return list<array{id:string, label:string, sub:string, phone:string}>
     */
    public static function pickSubscribers(string $q, array $ids = [], int $limit = 50): array
    {
        $where = ['1 = 1'];
        $params = [];
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($x) => $x > 0));
        if ($ids) {
            $where[] = 's.id IN (' . DB::in($ids) . ')';
            $params = $ids;
        } elseif (($q = trim(latin_digits($q))) !== '') {
            $where[] = '(s.full_name LIKE ? OR s.username LIKE ? OR s.phone LIKE ? OR s.phone2 LIKE ?)';
            $like = DB::like($q);
            array_push($params, $like, $like, $like, $like);
        }
        [$scope, $sp] = Auth::routerScope('s.router_id', true);
        $rows = DB::rows(
            'SELECT s.id, s.full_name, s.username, s.phone, s.phone2, p.name AS plan_name FROM subscribers s JOIN plans p ON p.id = s.plan_id
             WHERE ' . implode(' AND ', $where) . $scope . ' ORDER BY s.full_name LIMIT ' . (int) ($limit * 3),
            array_merge($params, $sp)
        );
        $out = [];
        foreach ($rows as $r) {
            $n = TweetSms::normalize((string) $r['phone']) ?? TweetSms::normalize((string) $r['phone2']);
            if ($n !== null) {
                $out[] = ['id' => 's:' . $r['id'], 'label' => (string) $r['full_name'], 'sub' => $r['username'] . ' · ' . $r['plan_name'], 'phone' => '0' . substr($n, 3)];
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * إنشاء رسالة في الطابور.
     * @param array $subs مشتركون من audience()
     * @param string[] $numbers أرقام يدوية منظّفة
     */
    public static function queue(string $body, array $subs, array $numbers, string $kind, string $audience, ?string $scheduledAt = null): int
    {
        $personal = self::personalized($body);
        $seen = [];
        $rows = [];
        foreach ($subs as $s) {
            if (isset($seen[$s['msisdn']])) {
                continue;
            }
            $seen[$s['msisdn']] = true;
            $rows[] = ['subscriber_id' => (int) $s['id'], 'name' => mb_substr((string) $s['full_name'], 0, 120), 'phone' => $s['msisdn'], 'body' => $personal ? self::render($body, $s) : null];
        }
        foreach ($numbers as $n) {
            if (isset($seen[$n])) {
                continue;
            }
            $seen[$n] = true;
            $rows[] = ['subscriber_id' => null, 'name' => null, 'phone' => $n, 'body' => $personal ? self::render($body, null) : null];
        }
        return DB::transaction(static function () use ($body, $rows, $kind, $audience, $scheduledAt, $personal) {
            $segments = $personal ? max(array_map(static fn ($r) => TweetSms::segments((string) $r['body']), $rows) ?: [1]) : TweetSms::segments($body);
            $id = DB::insert('sms_messages', [
                'kind' => $kind, 'audience' => mb_substr($audience, 0, 255), 'body' => $body, 'personalized' => $personal ? 1 : 0,
                'segments' => max(1, $segments), 'recipients' => count($rows), 'status' => 'queued', 'scheduled_at' => $scheduledAt,
                'employee_id' => Auth::id(), 'created_at' => now(),
            ]);
            foreach (array_chunk($rows, 500) as $chunk) {
                $ph = [];
                $vals = [];
                foreach ($chunk as $r) {
                    $ph[] = '(?, ?, ?, ?, ?, \'pending\')';
                    array_push($vals, $id, $r['subscriber_id'], $r['name'], $r['phone'], $r['body']);
                }
                DB::query('INSERT INTO sms_recipients (message_id, subscriber_id, name, phone, body, status) VALUES ' . implode(',', $ph), $vals);
            }
            return $id;
        });
    }

    /**
     * إرسال المستلمين المعلّقين لرسالة ضمن وقت محدد.
     * @return array{done:bool, sent:int, failed:int}
     */
    public static function process(int $id, float $budget = 50.0): array
    {
        $msg = DB::row('SELECT * FROM sms_messages WHERE id = ?', [$id]);
        if (!$msg || in_array($msg['status'], ['done', 'failed'], true)) {
            return ['done' => true, 'sent' => 0, 'failed' => 0];
        }
        $key = self::apiKey();
        $sender = self::sender();
        if ($key === '' || $sender === '') {
            self::failRemaining($id, null, 'إعدادات الرسائل غير مكتملة.');
            return ['done' => true, 'sent' => 0, 'failed' => 0];
        }
        DB::update('sms_messages', ['status' => 'sending'], 'id = ?', [$id]);
        $started = microtime(true);
        [$date, $time] = $msg['scheduled_at'] ? [date('d/m/Y', strtotime($msg['scheduled_at'])), date('H:i', strtotime($msg['scheduled_at']))] : [null, null];
        $fatal = null;
        while (microtime(true) - $started < $budget && $fatal === null) {
            $pending = DB::rows("SELECT id, phone, body FROM sms_recipients WHERE message_id = ? AND status = 'pending' ORDER BY id LIMIT " . ((int) $msg['personalized'] ? 20 : TweetSms::BATCH), [$id]);
            if (!$pending) {
                break;
            }
            $groups = (int) $msg['personalized'] ? array_map(static fn ($r) => [$r], $pending) : [$pending];
            foreach ($groups as $group) {
                $text = (int) $msg['personalized'] ? (string) $group[0]['body'] : (string) $msg['body'];
                $r = TweetSms::sendOnce($key, $sender, $text, array_column($group, 'phone'), $date, $time);
                $reqId = DB::insert('sms_requests', [
                    'message_id' => $id, 'numbers' => count($group), 'ok' => $r['ok'] ? 1 : 0, 'code' => $r['code'],
                    'description' => mb_substr(TweetSms::sendMessage($r), 0, 255), 'raw' => $r['raw'], 'created_at' => now(),
                ]);
                $ids = array_column($group, 'id');
                $in = implode(',', array_fill(0, count($ids), '?'));
                if ($r['transport']) {
                    // يبقى معلّقاً لمحاولة لاحقة
                    $fatal = ['code' => null, 'message' => TweetSms::sendMessage($r), 'transport' => true];
                    break;
                }
                DB::query("UPDATE sms_recipients SET status = ?, code = ?, request_id = ?, updated_at = ? WHERE id IN ($in)", array_merge([$r['ok'] ? 'sent' : 'failed', $r['code'], $reqId, now()], $ids));
                if (!$r['ok'] && in_array($r['code'], TweetSms::FATAL, true)) {
                    $fatal = ['code' => $r['code'], 'message' => TweetSms::sendMessage($r), 'transport' => false];
                    break;
                }
                if ((int) $msg['personalized']) {
                    usleep(120000);
                }
            }
        }
        if ($fatal !== null && !$fatal['transport']) {
            self::failRemaining($id, $fatal['code'], $fatal['message']);
        }
        return self::finalize($id, $fatal['message'] ?? null);
    }

    private static function failRemaining(int $id, ?int $code, string $error): void
    {
        DB::query("UPDATE sms_recipients SET status = 'failed', code = ?, updated_at = ? WHERE message_id = ? AND status = 'pending'", [$code, now(), $id]);
        DB::update('sms_messages', ['error' => mb_substr($error, 0, 255)], 'id = ?', [$id]);
        self::finalize($id, $error);
    }

    /** @return array{done:bool, sent:int, failed:int} */
    private static function finalize(int $id, ?string $error): array
    {
        $c = DB::pairs('SELECT status, COUNT(*) FROM sms_recipients WHERE message_id = ? GROUP BY status', [$id]);
        $sent = (int) ($c['sent'] ?? 0);
        $failed = (int) ($c['failed'] ?? 0);
        $pending = (int) ($c['pending'] ?? 0);
        $update = ['sent' => $sent, 'failed' => $failed];
        if ($pending === 0) {
            $update['status'] = $sent > 0 ? 'done' : 'failed';
            $update['finished_at'] = now();
        }
        if ($error !== null) {
            $update['error'] = mb_substr($error, 0, 255);
        }
        DB::update('sms_messages', $update, 'id = ?', [$id]);
        return ['done' => $pending === 0, 'sent' => $sent, 'failed' => $failed];
    }

    /** متابعة الرسائل غير المكتملة (من المهمة المجدولة) */
    public static function processQueue(float $budget = 30.0): int
    {
        $started = microtime(true);
        $n = 0;
        foreach (DB::column("SELECT id FROM sms_messages WHERE status IN ('queued', 'sending') ORDER BY id LIMIT 20") as $id) {
            if (microtime(true) - $started > $budget) {
                break;
            }
            self::process((int) $id, max(5.0, $budget - (microtime(true) - $started)));
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // الرسائل التلقائية
    // ------------------------------------------------------------------

    public static function autoText(string $kind): string
    {
        $t = trim((string) Settings::get('sms.text_' . $kind, ''));
        return $t !== '' ? $t : self::AUTO[$kind][1];
    }

    /** تذكير قبل الانتهاء وإشعار الانتهاء — مرة واحدة لكل تاريخ انتهاء */
    public static function autoReminders(): int
    {
        if (!self::configured()) {
            return 0;
        }
        $made = 0;
        $days = max(1, Settings::int('sms.remind_days', 3));
        $jobs = [];
        if (Settings::bool('sms.auto_remind')) {
            $jobs['remind'] = ["s.status = 'active' AND s.expires_at > ? AND s.expires_at <= ?", [now(), date('Y-m-d H:i:s', time() + $days * 86400)]];
        }
        if (Settings::bool('sms.auto_expired')) {
            $jobs['expired'] = ['s.expires_at IS NOT NULL AND s.expires_at <= ? AND s.expires_at > ?', [now(), date('Y-m-d H:i:s', time() - 2 * 86400)]];
        }
        foreach ($jobs as $kind => [$cond, $params]) {
            $subs = DB::rows(
                "SELECT s.id, s.full_name, s.username, s.phone, s.phone2, s.expires_at, s.balance, p.name AS plan_name FROM subscribers s JOIN plans p ON p.id = s.plan_id
                 WHERE $cond AND NOT EXISTS (SELECT 1 FROM sms_auto a WHERE a.subscriber_id = s.id AND a.kind = ? AND a.ref = s.expires_at) LIMIT 500",
                array_merge($params, [$kind])
            );
            $list = [];
            foreach ($subs as $s) {
                DB::query('INSERT IGNORE INTO sms_auto (subscriber_id, kind, ref, created_at) VALUES (?, ?, ?, ?)', [$s['id'], $kind, $s['expires_at'], now()]);
                $n = TweetSms::normalize((string) $s['phone']) ?? TweetSms::normalize((string) $s['phone2']);
                if ($n !== null) {
                    $list[] = $s + ['msisdn' => $n];
                }
            }
            if ($list) {
                $id = self::queue(self::autoText($kind), $list, [], 'auto', self::AUTO[$kind][0]);
                self::process($id, 20.0);
                $made++;
            }
        }
        return $made;
    }

    /** تأكيد التجديد فور تسجيله */
    public static function renewed(int $subscriberId, string $expiresAt): void
    {
        if (!self::configured() || !Settings::bool('sms.auto_renew')) {
            return;
        }
        $s = DB::row('SELECT s.id, s.full_name, s.username, s.phone, s.phone2, s.expires_at, s.balance, p.name AS plan_name FROM subscribers s JOIN plans p ON p.id = s.plan_id WHERE s.id = ?', [$subscriberId]);
        $n = $s ? (TweetSms::normalize((string) $s['phone']) ?? TweetSms::normalize((string) $s['phone2'])) : null;
        if ($n === null) {
            return;
        }
        try {
            DB::query('INSERT INTO sms_auto (subscriber_id, kind, ref, created_at) VALUES (?, ?, ?, ?)', [$subscriberId, 'renew', $expiresAt, now()]);
        } catch (Throwable) {
            return;
        }
        $id = self::queue(self::autoText('renew'), [$s + ['msisdn' => $n]], [], 'auto', self::AUTO['renew'][0] . ' — ' . $s['full_name']);
        \App\Core\Kernel::afterResponse(static fn () => self::process($id, 20.0));
        if (PHP_SAPI === 'cli') {
            self::process($id, 20.0);
        }
    }
}
