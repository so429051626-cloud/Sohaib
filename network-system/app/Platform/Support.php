<?php
declare(strict_types=1);

namespace App\Platform;

use App\Core\Brand;
use App\Core\Platform;
use App\Services\AdminNotify;
use App\Services\Ai;
use App\Services\Telegram;
use RuntimeException;

/**
 * تذاكر الدعم بين الزبون ومسؤول المنصة، والإعلانات التي تظهر داخل أنظمة الزبائن.
 */
final class Support
{
    public const CATEGORIES = ['general' => 'استفسار عام', 'billing' => 'الاشتراك والدفع', 'technical' => 'مشكلة تقنية', 'request' => 'طلب ميزة'];
    public const STATUS = [
        'open'     => ['بانتظار الرد', 'badge-warning'],
        'answered' => ['تم الرد', 'badge-success'],
        'closed'   => ['مغلقة', 'badge-outline'],
    ];
    public const LEVELS = ['info' => 'معلومة', 'success' => 'ميزة جديدة', 'warning' => 'تنبيه أو صيانة'];

    public static function open(array $tenant, string $subject, string $category, string $body, string $by): int
    {
        $subject = trim($subject);
        $body = trim($body);
        if ($subject === '' || $body === '') {
            throw new RuntimeException('اكتب عنوان التذكرة وتفاصيلها.');
        }
        if ((int) Platform::value("SELECT COUNT(*) FROM support_tickets WHERE tenant_id = ? AND status <> 'closed'", [$tenant['id']]) >= 20) {
            throw new RuntimeException('لديك تذاكر مفتوحة كثيرة. أغلق ما انتهى منها أولاً.');
        }
        $now = date('Y-m-d H:i:s');
        $id = Platform::insert('support_tickets', [
            'tenant_id' => $tenant['id'], 'subject' => mb_substr($subject, 0, 160), 'category' => isset(self::CATEGORIES[$category]) ? $category : 'general',
            'status' => 'open', 'opened_by' => mb_substr($by, 0, 120), 'admin_unread' => 1, 'tenant_unread' => 0, 'last_message_at' => $now, 'created_at' => $now,
        ]);
        Platform::insert('support_messages', ['ticket_id' => $id, 'sender' => 'tenant', 'sender_name' => mb_substr($by, 0, 120), 'body' => mb_substr($body, 0, 5000), 'created_at' => $now]);
        Platform::log((int) $tenant['id'], 'tenant', 'ticket.open', 'تذكرة دعم جديدة: ' . mb_substr($subject, 0, 120), $by);
        AdminNotify::send('ticket', Telegram::esc($tenant['name']) . ' (' . Telegram::esc($tenant['slug']) . ')' . "\n" . '<b>' . Telegram::esc(mb_substr($subject, 0, 160)) . '</b>' . "\n" . Telegram::esc(mb_substr($body, 0, 400)));
        return $id;
    }

    public static function reply(int $ticketId, string $sender, string $name, string $body): void
    {
        $body = trim($body);
        if ($body === '') {
            throw new RuntimeException('اكتب الرد.');
        }
        $t = Platform::row('SELECT * FROM support_tickets WHERE id = ?', [$ticketId]);
        if (!$t) {
            throw new RuntimeException('التذكرة غير موجودة.');
        }
        $now = date('Y-m-d H:i:s');
        $admin = $sender === 'admin';
        Platform::insert('support_messages', ['ticket_id' => $ticketId, 'sender' => $admin ? 'admin' : 'tenant', 'sender_name' => mb_substr($name, 0, 120), 'body' => mb_substr($body, 0, 5000), 'created_at' => $now]);
        Platform::update('support_tickets', [
            'status' => $admin ? 'answered' : 'open', 'admin_unread' => $admin ? 0 : 1, 'tenant_unread' => $admin ? 1 : 0, 'last_message_at' => $now, 'closed_at' => null,
        ], 'id = ?', [$ticketId]);
        if (!$admin) {
            $tenant = Platform::row('SELECT name, slug FROM tenants WHERE id = ?', [$t['tenant_id']]);
            AdminNotify::send('ticket', Telegram::esc($tenant['name'] ?? '') . "\n" . 'رد على: <b>' . Telegram::esc($t['subject']) . '</b>' . "\n" . Telegram::esc(mb_substr($body, 0, 400)));
        } else {
            // رد الدعم يصل إلى بريد صاحب الحساب إن كانت الرسالة مفعّلة
            $tenant = Platform::row('SELECT * FROM tenants WHERE id = ?', [$t['tenant_id']]);
            if ($tenant) {
                PlatformMail::ticketReply($tenant, $t, $body);
            }
        }
    }

    public static function setClosed(int $ticketId, bool $closed): void
    {
        Platform::update('support_tickets', $closed ? ['status' => 'closed', 'closed_at' => date('Y-m-d H:i:s'), 'admin_unread' => 0] : ['status' => 'open', 'closed_at' => null], 'id = ?', [$ticketId]);
    }

    public static function thread(int $ticketId): array
    {
        return Platform::rows('SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY id', [$ticketId]);
    }

    /** عدد التذاكر التي تحمل رداً جديداً لم يقرأه الزبون */
    public static function unreadForTenant(int $tenantId): int
    {
        try {
            return (int) Platform::value("SELECT COUNT(*) FROM support_tickets WHERE tenant_id = ? AND tenant_unread = 1 AND status <> 'closed'", [$tenantId]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * اقتراح رد على تذكرة يراجعه المسؤول قبل الإرسال (لا يُرسل شيء تلقائياً).
     * @return array{ok:bool, text:string, message:string}
     */
    public static function suggest(array $ticket, array $messages, ?array $tenant, string $admin): array
    {
        if (!Ai::available(null)) {
            return ['ok' => false, 'text' => '', 'message' => Ai::stateLabel(Ai::status(null))];
        }
        $lines = [];
        foreach ($messages as $m) {
            $lines[] = ($m['sender'] === 'admin' ? 'الدعم' : 'الزبون') . ': ' . $m['body'];
        }
        $system = 'أنت موظف دعم في منصة ' . Brand::NAME . ' لإدارة شبكات الإنترنت بأجهزة MikroTik (كروت، مشتركون، مبيعات، خريطة، مخزون، تقارير). '
            . 'اكتب رداً عربياً مهذباً ومختصراً وعملياً على آخر رسالة من الزبون. لا تَعِد بما لا تعرفه، ولا تذكر أسعاراً أو مواعيد غير موجودة في المحادثة، وإن احتجت معلومة فاطلبها بسؤال واحد واضح. اكتب نص الرد فقط دون مقدمات.';
        $context = 'الشبكة: ' . ($tenant['name'] ?? '—') . "\n" . 'حالة الحساب: ' . ($tenant['status'] ?? '—') . "\n" . 'التصنيف: ' . (self::CATEGORIES[$ticket['category']] ?? '') . "\n"
            . 'عنوان التذكرة: ' . $ticket['subject'] . "\n\n" . 'المحادثة:' . "\n" . implode("\n\n", $lines);
        $r = Ai::ask('ticket_reply', $system, [['role' => 'user', 'content' => $context]], ['effort' => 'low', 'max_tokens' => 6000, 'timeout' => 90, 'actor' => $admin]);
        return ['ok' => $r['ok'], 'text' => $r['text'], 'message' => $r['message']];
    }

    // ------------------------------------------------------------------
    // الإعلانات
    // ------------------------------------------------------------------

    /** الإعلانات السارية لحساب (للكل أو لحسابات محددة هو منها) */
    public static function announcements(int $tenantId): array
    {
        $now = date('Y-m-d H:i:s');
        try {
            return Platform::rows(
                "SELECT a.id, a.title, a.body, a.level FROM platform_announcements a
                 WHERE a.is_active = 1 AND (a.starts_at IS NULL OR a.starts_at <= ?) AND (a.ends_at IS NULL OR a.ends_at >= ?)
                   AND (a.audience = 'all' OR EXISTS (SELECT 1 FROM announcement_tenants x WHERE x.announcement_id = a.id AND x.tenant_id = ?))
                 ORDER BY a.id DESC LIMIT 5",
                [$now, $now, $tenantId]
            );
        } catch (\Throwable) {
            return [];
        }
    }
}
