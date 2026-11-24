<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\MikroTik\Gateway;
use Throwable;

/**
 * الفحص الذكي لأجهزة MikroTik (الميزة 2): يقرأ حالة الجهاز وواجهاته وسجله قراءة فقط،
 * ويطلب من الذكاء الاصطناعي تشخيصاً بالعربية مع حل مقترح. لا يغيّر شيئاً على الجهاز.
 */
final class RouterDoctor
{
    public const LEVELS = ['ok' => 'سليم', 'warn' => 'يحتاج انتباهاً', 'bad' => 'مشكلة'];

    /**
     * بيانات الجهاز للتشخيص: من قاعدة النظام دائماً، ومن الجهاز مباشرة إن أمكن الاتصال به.
     * @return array{facts: array, reachable: bool}
     */
    public static function collect(array $router): array
    {
        $facts = [
            'الاسم' => $router['name'],
            'الحالة_في_النظام' => ['online' => 'متصل', 'offline' => 'منقطع'][$router['status']] ?? 'غير معروف',
            'آخر_اتصال_ناجح' => $router['last_seen_at'],
            'محاولات_فاشلة_متتالية' => (int) $router['fail_count'],
            'آخر_خطأ_اتصال' => $router['last_error'] ?: null,
            'الطراز' => trim(($router['board_name'] ?? '') . ' ' . ($router['model'] ?? '')) ?: null,
            'إصدار_RouterOS' => $router['version'] ?: null,
            'واجهة_الإنترنت' => $router['wan_interface'] ?: null,
            'انقطاعات_آخر_30_يوماً' => (int) DB::value('SELECT COUNT(*) FROM outages WHERE router_id = ? AND started_at >= ?', [$router['id'], date('Y-m-d H:i:s', strtotime('-30 days'))]),
            'حد_تحذير_الجهد' => $router['voltage_warn'] !== null ? (float) $router['voltage_warn'] : null,
            'حد_خطر_الجهد' => $router['voltage_crit'] !== null ? (float) $router['voltage_crit'] : null,
        ];
        $reachable = false;
        try {
            $gw = Gateway::for($router, 8.0);
            $res = $gw->resource();
            $reachable = true;
            $health = $gw->health();
            $facts['الآن'] = array_filter([
                'مدة_التشغيل_بالثواني' => $res['uptime_seconds'],
                'حمل_المعالج' => $res['cpu_load'] !== null ? $res['cpu_load'] . '%' : null,
                'الذاكرة_المستخدمة' => $res['memory_total'] ? (int) round(100 - $res['memory_free'] / $res['memory_total'] * 100) . '%' : null,
                'التخزين_المستخدم' => $res['hdd_total'] ? (int) round(100 - $res['hdd_free'] / $res['hdd_total'] * 100) . '%' : null,
                'الحرارة' => $health['temperature'],
                'الجهد' => $health['voltage'],
                'متصلون_هوتسبوت' => count($gw->hotspotActive()),
                'متصلون_PPP' => count($gw->pppActive()),
            ], static fn ($v) => $v !== null);
            $facts['الواجهات'] = array_map(static fn ($i) => array_filter([
                'الاسم' => $i['name'], 'النوع' => $i['type'], 'تعمل' => $i['running'], 'معطّلة' => $i['disabled'] ?: null,
                'مرات_انقطاع_الرابط' => isset($i['link_downs']) ? (int) $i['link_downs'] : null, 'ملاحظة' => $i['comment'] ?: null,
            ], static fn ($v) => $v !== null), array_slice($gw->interfaces(), 0, 40));
            $facts['آخر_السجل'] = array_map(static fn ($l) => trim(($l['time'] ?? '') . ' [' . ($l['topics'] ?? '') . '] ' . mb_substr((string) ($l['message'] ?? ''), 0, 200)), $gw->logs(70));
            $gw->close();
        } catch (Throwable $e) {
            $facts['تعذّر_الاتصال_الآن'] = mb_substr(Ai::scrub($e->getMessage()), 0, 200);
        }
        return ['facts' => array_filter($facts, static fn ($v) => $v !== null && $v !== []), 'reachable' => $reachable];
    }

    /**
     * @return array{ok:bool, level:string, level_label:string, text:string, message:string, reachable:bool}
     */
    public static function diagnose(array $router): array
    {
        $fail = static fn (string $message) => ['ok' => false, 'level' => '', 'level_label' => '', 'text' => '', 'message' => $message, 'reachable' => false];
        $state = Ai::status();
        if ($state !== 'ok') {
            return $fail(Ai::stateLabel($state));
        }
        $data = self::collect($router);
        $system = 'أنت خبير شبكات وأجهزة MikroTik تساعد صاحب شبكة إنترنت. شخّص حالة الجهاز من البيانات المرفقة فقط، ولا تخمّن ما ليس فيها. '
            . 'اكتب بالعربية بنص عادي بلا تنسيق Markdown، وبهذا الترتيب تماماً:' . "\n"
            . 'السطر الأول: «الحالة: سليم» أو «الحالة: انتباه» أو «الحالة: مشكلة».' . "\n"
            . 'ثم «الملاحظات:» وتحتها نقاط تبدأ بشرطة، كل نقطة مشكلة أو ملاحظة مع دليلها من البيانات (رقم أو سطر من السجل)، الأهم أولاً. إن لم توجد مشكلات فاكتب ذلك في نقطة واحدة.' . "\n"
            . 'ثم «الحل المقترح:» وتحتها خطوات مرقّمة عملية يستطيع صاحب الشبكة تنفيذها.' . "\n"
            . 'أنت للقراءة فقط: لم تغيّر شيئاً على الجهاز ولا تقل إنك فعلت. التسمية «جهاز MikroTik» وليس «راوتر».';
        $user = 'بيانات جهاز MikroTik (JSON):' . "\n" . json_encode($data['facts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $r = Ai::ask('router_check', $system, [['role' => 'user', 'content' => $user]], ['effort' => 'medium', 'max_tokens' => 3000, 'actor' => (string) (Auth::user()['name'] ?? '')]);
        if (!$r['ok']) {
            return $fail($r['message']);
        }
        [$level, $text] = self::parse($r['text'], $data['reachable']);
        return ['ok' => true, 'level' => $level, 'level_label' => self::LEVELS[$level], 'text' => $text, 'message' => '', 'reachable' => $data['reachable']];
    }

    /** يستخرج الحالة من السطر الأول؛ إن غابت فالجهاز المنقطع «مشكلة» وغيره «انتباه» */
    public static function parse(string $text, bool $reachable = true): array
    {
        $text = trim($text);
        $level = $reachable ? 'warn' : 'bad';
        if (preg_match('/^\s*الحالة\s*[:：]\s*(.+?)\s*$/mu', $text, $m)) {
            $word = $m[1];
            $level = str_contains($word, 'سليم') ? 'ok' : (str_contains($word, 'مشكل') || str_contains($word, 'خطر') ? 'bad' : 'warn');
            $text = trim((string) preg_replace('/^\s*الحالة\s*[:：].*$/mu', '', $text, 1));
        }
        return [$level, $text];
    }
}
