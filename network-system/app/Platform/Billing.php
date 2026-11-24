<?php
declare(strict_types=1);

namespace App\Platform;

use App\Core\Brand;
use App\Core\DB;
use App\Core\Platform;
use App\Core\Tenant;
use App\Services\AdminNotify;
use App\Services\Ai;
use App\Services\Telegram;
use RuntimeException;
use Throwable;

/**
 * اشتراكات المنصة: الباقات، التفعيل والتمديد، الدفعات والفواتير، تحويلات الزبائن، ومسار الانتهاء.
 * كل الاستعلامات على قاعدة المنصة مباشرة، فتعمل من لوحة المسؤول ومن داخل نظام الزبون.
 */
final class Billing
{
    public const RECEIPT_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    public const RECEIPT_MAX = 6 * 1024 * 1024;
    public const TRANSFER_STATUS = [
        'pending'  => ['بانتظار الموافقة', 'badge-warning'],
        'approved' => ['تمت الموافقة', 'badge-success'],
        'rejected' => ['مرفوض', 'badge-danger'],
    ];

    // ------------------------------------------------------------------
    // الباقات
    // ------------------------------------------------------------------

    public static function plans(bool $publicOnly = false): array
    {
        return Platform::rows('SELECT * FROM platform_plans WHERE is_active = 1' . ($publicOnly ? ' AND is_public = 1' : '') . ' ORDER BY sort, price, id');
    }

    /** @return list<string> */
    public static function planFeatures(array $plan): array
    {
        $out = [];
        foreach (preg_split('/\R/u', (string) ($plan['features'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return array_slice($out, 0, 12);
    }

    /** وصف حدود الباقة بنص عربي جاهز للعرض */
    public static function planLimits(array $plan): array
    {
        $limit = static function ($v, string $unlimited, array $forms): string {
            if ($v === null || $v === '') {
                return 'عدد غير محدود من ' . $unlimited;
            }
            $n = (int) $v;
            // صيغة العدد العربية مع فاصل الآلاف
            return 'حتى ' . ($n >= 3 ? number_format($n) . ' ' . ($n <= 10 ? $forms[2] : $forms[3]) : ar_plural($n, ...$forms));
        };
        $out = [
            $limit($plan['max_routers'], 'أجهزة MikroTik', ['جهاز MikroTik واحد', 'جهازَي MikroTik', 'أجهزة MikroTik', 'جهاز MikroTik']),
            $limit($plan['max_subscribers'], 'المشتركين', ['مشترك واحد', 'مشتركَين', 'مشتركين', 'مشترك']),
            $limit($plan['max_employees'], 'الموظفين', ['موظف واحد', 'موظفَين', 'موظفين', 'موظفاً']),
        ];
        if ((int) ($plan['ai_messages'] ?? 0) > 0) {
            $out[] = number_format((int) $plan['ai_messages']) . ' رسالة ذكاء اصطناعي شهرياً';
        }
        return $out;
    }

    public static function monthsLabel(int $months): string
    {
        return match (true) {
            $months === 1 => 'شهر',
            $months === 2 => 'شهران',
            $months === 12 => 'سنة',
            $months === 24 => 'سنتان',
            $months >= 3 && $months <= 10 => $months . ' أشهر',
            default => $months . ' شهراً',
        };
    }

    // ------------------------------------------------------------------
    // التفعيل والدفعات والفواتير
    // ------------------------------------------------------------------

    /**
     * تفعيل أو تمديد اشتراك حساب، وتسجيل الدفعة برقم فاتورة إن وُجد مبلغ.
     * @param array{months?:int, until?:?string, amount?:float, method?:?string, note?:?string, admin?:?string, plan?:?array, transfer_id?:?int, currency?:?string} $o
     * @return array{until:?string, payment_id:?int}
     */
    public static function activate(array $tenant, array $o): array
    {
        $now = date('Y-m-d H:i:s');
        $months = max(0, min(120, (int) ($o['months'] ?? 0)));
        $until = null;
        if ($months > 0) {
            $running = $tenant['status'] === 'active' && $tenant['paid_until'] && strtotime($tenant['paid_until']) > time();
            $until = date('Y-m-d H:i:s', strtotime('+' . $months . ' month', $running ? (int) strtotime($tenant['paid_until']) : time()));
        } elseif (!empty($o['until']) && strtotime((string) $o['until'])) {
            $until = date('Y-m-d 23:59:59', (int) strtotime((string) $o['until']));
        }
        $set = [
            'status' => 'active', 'paid_until' => $until, 'status_reason' => null,
            'activated_at' => $tenant['activated_at'] ?? $now, 'reminder_key' => null, 'updated_at' => $now,
        ];
        $plan = $o['plan'] ?? null;
        if ($plan) {
            $set += [
                'plan_id' => (int) $plan['id'], 'max_routers' => $plan['max_routers'],
                'max_subscribers' => $plan['max_subscribers'], 'max_employees' => $plan['max_employees'],
            ];
        }
        Platform::update('tenants', $set, 'id = ?', [$tenant['id']]);
        $paymentId = null;
        $amount = round((float) ($o['amount'] ?? 0), 2);
        if ($amount > 0 && $amount < 100000000) {
            $paymentId = Platform::insert('tenant_payments', [
                'tenant_id' => $tenant['id'], 'tenant_name' => $tenant['name'], 'amount' => $amount,
                'currency' => mb_substr((string) ($o['currency'] ?? Platform::setting('platform.currency')), 0, 8),
                'months' => $months > 0 ? $months : null, 'paid_until' => $until,
                'method' => isset($o['method']) && $o['method'] !== '' ? mb_substr((string) $o['method'], 0, 40) : null,
                'note' => isset($o['note']) && $o['note'] !== '' ? mb_substr((string) $o['note'], 0, 255) : null,
                'admin_name' => isset($o['admin']) ? mb_substr((string) $o['admin'], 0, 120) : null,
                'plan_name' => $plan ? mb_substr((string) $plan['name'], 0, 80) : null,
                'transfer_id' => $o['transfer_id'] ?? null, 'created_at' => $now,
            ]);
            Platform::exec('UPDATE tenant_payments SET invoice_no = ? WHERE id = ?', [self::invoiceNo($paymentId, $now), $paymentId]);
            // الفاتورة بالبريد (لا توقف التفعيل إن تعذّر إرسالها)
            try {
                PlatformMail::invoice($paymentId);
            } catch (Throwable $e) {
                log_message('error', 'invoice mail: ' . $e->getMessage());
            }
        }
        return ['until' => $until, 'payment_id' => $paymentId];
    }

    public static function invoiceNo(int $paymentId, ?string $date = null): string
    {
        $prefix = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', Platform::setting('billing.invoice_prefix'))) ?: 'INV';
        return $prefix . '-' . date('Y', $date ? (int) strtotime($date) : time()) . '-' . str_pad((string) $paymentId, 5, '0', STR_PAD_LEFT);
    }

    /** بيانات الفاتورة لدفعة (تُصدر رقماً للدفعات القديمة التي سبقت الفواتير) */
    public static function invoice(int $paymentId): ?array
    {
        $p = Platform::row('SELECT p.*, t.slug, t.owner_name, t.phone, t.email FROM tenant_payments p LEFT JOIN tenants t ON t.id = p.tenant_id WHERE p.id = ?', [$paymentId]);
        if (!$p) {
            return null;
        }
        if (empty($p['invoice_no'])) {
            $p['invoice_no'] = self::invoiceNo($paymentId, $p['created_at']);
            Platform::exec('UPDATE tenant_payments SET invoice_no = ? WHERE id = ?', [$p['invoice_no'], $paymentId]);
        }
        return $p;
    }

    // ------------------------------------------------------------------
    // تحويلات الزبائن
    // ------------------------------------------------------------------

    public static function banks(bool $activeOnly = true): array
    {
        return Platform::rows('SELECT * FROM bank_accounts' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort, id');
    }

    public static function receiptsDir(): string
    {
        $dir = STORAGE_PATH . '/receipts';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        return $dir;
    }

    public static function receiptPath(?string $name): ?string
    {
        if ($name === null || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', $name)) {
            return null;
        }
        $path = self::receiptsDir() . '/' . $name;
        return is_file($path) ? $path : null;
    }

    public static function receiptMime(string $name): string
    {
        return (string) (array_search(pathinfo($name, PATHINFO_EXTENSION), self::RECEIPT_TYPES, true) ?: 'application/octet-stream');
    }

    /** حفظ صورة التحويل بعد التحقق من نوعها الحقيقي وحجمها؛ يرجع اسم الملف */
    public static function storeReceipt(array $file): string
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new RuntimeException('ارفع صورة التحويل.');
        }
        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > self::RECEIPT_MAX) {
            throw new RuntimeException('حجم الملف يجب ألا يتجاوز 6 ميغابايت.');
        }
        $mime = function_exists('finfo_open') ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) : (string) (@mime_content_type($tmp) ?: '');
        if (!isset(self::RECEIPT_TYPES[$mime])) {
            throw new RuntimeException('الملف يجب أن يكون صورة (JPG أو PNG أو WebP) أو PDF.');
        }
        if ($mime !== 'application/pdf' && @getimagesize($tmp) === false) {
            throw new RuntimeException('تعذّرت قراءة الصورة.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . self::RECEIPT_TYPES[$mime];
        $dest = self::receiptsDir() . '/' . $name;
        $moved = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dest) : (PHP_SAPI === 'cli' && @copy($tmp, $dest));
        if (!$moved) {
            throw new RuntimeException('تعذّر حفظ الملف.');
        }
        @chmod($dest, 0640);
        return $name;
    }

    /**
     * طلب تجديد أو ترقية يرسله الزبون مع صورة التحويل.
     * @param array{plan_id:int, periods?:int, amount:float, bank_account_id?:int, reference?:?string, sender_name?:?string, note?:?string} $d
     */
    public static function submitTransfer(array $tenant, array $d, array $file, string $by): int
    {
        $plan = Platform::plan((int) ($d['plan_id'] ?? 0));
        if (!$plan || !(int) $plan['is_active']) {
            throw new RuntimeException('اختر الباقة.');
        }
        $periods = max(1, min(12, (int) ($d['periods'] ?? 1)));
        $amount = round((float) ($d['amount'] ?? 0), 2);
        if ($amount <= 0 || $amount >= 100000000) {
            throw new RuntimeException('أدخل المبلغ المحوَّل.');
        }
        $bank = null;
        if (!empty($d['bank_account_id'])) {
            $bank = Platform::row('SELECT * FROM bank_accounts WHERE id = ? AND is_active = 1', [(int) $d['bank_account_id']]);
        }
        if ((int) Platform::value("SELECT COUNT(*) FROM transfer_requests WHERE tenant_id = ? AND status = 'pending'", [$tenant['id']]) >= 3) {
            throw new RuntimeException('لديك طلبات بانتظار المراجعة. انتظر مراجعتها أولاً.');
        }
        $name = self::storeReceipt($file);
        $reference = trim(latin_digits((string) ($d['reference'] ?? '')));
        $id = Platform::insert('transfer_requests', [
            'tenant_id' => $tenant['id'], 'tenant_name' => $tenant['name'], 'plan_id' => $plan['id'], 'plan_name' => $plan['name'],
            'months' => (int) $plan['months'] * $periods, 'amount' => $amount, 'currency' => mb_substr(Platform::setting('platform.currency'), 0, 8),
            'bank_account_id' => $bank['id'] ?? null, 'bank_label' => $bank['label'] ?? null,
            'reference' => $reference !== '' ? mb_substr($reference, 0, 80) : null,
            'sender_name' => trim((string) ($d['sender_name'] ?? '')) !== '' ? mb_substr(trim((string) $d['sender_name']), 0, 120) : null,
            'note' => trim((string) ($d['note'] ?? '')) !== '' ? mb_substr(trim((string) $d['note']), 0, 255) : null,
            'file' => $name, 'status' => 'pending', 'submitted_by' => mb_substr($by, 0, 120), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        Platform::log((int) $tenant['id'], 'tenant', 'transfer.submit', 'طلب اشتراك بتحويل ' . $amount . ' ' . Platform::setting('platform.currency') . ' — ' . $plan['name'], $by);
        try {
            PlatformMail::transferReceived(Platform::row('SELECT * FROM tenants WHERE id = ?', [$tenant['id']]) ?? $tenant, (array) Platform::row('SELECT * FROM transfer_requests WHERE id = ?', [$id]));
        } catch (Throwable $e) {
            log_message('error', 'transfer mail: ' . $e->getMessage());
        }
        AdminNotify::send('transfer', Telegram::esc($tenant['name']) . ' (' . Telegram::esc($tenant['slug']) . ')' . "\n"
            . 'الباقة: ' . Telegram::esc($plan['name']) . ' · ' . self::monthsLabel((int) $plan['months'] * $periods) . "\n"
            . 'المبلغ: ' . $amount . ' ' . Telegram::esc(Platform::setting('platform.currency'))
            . ($reference !== '' ? "\n" . 'رقم العملية: ' . Telegram::esc($reference) : '')
            . (self::duplicateReference($reference, $id) ? "\n" . '⚠️ رقم العملية مكرر' : ''));
        return $id;
    }

    /** طلبات أخرى تحمل رقم العملية نفسه (لكشف التحويل المكرر) */
    public static function duplicateReference(?string $reference, int $exceptId): array
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return [];
        }
        return Platform::rows("SELECT id, tenant_name, status, created_at FROM transfer_requests WHERE reference = ? AND id <> ? AND status <> 'rejected' ORDER BY id DESC LIMIT 5", [$reference, $exceptId]);
    }

    /**
     * الموافقة على تحويل: يتمدد الاشتراك وتُسجَّل الدفعة وتصدر الفاتورة. لا تُنفَّذ مرتين.
     * @return array{until:?string, payment_id:?int}
     */
    public static function approveTransfer(int $id, string $admin, ?string $note = null): array
    {
        $t = Platform::row('SELECT * FROM transfer_requests WHERE id = ?', [$id]);
        if (!$t || $t['status'] !== 'pending') {
            throw new RuntimeException('الطلب غير موجود أو تمت مراجعته.');
        }
        $tenant = Platform::row('SELECT * FROM tenants WHERE id = ?', [$t['tenant_id']]);
        if (!$tenant) {
            throw new RuntimeException('الحساب غير موجود.');
        }
        $claimed = Platform::exec(
            "UPDATE transfer_requests SET status = 'approved', admin_name = ?, admin_note = ?, reviewed_at = ? WHERE id = ? AND status = 'pending'",
            [mb_substr($admin, 0, 120), $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null, date('Y-m-d H:i:s'), $id]
        );
        if ($claimed !== 1) {
            throw new RuntimeException('تمت مراجعة هذا الطلب.');
        }
        $r = self::activate($tenant, [
            'months' => (int) $t['months'], 'amount' => (float) $t['amount'], 'currency' => $t['currency'],
            'method' => 'تحويل بنكي' . ($t['bank_label'] ? ' · ' . $t['bank_label'] : ''),
            'note' => $t['reference'] ? 'رقم العملية ' . $t['reference'] : null,
            'admin' => $admin, 'plan' => Platform::plan($t['plan_id'] !== null ? (int) $t['plan_id'] : null), 'transfer_id' => $id,
        ]);
        Platform::exec('UPDATE transfer_requests SET payment_id = ? WHERE id = ?', [$r['payment_id'], $id]);
        Platform::log((int) $tenant['id'], 'admin', 'transfer.approve', 'الموافقة على تحويل ' . $t['amount'] . ' ' . $t['currency'] . ' وتمديد الاشتراك' . ($r['until'] ? ' حتى ' . substr($r['until'], 0, 10) : ''), $admin);
        return $r;
    }

    public static function rejectTransfer(int $id, string $admin, string $reason): void
    {
        $t = Platform::row('SELECT * FROM transfer_requests WHERE id = ?', [$id]);
        if (!$t || $t['status'] !== 'pending') {
            throw new RuntimeException('الطلب غير موجود أو تمت مراجعته.');
        }
        $done = Platform::exec(
            "UPDATE transfer_requests SET status = 'rejected', admin_name = ?, admin_note = ?, reviewed_at = ? WHERE id = ? AND status = 'pending'",
            [mb_substr($admin, 0, 120), mb_substr($reason, 0, 255) ?: null, date('Y-m-d H:i:s'), $id]
        );
        if ($done === 1) {
            Platform::log((int) $t['tenant_id'], 'admin', 'transfer.reject', 'رفض تحويل ' . $t['amount'] . ' ' . $t['currency'] . ($reason !== '' ? ': ' . $reason : ''), $admin);
            $tenant = Platform::row('SELECT * FROM tenants WHERE id = ?', [$t['tenant_id']]);
            if ($tenant) {
                try {
                    PlatformMail::transferRejected($tenant, $t, $reason);
                } catch (Throwable $e) {
                    log_message('error', 'transfer mail: ' . $e->getMessage());
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // مسار الاشتراك: تذكير ← سماح ← قراءة فقط ← إيقاف
    // ------------------------------------------------------------------

    /** @return list<int> أيام التذكير قبل الانتهاء مرتبة تصاعدياً */
    public static function remindDays(): array
    {
        $days = [];
        foreach (preg_split('/[\s,،;]+/u', latin_digits(Platform::setting('billing.remind_days')), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $d) {
            if (ctype_digit($d) && (int) $d >= 1 && (int) $d <= 60) {
                $days[] = (int) $d;
            }
        }
        $days = array_values(array_unique($days));
        sort($days);
        return $days;
    }

    /**
     * تُشغَّل مع المهمة المجدولة: إيقاف الحسابات التي أنهت فترتي السماح والقراءة فقط، وإرسال التذكيرات مرة لكل مرحلة.
     * @return array{expired:int, reminded:int}
     */
    public static function lifecycle(?int $now = null): array
    {
        $now ??= time();
        [$grace, $readonly] = Platform::billingWindows();
        $cut = date('Y-m-d H:i:s', $now - $grace - $readonly);
        $stamp = date('Y-m-d H:i:s', $now);
        $expired = 0;
        $due = Platform::rows("SELECT * FROM tenants WHERE (status = 'trial' AND trial_ends_at <= ?) OR (status = 'active' AND paid_until IS NOT NULL AND paid_until <= ?)", [$cut, $cut]);
        foreach ($due as $t) {
            $reason = $t['status'] === 'trial' ? 'انتهت الفترة التجريبية' : 'انتهت مدة الاشتراك';
            if (Platform::exec("UPDATE tenants SET status = 'expired', status_reason = ?, updated_at = ? WHERE id = ? AND status = ?", [$reason, $stamp, $t['id'], $t['status']]) !== 1) {
                continue;
            }
            $expired++;
            Platform::log((int) $t['id'], 'system', 'tenant.expired', 'إيقاف الحساب: ' . $reason);
            AdminNotify::send('expired', Telegram::esc($t['name']) . ' (' . Telegram::esc($t['slug']) . ')' . "\n" . $reason . ($t['phone'] ? "\n" . Telegram::esc($t['phone']) : ''));
            PlatformMail::expired($t, $reason);
        }

        $reminded = 0;
        $days = self::remindDays();
        $horizon = date('Y-m-d H:i:s', $now + ($days ? max($days) : 0) * 86400);
        $rows = Platform::rows("SELECT * FROM tenants WHERE (status = 'trial' AND trial_ends_at IS NOT NULL AND trial_ends_at <= ?) OR (status = 'active' AND paid_until IS NOT NULL AND paid_until <= ?)", [$horizon, $horizon]);
        $sms = Platform::setting('billing.remind_sms') === '1' && PlatformSms::configured();
        foreach ($rows as $t) {
            $end = (string) ($t['status'] === 'trial' ? $t['trial_ends_at'] : $t['paid_until']);
            $phase = Tenant::phase($t, $now);
            $what = $t['status'] === 'trial' ? 'الفترة التجريبية' : 'اشتراك';
            if ($phase === 'ok') {
                $left = max(1, (int) ceil((strtotime($end) - $now) / 86400));
                $step = null;
                foreach ($days as $d) {
                    if ($left <= $d) {
                        $step = $d;
                        break;
                    }
                }
                if ($step === null) {
                    continue;
                }
                $key = substr($end, 0, 10) . ':' . $step;
                $text = $what . ' شبكة ' . $t['name'] . ' ينتهي خلال ' . ($left === 1 ? 'يوم واحد' : $left . ' أيام') . '. جدّد من صفحة الاشتراك في نظامك.';
            } elseif ($phase === 'grace') {
                $key = substr($end, 0, 10) . ':grace';
                $text = 'انتهى ' . $what . ' شبكة ' . $t['name'] . '. النظام يعمل في فترة سماح حتى ' . date('Y-m-d', (int) Tenant::phaseEnds($t, $now)) . '.';
            } elseif ($phase === 'readonly') {
                $key = substr($end, 0, 10) . ':readonly';
                $text = 'نظام شبكة ' . $t['name'] . ' الآن للقراءة فقط حتى تجديد الاشتراك، ويتوقف في ' . date('Y-m-d', (int) Tenant::phaseEnds($t, $now)) . '.';
            } else {
                continue;
            }
            if ($t['reminder_key'] === $key) {
                continue;
            }
            Platform::exec('UPDATE tenants SET reminder_key = ? WHERE id = ?', [$key, $t['id']]);
            $reminded++;
            Platform::log((int) $t['id'], 'system', 'billing.reminder', mb_substr($text, 0, 250));
            if ($sms && $t['phone']) {
                try {
                    PlatformSms::send((string) $t['phone'], Brand::NAME . ': ' . $text);
                } catch (Throwable $e) {
                    log_message('warning', 'billing reminder sms: ' . $e->getMessage());
                }
            }
            PlatformMail::reminder($t, $text, $end);
        }
        return ['expired' => $expired, 'reminded' => $reminded];
    }

    // ------------------------------------------------------------------
    // أرقام الحساب (تُحدَّث من المهمة المجدولة داخل سياق الحساب)
    // ------------------------------------------------------------------

    /** تحديث أرقام نشاط الحساب في قاعدة المنصة والتنبيه عند بلوغ حد */
    public static function refreshStats(array $tenant, int $everySeconds = 1800): void
    {
        if (!empty($tenant['stats_at']) && time() - (int) strtotime((string) $tenant['stats_at']) < $everySeconds) {
            return;
        }
        $stats = [
            'stat_routers' => (int) DB::value('SELECT COUNT(*) FROM routers'),
            'stat_subscribers' => (int) DB::value('SELECT COUNT(*) FROM subscribers'),
            'stat_employees' => (int) DB::value('SELECT COUNT(*) FROM employees'),
            'stat_sales' => (float) DB::value("SELECT COALESCE(SUM(total), 0) FROM sales WHERE status = 'completed' AND created_at >= ?", [date('Y-m-01 00:00:00')])
                + (float) DB::value("SELECT COALESCE(SUM(amount), 0) FROM subscriber_transactions WHERE type = 'payment' AND created_at >= ?", [date('Y-m-01 00:00:00')]),
            'stat_login_at' => DB::value('SELECT MAX(last_login_at) FROM employees'),
            'stats_at' => date('Y-m-d H:i:s'),
        ];
        $hits = [];
        foreach (['max_routers' => ['stat_routers', 'أجهزة MikroTik'], 'max_subscribers' => ['stat_subscribers', 'المشتركون'], 'max_employees' => ['stat_employees', 'الموظفون']] as $col => [$stat, $label]) {
            $max = $tenant[$col] ?? null;
            if ($max !== null && $max !== '' && (int) $max > 0 && $stats[$stat] >= (int) $max) {
                $hits[$col . ':' . (int) $max] = $label . ' ' . $stats[$stat] . ' من ' . (int) $max;
            }
        }
        if (!empty($tenant['ai_enabled'])) {
            $limit = Tenant::aiLimit($tenant);
            if ($limit > 0 && Ai::used((int) $tenant['id']) >= $limit) {
                $hits['ai:' . $limit . ':' . date('Y-m')] = 'رسائل الذكاء الاصطناعي ' . $limit . ' من ' . $limit;
            }
        }
        $key = $hits ? mb_substr(implode('|', array_keys($hits)), 0, 60) : null;
        $stats['limit_key'] = $key;
        Platform::update('tenants', $stats, 'id = ?', [$tenant['id']]);
        if ($key !== null && $key !== ($tenant['limit_key'] ?? null)) {
            Platform::log((int) $tenant['id'], 'system', 'tenant.limit', 'بلوغ الحد: ' . implode('، ', $hits));
            AdminNotify::send('limit', Telegram::esc($tenant['name']) . ' (' . Telegram::esc($tenant['slug']) . ')' . "\n" . Telegram::esc(implode("\n", $hits)));
        }
    }
}
