<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Settings;
use App\Core\Throttle;
use Throwable;

/**
 * المهمة المجدولة: مزامنة أجهزة MikroTik، تقدّم العمليات، الإنفاذ، التنبيهات، الصيانة اليومية.
 */
final class CronService
{
    /**
     * @return array{ran:bool, synced:int, failed:int, duration:float, message:string}
     */
    public static function run(float $budget, string $source): array
    {
        $lockFile = storage_path('locks/cron.lock');
        if (!is_dir(dirname($lockFile))) {
            @mkdir(dirname($lockFile), 0775, true);
        }
        $fh = @fopen($lockFile, 'c');
        if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
            return ['ran' => false, 'synced' => 0, 'failed' => 0, 'duration' => 0, 'message' => 'تعمل نسخة أخرى حالياً.'];
        }
        @set_time_limit((int) ceil($budget) + 60);
        @ignore_user_abort(true);
        $started = microtime(true);
        $deadline = $started + $budget;
        $synced = $failed = 0;

        try {
            // 1) مزامنة أجهزة MikroTik المستحقة
            $routers = DB::rows(
                'SELECT * FROM routers WHERE is_active = 1 AND (next_poll_at IS NULL OR next_poll_at <= ?) ORDER BY COALESCE(next_poll_at, \'1970-01-01\') LIMIT 100',
                [now()]
            );
            foreach ($routers as $router) {
                if (microtime(true) > $deadline - 5) {
                    break;
                }
                $r = RouterSync::run($router);
                $r['ok'] ? $synced++ : $failed++;
            }

            // الكوكيز: تطبيق أي تغيير من مسؤول المنصة أو من إعدادات الشبكة على أجهزة MikroTik
            self::guard(static fn () => CookiePolicy::syncIfChanged());

            // 2) العمليات الطويلة
            $left = $deadline - microtime(true);
            if ($left > 4) {
                JobRunner::runPending(min(20.0, $left - 2));
            }

            // 3) الإنفاذ
            if (self::every('outages', 300)) {
                self::guard(static fn () => OutageService::catchUp());
            }
            self::guard(static fn () => Enforcer::expireDueCards());
            self::guard(static fn () => Enforcer::subscribers());
            // رفع تحديد السرعة الذي انتهت مدته
            self::guard(static fn () => UsageGuard::expireCaps());
            if (self::every('quota', 300)) {
                self::guard(static fn () => Enforcer::subscriberQuotas());
            }

            // 4) التنبيهات الدورية
            if (self::every('tamper', 1800)) {
                self::guard(static fn () => TamperWatch::run());
            }
            if (self::every('cables', 600)) {
                self::guard(static fn () => CableDoctor::scan($deadline));
            }
            if (self::every('stock', 3600)) {
                self::guard(static fn () => self::lowStockAlerts());
            }
            if (self::every('expiring', 86400 / 2)) {
                self::guard(static fn () => self::expiringAlerts());
            }

            // 5) بوت تيليجرام: الأوامر والتقارير المجدولة
            if (Telegram::token() !== '') {
                self::guard(static fn () => TelegramBot::poll());
                self::guard(static fn () => TelegramBot::pollCustomer());
                self::guard(static fn () => TelegramBot::scheduled());
                if (self::every('tg_remind', 1800)) {
                    self::guard(static fn () => CustomerBot::reminders());
                }
            }

            // 6) الرسائل النصية: إكمال الطابور والرسائل التلقائية
            if ((int) DB::value("SELECT COUNT(*) FROM sms_messages WHERE status IN ('queued', 'sending')") > 0) {
                self::guard(static fn () => SmsService::processQueue(max(5.0, min(30.0, $deadline - microtime(true) - 3))));
            }
            if (self::every('sms_auto', 1800)) {
                self::guard(static fn () => SmsService::autoReminders());
            }

            // 7) الصيانة اليومية
            if (self::every('daily', 86400)) {
                self::guard(static fn () => self::daily());
            }
            if (self::every('throttle', 3600)) {
                self::guard(static fn () => Throttle::cleanup());
            }
        } finally {
            $duration = round(microtime(true) - $started, 2);
            try {
                Settings::setMany([
                    'cron.last_run'      => now(),
                    'cron.last_duration' => (string) $duration,
                    'cron.last_source'   => $source,
                ]);
            } catch (Throwable $e) {
                log_message('error', 'cron settings: ' . $e->getMessage());
            }
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        return ['ran' => true, 'synced' => $synced, 'failed' => $failed, 'duration' => $duration, 'message' => 'تمت المزامنة.'];
    }

    private static function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            log_message('error', 'cron step: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    /** هل مرّت المدة منذ آخر تنفيذ لهذه الخطوة */
    private static function every(string $name, int $seconds): bool
    {
        $key = 'cron.step_' . $name;
        $last = (string) Settings::get($key, '');
        if ($last !== '' && time() - (int) strtotime($last) < $seconds) {
            return false;
        }
        Settings::set($key, now());
        return true;
    }

    /**
     * المهمة المجدولة يديرها الخادم تلقائياً في وضع المنصة وعلى الخادم المُدار،
     * فلا تُعرض صفحاتها ولا تعليماتها داخل النظام (تبقى تعمل كما هي).
     */
    public static function managed(): bool
    {
        return \App\Core\Platform::enabled() || \App\Core\Server::managed();
    }

    /** هل المهمة المجدولة متأخرة (لتشغيلها تلقائياً من اللوحة) */
    public static function isStale(int $seconds = 150): bool
    {
        $last = (string) Settings::get('cron.last_run', '');
        return $last === '' || time() - (int) strtotime($last) > $seconds;
    }

    public static function lowStockAlerts(): void
    {
        if (!Settings::bool('notify.low_stock')) {
            return;
        }
        $rows = DB::rows(
            "SELECT c.id, c.name, c.low_stock,
                    (SELECT COUNT(*) FROM cards k WHERE k.category_id = c.id AND k.status = 'new' AND k.is_disabled = 0) AS available
             FROM categories c WHERE c.is_active = 1 AND c.is_custom = 0 AND c.low_stock IS NOT NULL AND c.low_stock > 0"
        );
        foreach ($rows as $r) {
            if ((int) $r['available'] <= (int) $r['low_stock']) {
                Notifier::alert('low_stock', 'warning', 'مخزون باقة «' . $r['name'] . '» منخفض', 'المتاح ' . $r['available'] . ' كرت', null, 'stock:' . $r['id'], 720);
            }
        }
    }

    public static function expiringAlerts(): void
    {
        if (!Settings::bool('notify.expiring')) {
            return;
        }
        $days = max(1, Settings::int('subscribers.expiry_alert_days', 3));
        $count = (int) DB::value(
            "SELECT COUNT(*) FROM subscribers WHERE status = 'active' AND expires_at BETWEEN ? AND ?",
            [now(), date('Y-m-d H:i:s', time() + $days * 86400)]
        );
        if ($count > 0) {
            Notifier::alert('expiring', 'info', ar_plural($count, 'اشتراك واحد', 'اشتراكان', 'اشتراكات', 'اشتراكاً') . ' ينتهي خلال ' . $days . ' أيام', null, null, 'expiring:' . date('Y-m-d'), 1440);
        }
    }

    /** تنظيف البيانات القديمة والنسخ الاحتياطي اليومي */
    public static function daily(): void
    {
        $del = static function (string $sql, int $days): void {
            DB::query($sql, [date('Y-m-d H:i:s', time() - max(1, $days) * 86400)]);
        };
        $del('DELETE FROM router_metrics WHERE created_at < ?', Settings::int('retention.metrics_days', 30));
        PowerMonitor::prune();
        CableDoctor::prune();
        $del('DELETE FROM sessions WHERE stopped_at IS NOT NULL AND stopped_at < ?', Settings::int('retention.sessions_days', 180));
        $del('DELETE FROM login_log WHERE created_at < ?', Settings::int('retention.login_log_days', 60));
        $del('DELETE FROM audit_log WHERE created_at < ?', Settings::int('retention.audit_days', 365));
        $del("DELETE FROM router_tasks WHERE status <> 'pending' AND created_at < ?", Settings::int('retention.tasks_days', 14));
        $del("DELETE FROM router_tasks WHERE status = 'pending' AND created_at < ?", 30);
        $del("DELETE FROM jobs WHERE status IN ('done','failed','cancelled') AND created_at < ?", 30);
        $del('DELETE FROM alerts WHERE is_read = 1 AND created_at < ?', 90);
        // ملفات مؤقتة
        foreach (glob(storage_path('tmp') . '/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
        foreach (glob(storage_path('imports') . '/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
        if (Settings::bool('backup.auto')) {
            try {
                BackupService::create(false);
                BackupService::prune(max(1, Settings::int('backup.keep', 14)));
            } catch (Throwable $e) {
                Notifier::alert('backup', 'danger', 'فشل النسخ الاحتياطي التلقائي', $e->getMessage(), null, 'backup-fail', 720);
            }
        }
    }
}
