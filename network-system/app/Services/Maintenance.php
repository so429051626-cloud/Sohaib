<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Settings;
use RuntimeException;

/**
 * مهام الصيانة (الميزة 12): مهمة تُسند لفنّي مع الموقع والحالة، والقطع المصروفة تُخصم من المخزون،
 * والفنّي يُشعَر عند الإسناد (رسالة نصية إن كانت الرسائل مضبوطة، وعدّاد مهامه في القائمة).
 */
final class Maintenance
{
    /** النوع => [الاسم، الأيقونة] */
    public const TYPES = [
        'install'     => ['تركيب', 'hard-hat'],
        'repair'      => ['تصليح', 'wrench'],
        'maintenance' => ['صيانة دورية', 'hammer'],
        'other'       => ['أخرى', 'list-todo'],
    ];

    /** الحالة => [الاسم، الشارة] */
    public const STATUSES = [
        'new'         => ['جديدة', 'badge-info'],
        'in_progress' => ['قيد التنفيذ', 'badge-warning'],
        'done'        => ['منتهية', 'badge-success'],
        'cancelled'   => ['ملغاة', 'badge-outline'],
    ];

    /** الأولوية => [الاسم، الشارة] */
    public const PRIORITIES = [
        'normal' => ['عادية', 'badge-outline'],
        'high'   => ['مرتفعة', 'badge-warning'],
        'urgent' => ['عاجلة', 'badge-danger'],
    ];

    /** يرى كل المهام (لا مهامه فقط) */
    public static function seesAll(): bool
    {
        return Auth::can('maintenance.view') || Auth::can('maintenance.manage');
    }

    /** عدد مهامي المفتوحة (لعدّاد القائمة) */
    public static function mineOpen(): int
    {
        $id = Auth::id();
        if ($id === null || !Auth::can('maintenance.work')) {
            return 0;
        }
        try {
            return (int) DB::value("SELECT COUNT(*) FROM maintenance_tasks WHERE assignee_id = ? AND status IN ('new', 'in_progress')", [$id]);
        } catch (\Throwable) {
            return 0; // قبل ترحيل قاعدة البيانات
        }
    }

    public static function find(int $id): ?array
    {
        return DB::row('SELECT t.*, e.name AS assignee_name, e.phone AS assignee_phone, c.name AS creator_name, si.name AS site_name, si.lat AS site_lat, si.lng AS site_lng,
                r.name AS router_name, s.full_name AS subscriber_name, s.username AS subscriber_username, s.phone AS subscriber_phone, s.lat AS sub_lat, s.lng AS sub_lng
            FROM maintenance_tasks t LEFT JOIN employees e ON e.id = t.assignee_id LEFT JOIN employees c ON c.id = t.created_by LEFT JOIN sites si ON si.id = t.site_id
            LEFT JOIN routers r ON r.id = t.router_id LEFT JOIN subscribers s ON s.id = t.subscriber_id WHERE t.id = ?', [$id]);
    }

    /** يحق للمستخدم الحالي رؤية المهمة: من يرى الكل، أو الفنّي المسندة إليه */
    public static function canSee(array $task): bool
    {
        return self::seesAll() || (Auth::can('maintenance.work') && (int) $task['assignee_id'] === (int) Auth::id());
    }

    /** يحق له تغيير حالتها وقطعها: المدير، أو الفنّي المسندة إليه */
    public static function canWork(array $task): bool
    {
        return Auth::can('maintenance.manage') || (Auth::can('maintenance.work') && (int) $task['assignee_id'] === (int) Auth::id());
    }

    /**
     * إنشاء مهمة أو تعديلها. البيانات مُتحقق منها في المتحكم.
     * @return int رقم المهمة
     */
    public static function save(?int $id, array $d): int
    {
        $data = [
            'title' => $d['title'], 'type' => $d['type'], 'priority' => $d['priority'], 'assignee_id' => $d['assignee_id'] ?: null,
            'site_id' => $d['site_id'] ?: null, 'router_id' => $d['router_id'] ?: null, 'subscriber_id' => $d['subscriber_id'] ?: null,
            'location' => $d['location'] !== '' ? $d['location'] : null, 'description' => $d['description'] !== '' ? $d['description'] : null,
            'due_on' => $d['due_on'] ?: null,
        ];
        if ($id === null) {
            $id = DB::insert('maintenance_tasks', $data + ['status' => 'new', 'created_by' => Auth::id(), 'created_at' => now()]);
            Audit::log('maintenance.create', 'إنشاء مهمة صيانة #' . $id . ': ' . $d['title'], 'maintenance', $id);
            if ($data['assignee_id']) {
                self::notify($id);
            }
            return $id;
        }
        $old = DB::row('SELECT * FROM maintenance_tasks WHERE id = ?', [$id]);
        if (!$old) {
            throw new RuntimeException('المهمة غير موجودة.');
        }
        DB::update('maintenance_tasks', $data + ['updated_at' => now()], 'id = ?', [$id]);
        Audit::log('maintenance.update', 'تعديل مهمة صيانة #' . $id . ': ' . $d['title'], 'maintenance', $id);
        if ($data['assignee_id'] && (int) $data['assignee_id'] !== (int) $old['assignee_id']) {
            self::notify($id);
        }
        return $id;
    }

    /**
     * تغيير الحالة: بدء التنفيذ، إنهاء (مع نتيجة)، إلغاء، أو إعادة فتح.
     */
    public static function setStatus(array $task, string $status, string $result = ''): void
    {
        if (!isset(self::STATUSES[$status])) {
            throw new RuntimeException('حالة غير صالحة.');
        }
        if ($task['status'] === $status) {
            return;
        }
        if ($status === 'cancelled' && !Auth::can('maintenance.manage')) {
            throw new RuntimeException('إلغاء المهمة لمن يدير المهام.');
        }
        $data = ['status' => $status, 'updated_at' => now()];
        if ($status === 'in_progress') {
            $data['started_at'] = $task['started_at'] ?? now();
            $data['done_at'] = null;
        } elseif ($status === 'done') {
            $data['started_at'] = $task['started_at'] ?? now();
            $data['done_at'] = now();
            $data['result'] = $result !== '' ? mb_substr($result, 0, 500) : $task['result'];
        } elseif ($status === 'new') {
            $data['done_at'] = null;
        }
        DB::update('maintenance_tasks', $data, 'id = ?', [$task['id']]);
        Audit::log('maintenance.status', 'مهمة الصيانة #' . $task['id'] . ': ' . self::STATUSES[$status][0], 'maintenance', (int) $task['id'], ['from' => $task['status']]);
    }

    /** صرف قطعة من المخزون للمهمة (الأصناف غير المرقّمة) */
    public static function addPart(array $task, int $itemId, float $qty): void
    {
        $item = Inventory::item($itemId);
        if (!$item || !(int) $item['is_active']) {
            throw new RuntimeException('الصنف غير موجود.');
        }
        DB::transaction(static function () use ($task, $item, $qty): void {
            Inventory::move((int) $item['id'], 'out', $qty, [
                'notes' => 'مهمة صيانة #' . $task['id'] . ': ' . mb_substr((string) $task['title'], 0, 120),
                'site_id' => $task['site_id'], 'subscriber_id' => $task['subscriber_id'],
                'receiver' => $task['assignee_name'] ?? null, 'employee_id' => Auth::id(),
            ]);
            DB::insert('maintenance_parts', ['task_id' => $task['id'], 'item_id' => $item['id'], 'qty' => $qty, 'unit_cost' => $item['unit_cost'], 'created_by' => Auth::id(), 'created_at' => now()]);
        });
        Audit::log('maintenance.part', 'صرف ' . Inventory::fmtQty($qty) . ' ' . $item['name'] . ' لمهمة الصيانة #' . $task['id'], 'maintenance', (int) $task['id']);
    }

    /** حذف قطعة من المهمة يعيدها إلى المخزون */
    public static function removePart(array $task, int $partId): void
    {
        $part = DB::row('SELECT p.*, i.name FROM maintenance_parts p JOIN inventory_items i ON i.id = p.item_id WHERE p.id = ? AND p.task_id = ?', [$partId, $task['id']]);
        if (!$part) {
            throw new RuntimeException('القطعة غير موجودة.');
        }
        DB::transaction(static function () use ($task, $part): void {
            Inventory::move((int) $part['item_id'], 'return', (float) $part['qty'], ['notes' => 'إرجاع من مهمة الصيانة #' . $task['id'], 'employee_id' => Auth::id()]);
            DB::delete('maintenance_parts', 'id = ?', [$part['id']]);
        });
        Audit::log('maintenance.part', 'إرجاع ' . Inventory::fmtQty($part['qty']) . ' ' . $part['name'] . ' من مهمة الصيانة #' . $task['id'], 'maintenance', (int) $task['id']);
    }

    /** حذف مهمة: قطعها تعود إلى المخزون أولاً */
    public static function delete(array $task): void
    {
        foreach (DB::column('SELECT id FROM maintenance_parts WHERE task_id = ?', [$task['id']]) as $pid) {
            self::removePart($task, (int) $pid);
        }
        DB::delete('maintenance_tasks', 'id = ?', [$task['id']]);
        Audit::log('maintenance.delete', 'حذف مهمة الصيانة #' . $task['id'] . ': ' . $task['title'], 'maintenance', (int) $task['id']);
    }

    /** وصف قصير لمكان المهمة */
    public static function where(array $t): string
    {
        return implode(' · ', array_filter([
            $t['site_name'] ?? null, isset($t['router_name']) && $t['router_name'] !== null ? 'جهاز ' . $t['router_name'] : null,
            isset($t['subscriber_name']) && $t['subscriber_name'] !== null ? 'المشترك ' . $t['subscriber_name'] : null, $t['location'] ?? null,
        ], static fn ($v) => $v !== null && $v !== ''));
    }

    /** رابط الموقع على الخريطة (البرج أو المشترك) إن عُرفت إحداثياته */
    public static function mapUrl(array $t): ?string
    {
        foreach ([['site_lat', 'site_lng'], ['sub_lat', 'sub_lng']] as [$a, $b]) {
            if (!empty($t[$a]) && !empty($t[$b])) {
                return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($t[$a] . ',' . $t[$b]);
            }
        }
        return null;
    }

    /**
     * إشعار الفنّي بمهمة أُسندت إليه: رسالة نصية إلى هاتفه إن كانت الرسائل مضبوطة والخيار مفعّلاً.
     * @return bool هل وُضعت الرسالة في طابور الإرسال
     */
    public static function notify(int $id): bool
    {
        $t = self::find($id);
        if (!$t || !$t['assignee_id'] || !Settings::bool('maintenance.sms') || !SmsService::configured()) {
            return false;
        }
        $phone = TweetSms::normalize((string) ($t['assignee_phone'] ?? ''));
        if ($phone === null) {
            return false;
        }
        $where = self::where($t);
        $body = 'مهمة صيانة جديدة #' . $id . ': ' . $t['title']
            . ($where !== '' ? "\n" . 'المكان: ' . $where : '')
            . ($t['priority'] !== 'normal' ? "\n" . 'الأولوية: ' . self::PRIORITIES[$t['priority']][0] : '')
            . ($t['due_on'] ? "\n" . 'الموعد: ' . $t['due_on'] : '');
        try {
            SmsService::queue(mb_substr($body, 0, 480), [], [$phone], 'auto', 'مهمة صيانة #' . $id);
            return true;
        } catch (\Throwable $e) {
            log_message('warning', 'maintenance sms: ' . $e->getMessage());
            return false;
        }
    }
}
