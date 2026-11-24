<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\Finance;
use App\Services\Inventory;
use App\Support\Spreadsheet;

/**
 * التقارير: كل تقرير يعيد أرقاماً ملخصة ورسماً بيانياً وجداول قابلة للتصدير.
 */
final class ReportController extends Controller
{
    /** النوع => [العنوان، الأيقونة، الصلاحية] */
    public const TYPES = [
        'sales'       => ['المبيعات والتحصيل', 'banknote', 'reports.financial'],
        'profit'      => ['صافي الربح', 'trending-up', 'profit.view'],
        'cards'       => ['الكروت', 'ticket', 'reports.view'],
        'usage'       => ['الاستخدام', 'activity', 'reports.view'],
        'peak'        => ['أوقات الذروة', 'clock', 'reports.view'],
        'subscribers' => ['المشتركون', 'users', 'reports.view'],
        'routers'     => ['أجهزة MikroTik', 'router', 'reports.view'],
        'employees'   => ['الموظفون', 'user-cog', 'reports.financial'],
        'inventory'   => ['المخزون', 'warehouse', 'inventory.view'],
    ];

    public const PRESETS = [
        'today'      => 'اليوم',
        'yesterday'  => 'أمس',
        '7d'         => 'آخر 7 أيام',
        '30d'        => 'آخر 30 يوماً',
        'month'      => 'هذا الشهر',
        'last_month' => 'الشهر الماضي',
        'year'       => 'هذه السنة',
    ];

    private string $from = '';
    private string $to = '';
    private string $group = 'day';

    // ---------------------------------------------------------------------
    // الفترة والتجميع
    // ---------------------------------------------------------------------

    private function available(): array
    {
        return array_filter(self::TYPES, static fn ($t) => can($t[2]));
    }

    private function prepare(Request $request): array
    {
        $types = $this->available();
        if (!$types) {
            throw new HttpException(403);
        }
        $type = $request->str('type');
        if (!isset($types[$type])) {
            $type = array_key_first($types);
        }
        $preset = $request->str('preset');
        $from = $request->str('from');
        $to = $request->str('to');
        $today = date('Y-m-d');
        if (isset(self::PRESETS[$preset])) {
            [$from, $to] = match ($preset) {
                'today'      => [$today, $today],
                'yesterday'  => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
                '7d'         => [date('Y-m-d', strtotime('-6 days')), $today],
                'month'      => [date('Y-m-01'), $today],
                'last_month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
                'year'       => [date('Y-01-01'), $today],
                default      => [date('Y-m-d', strtotime('-29 days')), $today],
            };
        } else {
            $valid = static fn (string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
            if (!$valid($from) || !$valid($to)) {
                $preset = '30d';
                [$from, $to] = [date('Y-m-d', strtotime('-29 days')), $today];
            } else {
                $preset = '';
            }
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        // حد أقصى سنتان لتجنب الاستعلامات الثقيلة
        if ((strtotime($to) - strtotime($from)) > 731 * 86400) {
            $from = date('Y-m-d', strtotime($to . ' -731 days'));
        }
        $this->from = $from;
        $this->to = $to;
        $this->group = (strtotime($to) - strtotime($from)) > 62 * 86400 ? 'month' : 'day';
        return [$type, $preset];
    }

    private function between(): array
    {
        return [$this->from . ' 00:00:00', $this->to . ' 23:59:59'];
    }

    private function bucket(string $col): string
    {
        return $this->group === 'month' ? "DATE_FORMAT($col, '%Y-%m')" : "DATE($col)";
    }

    /** مفاتيح الفترات من البداية إلى النهاية */
    private function keys(): array
    {
        $keys = [];
        if ($this->group === 'month') {
            $t = strtotime(substr($this->from, 0, 7) . '-01');
            $end = strtotime(substr($this->to, 0, 7) . '-01');
            while ($t <= $end) {
                $keys[] = date('Y-m', $t);
                $t = strtotime('+1 month', $t);
            }
        } else {
            for ($t = strtotime($this->from); $t <= strtotime($this->to); $t = strtotime('+1 day', $t)) {
                $keys[] = date('Y-m-d', $t);
            }
        }
        return $keys;
    }

    private function label(string $key): string
    {
        return $this->group === 'month' ? $key : date('m-d', strtotime($key));
    }

    /** سلسلة قيم مرتبة حسب المفاتيح مع ملء الفراغات بصفر */
    private function series(array $pairs, array $keys): array
    {
        return array_map(static fn ($k) => round((float) ($pairs[$k] ?? 0), 2), $keys);
    }

    private function stat(string $label, string $value, string $icon, string $tone = '', string $meta = ''): array
    {
        return compact('label', 'value', 'icon', 'tone', 'meta');
    }

    // ---------------------------------------------------------------------
    // الصفحة والتصدير
    // ---------------------------------------------------------------------

    public function index(Request $request): Response
    {
        [$type, $preset] = $this->prepare($request);
        $report = $this->{$type}();
        return view('reports/index', [
            'types' => $this->available(), 'type' => $type, 'preset' => $preset,
            'from' => $this->from, 'to' => $this->to, 'report' => $report,
        ]);
    }

    public function export(Request $request): Response
    {
        [$type] = $this->prepare($request);
        $report = $this->{$type}();
        $index = $request->int('t');
        $table = $report['tables'][$index] ?? null;
        $this->notFoundUnless($table);
        $header = array_map(static fn ($c) => $c[0], $table['columns']);
        $rows = [];
        foreach ($table['rows'] as $row) {
            $out = [];
            foreach ($table['columns'] as $i => [, $kind]) {
                $out[] = self::exportValue($row[$i] ?? null, $kind);
            }
            $rows[] = $out;
        }
        Audit::log('report.export', 'تصدير تقرير ' . self::TYPES[$type][0] . ' — ' . $table['title'], 'report');
        $name = 'report-' . $type . '-' . $this->from . '-' . $this->to;
        if ($request->str('format') === 'csv' || !class_exists(\ZipArchive::class)) {
            return Response::download(Spreadsheet::csv($header, $rows), $name . '.csv', 'text/csv; charset=UTF-8');
        }
        return Response::download(Spreadsheet::xlsx($header, $rows, mb_substr($table['title'], 0, 30)), $name . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /** طباعة الملف للورق: نفس بيانات التقرير بترويسة الشبكة */
    public function print(Request $request): Response
    {
        [$type, $preset] = $this->prepare($request);
        $report = $this->{$type}();
        $query = ['type' => $type] + ($preset !== '' ? ['preset' => $preset] : ['from' => $this->from, 'to' => $this->to]);
        Audit::log('report.export', 'طباعة تقرير ' . self::TYPES[$type][0], 'report');
        $period = $this->from === $this->to ? ltr(e($this->from)) : ltr(e($this->from)) . ' — ' . ltr(e($this->to));
        return view('reports/print', [
            'type' => $type, 'report' => $report, 'from' => $this->from, 'to' => $this->to,
            'docTitle' => 'تقرير ' . self::TYPES[$type][0],
            'docMeta' => ['الفترة:' => $period . ($preset !== '' ? ' · ' . e(self::PRESETS[$preset]) : '')],
            'back' => '/reports?' . http_build_query($query),
        ], 'document');
    }

    /** تنسيق خلية في جدول تقرير */
    public static function cell(mixed $value, string $kind): string
    {
        if ($value === null || $value === '') {
            return '<span class="text-muted">—</span>';
        }
        return match ($kind) {
            'num'      => num((int) $value),
            'num1'     => num((float) $value, 1),
            'num2'     => num((float) $value, 2),
            'qty'      => e(Inventory::fmtQty($value)),
            'money'    => e(money((float) $value)),
            'bytes'    => e(fmt_bytes((int) $value)),
            'duration' => e(fmt_duration((int) $value)),
            'pct'      => ltr(e(number_format((float) $value, 1) . '%')),
            'datetime' => e(fmt_dt((string) $value)),
            'mono'     => '<span class="mono">' . e((string) $value) . '</span>',
            default    => e((string) $value),
        };
    }

    public const NUMERIC = ['num', 'num1', 'num2', 'qty', 'money', 'bytes', 'duration', 'pct'];

    private static function exportValue(mixed $v, string $kind): mixed
    {
        if ($v === null) {
            return '';
        }
        return match ($kind) {
            'num'      => (int) $v,
            'num1'     => round((float) $v, 1),
            'num2'     => round((float) $v, 2),
            'money'    => round((float) $v, 2),
            'pct'      => round((float) $v, 1),
            'qty'      => round((float) $v, 2),
            'bytes'    => Spreadsheet::plain(fmt_bytes((int) $v)),
            'duration' => Spreadsheet::plain(fmt_duration((int) $v)),
            default    => Spreadsheet::plain((string) $v),
        };
    }

    // ---------------------------------------------------------------------
    // التقارير
    // ---------------------------------------------------------------------

    private function sales(): array
    {
        [$f, $t] = $this->between();
        $keys = $this->keys();
        $b = $this->bucket('created_at');
        $sales = [];
        foreach (DB::rows("SELECT $b AS k, SUM(quantity) AS qty, SUM(total) AS total, SUM(paid) AS paid FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ? GROUP BY k", [$f, $t]) as $r) {
            $sales[$r['k']] = $r;
        }
        $tx = static fn (string $type) => DB::pairs("SELECT $b AS k, SUM(amount) FROM subscriber_transactions WHERE type = ? AND created_at BETWEEN ? AND ? GROUP BY k", [$type, $f, $t]);
        $renewals = $tx('renewal');
        $subPay = $tx('payment');
        $refunds = $tx('refund');
        $distPay = DB::pairs("SELECT $b AS k, SUM(amount) FROM distributor_payments WHERE created_at BETWEEN ? AND ? GROUP BY k", [$f, $t]);

        $rows = [];
        $tot = ['qty' => 0, 'sales' => 0.0, 'renew' => 0.0, 'collect' => 0.0];
        $chartSales = $chartRenew = $chartCollect = [];
        foreach ($keys as $k) {
            $qty = (int) ($sales[$k]['qty'] ?? 0);
            $sale = (float) ($sales[$k]['total'] ?? 0);
            $renew = (float) ($renewals[$k] ?? 0);
            $collect = (float) ($sales[$k]['paid'] ?? 0) + (float) ($subPay[$k] ?? 0) + (float) ($distPay[$k] ?? 0) - (float) ($refunds[$k] ?? 0);
            $tot['qty'] += $qty;
            $tot['sales'] += $sale;
            $tot['renew'] += $renew;
            $tot['collect'] += $collect;
            $chartSales[] = round($sale, 2);
            $chartRenew[] = round($renew, 2);
            $chartCollect[] = round($collect, 2);
            if ($qty || $sale || $renew || $collect) {
                $rows[] = [$k, $qty, $sale, $renew, $collect];
            }
        }
        $byCategory = array_map(static fn ($r) => [$r['name'], (int) $r['qty'], (float) $r['total'], (int) $r['qty'] > 0 ? (float) $r['total'] / (int) $r['qty'] : 0],
            DB::rows("SELECT c.name, SUM(s.quantity) AS qty, SUM(s.total) AS total FROM sales s JOIN categories c ON c.id = s.category_id
                      WHERE s.status = 'completed' AND s.created_at BETWEEN ? AND ? GROUP BY c.id, c.name ORDER BY total DESC", [$f, $t]));
        $byDistributor = array_map(static fn ($r) => [$r['name'] ?? 'بيع مباشر', (int) $r['ops'], (int) $r['qty'], (float) $r['total'], (float) $r['paid']],
            DB::rows("SELECT d.name, COUNT(*) AS ops, SUM(s.quantity) AS qty, SUM(s.total) AS total, SUM(s.paid) AS paid FROM sales s
                      LEFT JOIN distributors d ON d.id = s.distributor_id WHERE s.status = 'completed' AND s.created_at BETWEEN ? AND ?
                      GROUP BY s.distributor_id, d.name ORDER BY total DESC", [$f, $t]));
        $due = (float) DB::value('SELECT COALESCE(SUM(balance), 0) FROM distributors WHERE balance > 0')
            + (float) DB::value('SELECT COALESCE(SUM(balance), 0) FROM subscribers WHERE balance > 0');

        return [
            'stats' => [
                $this->stat('مبيعات الكروت', money($tot['sales']), 'ticket', 'is-info', num($tot['qty']) . ' كرت'),
                $this->stat('تجديد الاشتراكات', money($tot['renew']), 'refresh-cw', 'is-violet'),
                $this->stat('المبالغ المحصّلة', money($tot['collect']), 'banknote', 'is-success'),
                $this->stat('المستحقات الحالية', money($due), 'wallet', 'is-danger', 'الموزعون والمشتركون'),
            ],
            'chart' => [
                'type' => 'bar', 'format' => 'money', 'labels' => array_map([$this, 'label'], $keys),
                'datasets' => [
                    ['label' => 'مبيعات الكروت', 'data' => $chartSales],
                    ['label' => 'تجديد الاشتراكات', 'data' => $chartRenew],
                    ['label' => 'المحصّل', 'data' => $chartCollect, 'type' => 'line'],
                ],
            ],
            'tables' => [
                ['title' => 'حسب الفترة', 'columns' => [[$this->group === 'month' ? 'الشهر' : 'اليوم', 'text'], ['الكروت', 'num'], ['مبيعات الكروت', 'money'], ['التجديدات', 'money'], ['المحصّل', 'money']],
                    'rows' => array_reverse($rows), 'total' => ['الإجمالي', $tot['qty'], $tot['sales'], $tot['renew'], $tot['collect']]],
                ['title' => 'حسب الباقة', 'columns' => [['الباقة', 'text'], ['الكمية', 'num'], ['الإجمالي', 'money'], ['متوسط سعر الكرت', 'money']], 'rows' => $byCategory],
                ['title' => 'حسب المشتري', 'columns' => [['الموزع', 'text'], ['العمليات', 'num'], ['الكروت', 'num'], ['الإجمالي', 'money'], ['المدفوع عند البيع', 'money']], 'rows' => $byDistributor],
            ],
        ];
    }

    /** صافي الربح: الدخل المحصّل ناقص المصاريف، المصاريف حسب الفئة، وحصة كل شريك بنسبته */
    private function profit(): array
    {
        [$f, $t] = $this->between();
        $keys = $this->keys();
        $income = Finance::collectedBy($this->bucket('{col}'), $f, $t);
        $spent = DB::pairs('SELECT ' . $this->bucket('spent_on') . ' AS k, SUM(amount) FROM expenses WHERE spent_on BETWEEN ? AND ? GROUP BY k', [$this->from, $this->to]);
        $rows = [];
        $tot = ['in' => 0.0, 'out' => 0.0];
        $cIn = $cOut = $cNet = [];
        foreach ($keys as $k) {
            $in = round((float) ($income[$k] ?? 0), 2);
            $out = round((float) ($spent[$k] ?? 0), 2);
            $tot['in'] += $in;
            $tot['out'] += $out;
            $cIn[] = $in;
            $cOut[] = $out;
            $cNet[] = round($in - $out, 2);
            if ($in != 0.0 || $out != 0.0) {
                $rows[] = [$k, $in, $out, round($in - $out, 2)];
            }
        }
        $net = round($tot['in'] - $tot['out'], 2);
        $cats = array_map(static fn ($c) => [Finance::label($c['category']), $c['count'], $c['total'], $tot['out'] > 0 ? $c['total'] / $tot['out'] * 100 : 0],
            Finance::byCategory($this->from, $this->to));
        $split = Finance::split($net);
        $partners = array_map(static fn ($p) => [$p['name'], $p['share'], $p['amount']], $split['rows']);
        if ($partners && $split['rest']['share'] > 0) {
            $partners[] = ['غير موزّع', $split['rest']['share'], $split['rest']['amount']];
        }
        return [
            'stats' => [
                $this->stat('الدخل المحصّل', money($tot['in']), 'banknote', 'is-success'),
                $this->stat('المصاريف', money($tot['out']), 'receipt', 'is-danger'),
                $this->stat($net >= 0 ? 'صافي الربح' : 'صافي الخسارة', money(abs($net)), $net >= 0 ? 'trending-up' : 'trending-down', $net >= 0 ? 'is-info' : 'is-warning'),
                $this->stat('هامش الربح', $tot['in'] > 0 ? ltr(number_format($net / $tot['in'] * 100, 1) . '%') : '—', 'percent', 'is-violet'),
            ],
            'chart' => [
                'type' => 'bar', 'format' => 'money', 'labels' => array_map([$this, 'label'], $keys),
                'datasets' => [
                    ['label' => 'الدخل', 'data' => $cIn],
                    ['label' => 'المصاريف', 'data' => $cOut],
                    ['label' => 'الصافي', 'data' => $cNet, 'type' => 'line'],
                ],
            ],
            'tables' => [
                ['title' => 'حسب الفترة', 'columns' => [[$this->group === 'month' ? 'الشهر' : 'اليوم', 'text'], ['الدخل المحصّل', 'money'], ['المصاريف', 'money'], ['الصافي', 'money']],
                    'rows' => array_reverse($rows), 'total' => ['الإجمالي', $tot['in'], $tot['out'], $net]],
                ['title' => 'المصاريف حسب الفئة', 'columns' => [['الفئة', 'text'], ['العدد', 'num'], ['الإجمالي', 'money'], ['النسبة', 'pct']], 'rows' => $cats],
                ['title' => 'حصص الشركاء', 'columns' => [['الشريك', 'text'], ['النسبة', 'pct'], ['الحصة من الصافي', 'money']], 'rows' => $partners],
            ],
        ];
    }

    /**
     * أوقات الذروة: متوسط المتصلين وحركة الإنترنت لكل ساعة من اليوم (من قياسات أجهزة MikroTik)،
     * خريطة حرارية لأيام الأسبوع والساعات، الضغط على كل جهاز، وأكثر المستهلكين.
     */
    private function peak(): array
    {
        [$f, $t] = $this->between();
        [$scope, $sp] = Auth::routerScope('m.router_id');
        // متوسط كل جهاز في كل ساعة، ثم مجموع الأجهزة = الشبكة في تلك الساعة
        $cells = DB::rows("SELECT d, h, SUM(users) AS users, SUM(bps) AS bps FROM (
                SELECT DATE(m.created_at) AS d, HOUR(m.created_at) AS h, m.router_id,
                    AVG(COALESCE(m.active_hotspot, 0) + COALESCE(m.active_ppp, 0)) AS users, AVG(COALESCE(m.rx_bps, 0) + COALESCE(m.tx_bps, 0)) AS bps
                FROM router_metrics m WHERE m.online = 1 AND m.created_at BETWEEN ? AND ?$scope GROUP BY d, h, m.router_id
            ) x GROUP BY d, h", array_merge([$f, $t], $sp));
        $byHour = array_fill(0, 24, ['users' => 0.0, 'bps' => 0.0, 'n' => 0]);
        $grid = [];
        $top = ['users' => 0.0, 'at' => null];
        $days = [];
        foreach ($cells as $c) {
            $h = (int) $c['h'];
            $byHour[$h]['users'] += (float) $c['users'];
            $byHour[$h]['bps'] += (float) $c['bps'];
            $byHour[$h]['n']++;
            $w = (int) date('w', (int) strtotime((string) $c['d']));
            $grid[$w][$h][] = (float) $c['users'];
            $days[$c['d']] = max($days[$c['d']] ?? 0.0, (float) $c['users']);
            if ((float) $c['users'] > $top['users']) {
                $top = ['users' => (float) $c['users'], 'at' => $c['d'] . ' ' . str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00'];
            }
        }
        $avgUsers = array_map(static fn ($x) => $x['n'] ? round($x['users'] / $x['n'], 1) : 0.0, $byHour);
        $avgMbps = array_map(static fn ($x) => $x['n'] ? round($x['bps'] / $x['n'] / 1000000, 2) : 0.0, $byHour);
        $has = array_filter($byHour, static fn ($x) => $x['n'] > 0);
        $peakHour = $has ? (int) array_search(max($avgUsers), $avgUsers, true) : null;
        $quiet = null;
        foreach ($has as $h => $_) {
            if ($quiet === null || $avgUsers[$h] < $avgUsers[$quiet]) {
                $quiet = $h;
            }
        }
        $weekdays = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $heat = [];
        $dayAvg = [];
        foreach ($weekdays as $w => $name) {
            $row = [];
            foreach (range(0, 23) as $h) {
                $row[] = isset($grid[$w][$h]) ? round(array_sum($grid[$w][$h]) / count($grid[$w][$h]), 1) : null;
            }
            $vals = array_filter($row, static fn ($v) => $v !== null);
            $dayAvg[$w] = $vals ? array_sum($vals) / count($vals) : null;
            $heat[] = ['label' => $name, 'values' => $row];
        }
        $busyDay = array_filter($dayAvg, static fn ($v) => $v !== null) ? (int) array_search(max(array_filter($dayAvg, static fn ($v) => $v !== null)), $dayAvg, true) : null;
        $hourLabel = static fn (?int $h) => $h === null ? '—' : ltr(str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00–' . str_pad((string) (($h + 1) % 24), 2, '0', STR_PAD_LEFT) . ':00');

        // كل جهاز: ساعة ذروته، أعلى عدد متصلين، المعالج وقت ذروته، أعلى حركة، ونسبة الوقت الذي تجاوز فيه المعالج 80%
        $routers = [];
        foreach (DB::rows("SELECT m.router_id, r.name, HOUR(m.created_at) AS h, AVG(COALESCE(m.active_hotspot, 0) + COALESCE(m.active_ppp, 0)) AS users, AVG(m.cpu_load) AS cpu
                FROM router_metrics m JOIN routers r ON r.id = m.router_id WHERE m.online = 1 AND m.created_at BETWEEN ? AND ?$scope GROUP BY m.router_id, r.name, h", array_merge([$f, $t], $sp)) as $r) {
            $id = (int) $r['router_id'];
            if (!isset($routers[$id]) || (float) $r['users'] > $routers[$id]['users']) {
                $routers[$id] = ['name' => $r['name'], 'h' => (int) $r['h'], 'users' => (float) $r['users'], 'cpu' => $r['cpu'] !== null ? (float) $r['cpu'] : null];
            }
        }
        $extra = [];
        foreach (DB::rows("SELECT m.router_id, MAX(COALESCE(m.active_hotspot, 0) + COALESCE(m.active_ppp, 0)) AS max_users, MAX(COALESCE(m.rx_bps, 0) + COALESCE(m.tx_bps, 0)) AS max_bps,
                SUM(m.cpu_load >= 80) AS hot, SUM(m.cpu_load IS NOT NULL) AS n FROM router_metrics m WHERE m.online = 1 AND m.created_at BETWEEN ? AND ?$scope GROUP BY m.router_id", array_merge([$f, $t], $sp)) as $r) {
            $extra[(int) $r['router_id']] = $r;
        }
        $routerRows = [];
        foreach ($routers as $id => $r) {
            $x = $extra[$id] ?? [];
            $routerRows[] = [$r['name'], $hourLabel($r['h']), round($r['users'], 1), (int) ($x['max_users'] ?? 0), $r['cpu'] !== null ? round($r['cpu'], 1) : null,
                round((float) ($x['max_bps'] ?? 0) / 1000000, 2), (int) ($x['n'] ?? 0) > 0 ? round((int) $x['hot'] / (int) $x['n'] * 100, 1) : null];
        }
        usort($routerRows, static fn ($a, $b) => $b[2] <=> $a[2]);

        // أكثر المستهلكين في الفترة
        [$ss, $ssp] = Auth::routerScope('s.router_id');
        $consumers = array_map(static fn ($r) => [$r['username'], $r['owner'] ?? ($r['card_id'] ? 'كرت' : '—'), (int) $r['sessions'], (int) $r['bytes'], (int) $r['seconds']],
            DB::rows("SELECT s.username, MAX(sub.full_name) AS owner, MAX(s.card_id) AS card_id, COUNT(*) AS sessions, SUM(s.bytes_in + s.bytes_out) AS bytes, SUM(s.uptime) AS seconds
                FROM sessions s LEFT JOIN subscribers sub ON sub.id = s.subscriber_id WHERE s.started_at BETWEEN ? AND ?$ss
                GROUP BY s.username HAVING bytes > 0 ORDER BY bytes DESC LIMIT 20", array_merge([$f, $t], $ssp)));

        $labels = array_map(static fn ($h) => str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00', range(0, 23));
        return [
            'stats' => [
                $this->stat('ساعة الذروة', $hourLabel($peakHour), 'clock', 'is-danger', $peakHour !== null ? 'متوسط ' . num($avgUsers[$peakHour], 1) . ' متصل' : ''),
                $this->stat('أهدأ ساعة', $hourLabel($quiet), 'moon', 'is-info', $quiet !== null ? 'متوسط ' . num($avgUsers[$quiet], 1) . ' متصل' : ''),
                $this->stat('أعلى ذروة', $top['at'] !== null ? num((int) round($top['users'])) . ' متصل' : '—', 'trending-up', 'is-warning', $top['at'] !== null ? ltr($top['at']) : ''),
                $this->stat('أكثر الأيام ازدحاماً', $busyDay !== null ? $weekdays[$busyDay] : '—', 'calendar-days', 'is-violet'),
            ],
            'chart' => $has ? [
                'type' => 'bar', 'format' => 'num', 'labels' => $labels,
                'datasets' => [
                    ['label' => 'متوسط المتصلين', 'data' => $avgUsers],
                    ['label' => 'الحركة (ميغابت/ث)', 'data' => $avgMbps, 'type' => 'line'],
                ],
            ] : ['labels' => []],
            'heatmap' => $has ? ['title' => 'الازدحام حسب اليوم والساعة', 'rows' => $heat, 'max' => max(array_map(static fn ($r) => max(array_map(static fn ($v) => $v ?? 0, $r['values'])), $heat))] : null,
            'tables' => [
                ['title' => 'حسب الساعة', 'columns' => [['الساعة', 'text'], ['متوسط المتصلين', 'num1'], ['متوسط الحركة (ميغابت/ث)', 'num2']],
                    'rows' => array_values(array_map(static fn ($h) => [$labels[$h], $avgUsers[$h], $avgMbps[$h]], array_keys($has)))],
                ['title' => 'الضغط على أجهزة MikroTik', 'columns' => [['الجهاز', 'text'], ['ساعة ذروته', 'text'], ['متوسط المتصلين وقتها', 'num1'], ['أعلى عدد متصلين', 'num'],
                    ['المعالج وقت الذروة %', 'num1'], ['أعلى حركة (ميغابت/ث)', 'num2'], ['وقت المعالج فوق 80%', 'pct']], 'rows' => $routerRows],
                ['title' => 'أكثر المستهلكين', 'columns' => [['اسم الدخول', 'mono'], ['المشترك', 'text'], ['الجلسات', 'num'], ['الاستهلاك', 'bytes'], ['المدة', 'duration']], 'rows' => $consumers],
            ],
        ];
    }

    private function cards(): array
    {
        [$f, $t] = $this->between();
        $keys = $this->keys();
        $count = fn (string $col) => DB::pairs('SELECT ' . $this->bucket($col) . " AS k, COUNT(*) FROM cards WHERE $col BETWEEN ? AND ? GROUP BY k", [$f, $t]);
        $generated = $count('created_at');
        $activated = $count('first_login_at');
        $ended = $count('ended_at');
        $rows = array_map(static fn ($r) => [
            $r['name'], (int) $r['gen'], (int) $r['act'], (int) $r['avail'], (int) $r['sold'], (int) $r['active'], (int) $r['ended'], (int) $r['total'],
        ], DB::rows(
            // باقات الكروت، ثم سطر واحد يجمع الحسابات الخاصة (لكل حساب باقة مخفية)
            "(SELECT c.name, 0 AS custom, c.sort AS s1, c.price AS s2,
                    COALESCE(SUM(k.created_at BETWEEN ? AND ?), 0) AS gen,
                    COALESCE(SUM(k.first_login_at BETWEEN ? AND ?), 0) AS act,
                    COALESCE(SUM(k.status = 'new' AND k.is_disabled = 0), 0) AS avail,
                    COALESCE(SUM(k.status = 'sold'), 0) AS sold,
                    COALESCE(SUM(k.status = 'active'), 0) AS active,
                    COALESCE(SUM(k.status IN ('expired','depleted')), 0) AS ended,
                    COUNT(k.id) AS total
             FROM categories c LEFT JOIN cards k ON k.category_id = c.id
             WHERE c.is_custom = 0 GROUP BY c.id, c.name, c.sort, c.price)
             UNION ALL
             (SELECT 'الحسابات الخاصة', 1, 0, 0,
                    COALESCE(SUM(k.created_at BETWEEN ? AND ?), 0),
                    COALESCE(SUM(k.first_login_at BETWEEN ? AND ?), 0),
                    0,
                    COALESCE(SUM(k.status = 'sold'), 0),
                    COALESCE(SUM(k.status = 'active'), 0),
                    COALESCE(SUM(k.status IN ('expired','depleted')), 0),
                    COUNT(k.id)
             FROM cards k JOIN categories c ON c.id = k.category_id WHERE c.is_custom = 1 HAVING COUNT(k.id) > 0)
             ORDER BY custom, s1, s2, name",
            [$f, $t, $f, $t, $f, $t, $f, $t]
        ));
        $batches = array_map(static fn ($r) => [$r['name'], $r['category'], $r['created_at'], (int) $r['quantity'], (int) $r['used'], (int) $r['printed']],
            DB::rows("SELECT b.name, c.name AS category, b.created_at, b.quantity,
                             (SELECT COUNT(*) FROM cards k WHERE k.batch_id = b.id AND k.first_login_at IS NOT NULL) AS used,
                             (SELECT COUNT(*) FROM cards k WHERE k.batch_id = b.id AND k.printed_at IS NOT NULL) AS printed
                      FROM batches b JOIN categories c ON c.id = b.category_id WHERE b.created_at BETWEEN ? AND ? AND b.source <> 'custom' ORDER BY b.id DESC LIMIT 200", [$f, $t]));
        $sum = static fn (array $p) => (int) array_sum($p);
        return [
            'stats' => [
                $this->stat('الكروت المنشأة', num($sum($generated)), 'wand-sparkles'),
                $this->stat('التفعيلات (أول دخول)', num($sum($activated)), 'log-in', 'is-success'),
                $this->stat('الكروت المنتهية', num($sum($ended)), 'hourglass', 'is-warning'),
                $this->stat('المتاحة للبيع الآن', num((int) DB::value("SELECT COUNT(*) FROM cards WHERE status = 'new' AND is_disabled = 0")), 'package', 'is-info'),
            ],
            'chart' => [
                'type' => 'bar', 'format' => 'num', 'labels' => array_map([$this, 'label'], $keys),
                'datasets' => [
                    ['label' => 'المنشأة', 'data' => $this->series($generated, $keys)],
                    ['label' => 'التفعيلات', 'data' => $this->series($activated, $keys)],
                    ['label' => 'المنتهية', 'data' => $this->series($ended, $keys)],
                ],
            ],
            'tables' => [
                ['title' => 'حسب الباقة', 'columns' => [['الباقة', 'text'], ['منشأة في الفترة', 'num'], ['مفعّلة في الفترة', 'num'], ['متاحة', 'num'], ['مباعة', 'num'], ['نشطة', 'num'], ['منتهية', 'num'], ['الإجمالي', 'num']], 'rows' => $rows],
                ['title' => 'الدفعات في الفترة', 'columns' => [['الدفعة', 'text'], ['الباقة', 'text'], ['التاريخ', 'datetime'], ['الكمية', 'num'], ['المستخدمة', 'num'], ['المطبوعة', 'num']], 'rows' => $batches],
            ],
        ];
    }

    private function usage(): array
    {
        [$f, $t] = $this->between();
        $keys = $this->keys();
        [$scope, $sp] = Auth::routerScope('x.router_id', true);
        $b = $this->bucket('x.started_at');
        $daily = [];
        foreach (DB::rows("SELECT $b AS k, COUNT(*) AS n, SUM(x.bytes_in + x.bytes_out) AS bytes, SUM(x.uptime) AS up FROM sessions x WHERE x.started_at BETWEEN ? AND ?$scope GROUP BY k", array_merge([$f, $t], $sp)) as $r) {
            $daily[$r['k']] = $r;
        }
        $totals = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(x.bytes_in + x.bytes_out), 0) AS bytes, COALESCE(SUM(x.uptime), 0) AS up, COUNT(DISTINCT x.mac) AS devices, COUNT(DISTINCT x.username) AS users FROM sessions x WHERE x.started_at BETWEEN ? AND ?$scope", array_merge([$f, $t], $sp));
        $top = array_map(static fn ($r) => [
            $r['username'], $r['sub_id'] ? 'مشترك' : ($r['card_id'] ? 'كرت' : '—'), (int) $r['n'], (int) $r['devices'], (int) $r['bytes'], (int) $r['up'],
        ], DB::rows(
            "SELECT x.username, MAX(x.card_id) AS card_id, MAX(x.subscriber_id) AS sub_id, COUNT(*) AS n, COUNT(DISTINCT x.mac) AS devices,
                    SUM(x.bytes_in + x.bytes_out) AS bytes, SUM(x.uptime) AS up
             FROM sessions x WHERE x.started_at BETWEEN ? AND ?$scope GROUP BY x.username ORDER BY bytes DESC LIMIT 30",
            array_merge([$f, $t], $sp)
        ));
        $services = array_map(static fn ($r) => [$r['service'] === 'ppp' ? 'PPPoE' : 'هوتسبوت', (int) $r['n'], (int) $r['users'], (int) $r['bytes'], (int) $r['up']],
            DB::rows("SELECT x.service, COUNT(*) AS n, COUNT(DISTINCT x.username) AS users, SUM(x.bytes_in + x.bytes_out) AS bytes, SUM(x.uptime) AS up
                      FROM sessions x WHERE x.started_at BETWEEN ? AND ?$scope GROUP BY x.service", array_merge([$f, $t], $sp)));
        $hours = DB::pairs("SELECT HOUR(x.started_at) AS h, COUNT(*) FROM sessions x WHERE x.started_at BETWEEN ? AND ?$scope GROUP BY h", array_merge([$f, $t], $sp));
        $peak = $hours ? array_search(max($hours), $hours, true) : null;
        $devices = array_map(static fn ($r) => [$r['hostname'] ?: '—', $r['mac'], (int) $r['n'], (int) $r['bytes']],
            DB::rows("SELECT x.mac, d.hostname, COUNT(*) AS n, SUM(x.bytes_in + x.bytes_out) AS bytes FROM sessions x LEFT JOIN devices d ON d.mac = x.mac
                      WHERE x.started_at BETWEEN ? AND ? AND x.mac IS NOT NULL$scope GROUP BY x.mac, d.hostname ORDER BY bytes DESC LIMIT 30", array_merge([$f, $t], $sp)));
        return [
            'stats' => [
                $this->stat('الجلسات', num($totals['n']), 'activity', '', num($totals['users']) . ' حساب'),
                $this->stat('البيانات', fmt_bytes($totals['bytes']), 'database', 'is-violet'),
                $this->stat('وقت الاتصال', fmt_duration((int) $totals['up']), 'timer', 'is-info'),
                $this->stat('الأجهزة', num($totals['devices']), 'smartphone', 'is-success', $peak !== null ? 'ذروة الدخول: الساعة ' . ltr(sprintf('%02d:00', $peak)) : ''),
            ],
            'chart' => [
                'type' => 'bar', 'format' => 'bytes', 'labels' => array_map([$this, 'label'], $keys),
                'datasets' => [['label' => 'البيانات', 'data' => array_map(static fn ($k) => (int) ($daily[$k]['bytes'] ?? 0), $keys)]],
            ],
            'tables' => [
                ['title' => 'الأكثر استهلاكاً', 'columns' => [['الحساب', 'mono'], ['النوع', 'text'], ['الجلسات', 'num'], ['الأجهزة', 'num'], ['البيانات', 'bytes'], ['الوقت', 'duration']], 'rows' => $top],
                ['title' => 'الأجهزة', 'columns' => [['الجهاز', 'text'], ['MAC', 'mono'], ['الجلسات', 'num'], ['البيانات', 'bytes']], 'rows' => $devices],
                ['title' => 'حسب الخدمة', 'columns' => [['الخدمة', 'text'], ['الجلسات', 'num'], ['الحسابات', 'num'], ['البيانات', 'bytes'], ['الوقت', 'duration']], 'rows' => $services],
            ],
        ];
    }

    private function subscribers(): array
    {
        [$f, $t] = $this->between();
        $keys = $this->keys();
        $financial = can('reports.financial');
        [$scope, $sp] = Auth::routerScope('s.router_id', true);
        $now = now();
        $soon = date('Y-m-d H:i:s', time() + max(1, Settings::int('subscribers.expiry_alert_days', 3)) * 86400);
        $counts = DB::row(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(s.status = 'active' AND s.expires_at > ?), 0) AS active,
                    COALESCE(SUM(s.status = 'active' AND s.expires_at > ? AND s.expires_at <= ?), 0) AS expiring,
                    COALESCE(SUM(s.status = 'active' AND s.expires_at <= ?), 0) AS expired,
                    COALESCE(SUM(s.status = 'suspended'), 0) AS suspended,
                    COALESCE(SUM(s.created_at BETWEEN ? AND ?), 0) AS new_subs
             FROM subscribers s WHERE 1 = 1$scope",
            array_merge([$now, $now, $soon, $now, $f, $t], $sp)
        );
        $newDaily = DB::pairs('SELECT ' . $this->bucket('s.created_at') . " AS k, COUNT(*) FROM subscribers s WHERE s.created_at BETWEEN ? AND ?$scope GROUP BY k", array_merge([$f, $t], $sp));
        $renewDaily = DB::pairs('SELECT ' . $this->bucket('x.created_at') . " AS k, COUNT(*) FROM subscriber_transactions x JOIN subscribers s ON s.id = x.subscriber_id
                                 WHERE x.type = 'renewal' AND x.created_at BETWEEN ? AND ?$scope GROUP BY k", array_merge([$f, $t], $sp));
        $plans = DB::rows(
            "SELECT p.name, p.service,
                    COUNT(s.id) AS total,
                    COALESCE(SUM(s.status = 'active' AND s.expires_at > ?), 0) AS active,
                    COALESCE(SUM(s.status = 'active' AND s.expires_at <= ?), 0) AS expired,
                    COALESCE(SUM(s.status = 'suspended'), 0) AS suspended,
                    (SELECT COUNT(*) FROM subscriber_transactions x WHERE x.plan_id = p.id AND x.type = 'renewal' AND x.created_at BETWEEN ? AND ?) AS renewals,
                    (SELECT COALESCE(SUM(x.amount), 0) FROM subscriber_transactions x WHERE x.plan_id = p.id AND x.type = 'renewal' AND x.created_at BETWEEN ? AND ?) AS revenue
             FROM plans p LEFT JOIN subscribers s ON s.plan_id = p.id" . ($scope !== '' ? $scope : '') . "
             GROUP BY p.id, p.name, p.service ORDER BY p.sort, p.price",
            array_merge([$now, $now, $f, $t, $f, $t], $sp)
        );
        $planCols = [['الباقة', 'text'], ['الخدمة', 'text'], ['المشتركون', 'num'], ['الفعّالون', 'num'], ['المنتهون', 'num'], ['الموقوفون', 'num'], ['التجديدات', 'num']];
        if ($financial) {
            $planCols[] = ['قيمة التجديدات', 'money'];
        }
        $planRows = array_map(static function ($r) use ($financial) {
            $row = [$r['name'], $r['service'] === 'ppp' ? 'PPPoE' : 'هوتسبوت', (int) $r['total'], (int) $r['active'], (int) $r['expired'], (int) $r['suspended'], (int) $r['renewals']];
            if ($financial) {
                $row[] = (float) $r['revenue'];
            }
            return $row;
        }, $plans);
        $expCols = [['المشترك', 'text'], ['الهاتف', 'mono'], ['الباقة', 'text'], ['الانتهاء', 'datetime']];
        if ($financial) {
            $expCols[] = ['المستحق', 'money'];
        }
        $expiring = array_map(static function ($r) use ($financial) {
            $row = [$r['full_name'], $r['phone'] ?? '', $r['plan'], $r['expires_at']];
            if ($financial) {
                $row[] = (float) $r['balance'];
            }
            return $row;
        }, DB::rows(
            "SELECT s.full_name, s.phone, p.name AS plan, s.expires_at, s.balance FROM subscribers s JOIN plans p ON p.id = s.plan_id
             WHERE s.status = 'active' AND s.expires_at IS NOT NULL AND s.expires_at <= ?$scope ORDER BY s.expires_at DESC LIMIT 200",
            array_merge([$soon], $sp)
        ));
        return [
            'stats' => [
                $this->stat('المشتركون', num($counts['total']), 'users', '', num($counts['new_subs']) . ' جديد في الفترة'),
                $this->stat('الفعّالون', num($counts['active']), 'circle-check', 'is-success'),
                $this->stat('ينتهون قريباً', num($counts['expiring']), 'hourglass', 'is-warning'),
                $this->stat('المنتهون والموقوفون', num((int) $counts['expired'] + (int) $counts['suspended']), 'circle-x', 'is-danger', num($counts['suspended']) . ' موقوف'),
            ],
            'chart' => [
                'type' => 'bar', 'format' => 'num', 'labels' => array_map([$this, 'label'], $keys),
                'datasets' => [
                    ['label' => 'مشتركون جدد', 'data' => $this->series($newDaily, $keys)],
                    ['label' => 'تجديدات', 'data' => $this->series($renewDaily, $keys)],
                ],
            ],
            'tables' => [
                ['title' => 'حسب الباقة', 'columns' => $planCols, 'rows' => array_map('array_values', $planRows)],
                ['title' => 'منتهون أو ينتهون قريباً', 'columns' => $expCols, 'rows' => $expiring],
            ],
        ];
    }

    private function routers(): array
    {
        [$f, $t] = $this->between();
        [$scope, $sp] = Auth::routerScope('r.id');
        $rows = DB::rows(
            "SELECT r.id, r.name, r.status, r.uptime_seconds,
                    (SELECT COUNT(*) FROM sessions x WHERE x.router_id = r.id AND x.started_at BETWEEN ? AND ?) AS sessions,
                    (SELECT COUNT(DISTINCT x.username) FROM sessions x WHERE x.router_id = r.id AND x.started_at BETWEEN ? AND ?) AS users,
                    (SELECT COALESCE(SUM(x.bytes_in + x.bytes_out), 0) FROM sessions x WHERE x.router_id = r.id AND x.started_at BETWEEN ? AND ?) AS bytes,
                    (SELECT AVG(m.cpu_load) FROM router_metrics m WHERE m.router_id = r.id AND m.created_at BETWEEN ? AND ?) AS cpu,
                    (SELECT MAX(COALESCE(m.active_hotspot, 0) + COALESCE(m.active_ppp, 0)) FROM router_metrics m WHERE m.router_id = r.id AND m.created_at BETWEEN ? AND ?) AS peak,
                    (SELECT AVG(m.online) * 100 FROM router_metrics m WHERE m.router_id = r.id AND m.created_at BETWEEN ? AND ?) AS availability
             FROM routers r WHERE 1 = 1$scope ORDER BY r.name",
            array_merge([$f, $t, $f, $t, $f, $t, $f, $t, $f, $t, $f, $t], $sp)
        );
        $status = ['online' => 'متصل', 'offline' => 'غير متصل', 'unknown' => 'غير معروف'];
        $table = array_map(static fn ($r) => [
            $r['name'], $status[$r['status']] ?? $r['status'], $r['availability'] !== null ? (float) $r['availability'] : null,
            $r['uptime_seconds'] !== null ? (int) $r['uptime_seconds'] : null, (int) $r['sessions'], (int) $r['users'], (int) $r['bytes'],
            $r['cpu'] !== null ? (float) $r['cpu'] : null, $r['peak'] !== null ? (int) $r['peak'] : null,
        ], $rows);
        $online = count(array_filter($rows, static fn ($r) => $r['status'] === 'online'));
        return [
            'stats' => [
                $this->stat('أجهزة MikroTik', num(count($rows)), 'router', '', num($online) . ' متصل الآن'),
                $this->stat('الجلسات', num(array_sum(array_column($rows, 'sessions'))), 'activity', 'is-info'),
                $this->stat('البيانات', fmt_bytes(array_sum(array_map('intval', array_column($rows, 'bytes')))), 'database', 'is-violet'),
                $this->stat('غير المتصلة', num(count($rows) - $online), 'wifi-off', count($rows) - $online > 0 ? 'is-danger' : 'is-success'),
            ],
            'chart' => [
                'type' => 'bar', 'format' => 'bytes', 'labels' => array_column($rows, 'name'),
                'datasets' => [['label' => 'البيانات', 'data' => array_map('intval', array_column($rows, 'bytes'))]],
            ],
            'tables' => [
                ['title' => 'أداء أجهزة MikroTik', 'columns' => [['جهاز MikroTik', 'text'], ['الحالة', 'text'], ['نسبة التوفر %', 'pct'], ['مدة التشغيل', 'duration'], ['الجلسات', 'num'], ['الحسابات', 'num'], ['البيانات', 'bytes'], ['متوسط المعالج %', 'pct'], ['ذروة المتصلين', 'num']], 'rows' => $table],
            ],
        ];
    }

    private function employees(): array
    {
        [$f, $t] = $this->between();
        $rows = DB::rows(
            "SELECT e.id, e.name,
                    (SELECT COUNT(*) FROM sales s WHERE s.employee_id = e.id AND s.status = 'completed' AND s.created_at BETWEEN ? AND ?) AS ops,
                    (SELECT COALESCE(SUM(s.quantity), 0) FROM sales s WHERE s.employee_id = e.id AND s.status = 'completed' AND s.created_at BETWEEN ? AND ?) AS cards,
                    (SELECT COALESCE(SUM(s.total), 0) FROM sales s WHERE s.employee_id = e.id AND s.status = 'completed' AND s.created_at BETWEEN ? AND ?) AS sales,
                    (SELECT COUNT(*) FROM subscriber_transactions x WHERE x.employee_id = e.id AND x.type = 'renewal' AND x.created_at BETWEEN ? AND ?) AS renewals,
                    (SELECT COALESCE(SUM(x.amount), 0) FROM subscriber_transactions x WHERE x.employee_id = e.id AND x.type = 'renewal' AND x.created_at BETWEEN ? AND ?) AS renew_amount,
                    (SELECT COALESCE(SUM(x.amount), 0) FROM subscriber_transactions x WHERE x.employee_id = e.id AND x.type = 'payment' AND x.created_at BETWEEN ? AND ?)
                    + (SELECT COALESCE(SUM(p.amount), 0) FROM distributor_payments p WHERE p.employee_id = e.id AND p.created_at BETWEEN ? AND ?)
                    + (SELECT COALESCE(SUM(s.paid), 0) FROM sales s WHERE s.employee_id = e.id AND s.status = 'completed' AND s.created_at BETWEEN ? AND ?) AS collected,
                    (SELECT COALESCE(SUM(b.quantity), 0) FROM batches b WHERE b.created_by = e.id AND b.created_at BETWEEN ? AND ?) AS generated,
                    (SELECT COUNT(*) FROM audit_log a WHERE a.employee_id = e.id AND a.created_at BETWEEN ? AND ?) AS actions
             FROM employees e ORDER BY e.name",
            array_merge(...array_fill(0, 10, [$f, $t]))
        );
        $table = array_map(static fn ($r) => [
            $r['name'], (int) $r['ops'], (int) $r['cards'], (float) $r['sales'], (int) $r['renewals'], (float) $r['renew_amount'], (float) $r['collected'], (int) $r['generated'], (int) $r['actions'],
        ], $rows);
        $active = array_values(array_filter($rows, static fn ($r) => (int) $r['actions'] > 0 || (float) $r['sales'] > 0 || (int) $r['renewals'] > 0));
        return [
            'stats' => [
                $this->stat('الموظفون النشطون', num(count($active)), 'users'),
                $this->stat('المبيعات', money(array_sum(array_column($rows, 'sales'))), 'ticket', 'is-info'),
                $this->stat('التجديدات', money(array_sum(array_column($rows, 'renew_amount'))), 'refresh-cw', 'is-violet'),
                $this->stat('المحصّل', money(array_sum(array_column($rows, 'collected'))), 'banknote', 'is-success'),
            ],
            'chart' => [
                'type' => 'bar', 'format' => 'money', 'labels' => array_column($active, 'name'),
                'datasets' => [
                    ['label' => 'المبيعات', 'data' => array_map(static fn ($r) => round((float) $r['sales'], 2), $active)],
                    ['label' => 'التجديدات', 'data' => array_map(static fn ($r) => round((float) $r['renew_amount'], 2), $active)],
                    ['label' => 'المحصّل', 'data' => array_map(static fn ($r) => round((float) $r['collected'], 2), $active)],
                ],
            ],
            'tables' => [
                ['title' => 'أداء الموظفين', 'columns' => [['الموظف', 'text'], ['عمليات البيع', 'num'], ['الكروت المباعة', 'num'], ['قيمة المبيعات', 'money'], ['التجديدات', 'num'], ['قيمة التجديدات', 'money'], ['المحصّل', 'money'], ['الكروت المولّدة', 'num'], ['العمليات المسجلة', 'num']], 'rows' => $table],
            ],
        ];
    }
    private function inventory(): array
    {
        [$f, $t] = $this->between();
        $keys = $this->keys();
        $b = $this->bucket('m.created_at');
        $cost = 'COALESCE(m.unit_cost, i.unit_cost, 0)';
        $flow = [];
        foreach (DB::rows(
            "SELECT $b AS k, SUM(CASE WHEN m.type = 'in' THEN m.qty * $cost ELSE 0 END) AS inv,
                    SUM(CASE WHEN m.type = 'out' THEN -m.qty * $cost ELSE 0 END) AS outv,
                    SUM(CASE WHEN m.type = 'damage' THEN -m.qty * $cost ELSE 0 END) AS dmg
             FROM inventory_movements m JOIN inventory_items i ON i.id = m.item_id WHERE m.created_at BETWEEN ? AND ? GROUP BY k",
            [$f, $t]
        ) as $r) {
            $flow[$r['k']] = $r;
        }
        $tot = ['in' => 0.0, 'out' => 0.0, 'dmg' => 0.0];
        foreach ($flow as $r) {
            $tot['in'] += (float) $r['inv'];
            $tot['out'] += (float) $r['outv'];
            $tot['dmg'] += (float) $r['dmg'];
        }
        $summary = Inventory::summary();

        $stock = DB::rows('SELECT * FROM inventory_items WHERE is_active = 1 ORDER BY category, name');
        $stockRows = array_map(static fn ($i) => [
            $i['name'], Inventory::CATEGORIES[$i['category']][0] ?? $i['category'], (float) $i['quantity'], Inventory::UNITS[$i['unit']] ?? '',
            $i['min_qty'] !== null ? (float) $i['min_qty'] : null, $i['unit_cost'] !== null ? (float) $i['unit_cost'] : null,
            (float) $i['quantity'] * (float) $i['unit_cost'], Inventory::isLow($i) ? 'منخفض' : 'متوفر',
        ], $stock);

        $byItem = array_map(static fn ($r) => [
            $r['name'], Inventory::UNITS[$r['unit']] ?? '', (float) $r['qin'], (float) $r['qout'], (float) $r['qret'], (float) $r['qdmg'], (float) $r['vout'],
        ], DB::rows(
            "SELECT i.name, i.unit,
                    SUM(CASE WHEN m.type = 'in' THEN m.qty ELSE 0 END) AS qin,
                    SUM(CASE WHEN m.type = 'out' THEN -m.qty ELSE 0 END) AS qout,
                    SUM(CASE WHEN m.type = 'return' THEN m.qty ELSE 0 END) AS qret,
                    SUM(CASE WHEN m.type = 'damage' THEN -m.qty ELSE 0 END) AS qdmg,
                    SUM(CASE WHEN m.type = 'out' THEN -m.qty * $cost ELSE 0 END) AS vout
             FROM inventory_movements m JOIN inventory_items i ON i.id = m.item_id WHERE m.created_at BETWEEN ? AND ?
             GROUP BY i.id, i.name, i.unit ORDER BY vout DESC, i.name",
            [$f, $t]
        ));

        $bySite = array_map(static fn ($r) => [$r['name'] ?? 'بدون موقع', (int) $r['moves'], (float) $r['value']], DB::rows(
            "SELECT si.name, COUNT(*) AS moves, SUM(-m.qty * $cost) AS value
             FROM inventory_movements m JOIN inventory_items i ON i.id = m.item_id LEFT JOIN sites si ON si.id = m.site_id
             WHERE m.type = 'out' AND m.created_at BETWEEN ? AND ? GROUP BY m.site_id, si.name ORDER BY value DESC",
            [$f, $t]
        ));

        $installs = array_map(static fn ($r) => [
            $r['created_at'], $r['item_name'], $r['serial'], implode(' · ', array_filter([$r['site_name'], $r['subscriber_name'], $r['receiver']])) ?: null, $r['employee_name'],
        ], DB::rows(
            "SELECT m.created_at, i.name AS item_name, u.serial, si.name AS site_name, s.full_name AS subscriber_name, m.receiver, e.name AS employee_name
             FROM inventory_movements m JOIN inventory_items i ON i.id = m.item_id JOIN inventory_units u ON u.id = m.unit_id
             LEFT JOIN sites si ON si.id = m.site_id LEFT JOIN subscribers s ON s.id = m.subscriber_id LEFT JOIN employees e ON e.id = m.employee_id
             WHERE m.type = 'out' AND m.created_at BETWEEN ? AND ? ORDER BY m.id DESC LIMIT 500",
            [$f, $t]
        ));

        return [
            'stats' => [
                $this->stat('قيمة المخزون الحالية', money($summary['value']), 'warehouse', '', num($summary['items']) . ' صنف'),
                $this->stat('الوارد في الفترة', money($tot['in']), 'package-plus', 'is-success'),
                $this->stat('المصروف في الفترة', money($tot['out']), 'package-minus', 'is-info', num($summary['installed']) . ' جهاز مركّب'),
                $this->stat('التالف والمفقود', money($tot['dmg']), 'triangle-alert', $tot['dmg'] > 0 ? 'is-danger' : '', num($summary['low']) . ' صنف منخفض'),
            ],
            'chart' => [
                'type' => 'bar', 'format' => 'money', 'labels' => array_map([$this, 'label'], $keys),
                'datasets' => [
                    ['label' => 'الوارد', 'data' => $this->series(array_map(static fn ($r) => $r['inv'], $flow), $keys)],
                    ['label' => 'المصروف', 'data' => $this->series(array_map(static fn ($r) => $r['outv'], $flow), $keys)],
                    ['label' => 'التالف', 'data' => $this->series(array_map(static fn ($r) => $r['dmg'], $flow), $keys)],
                ],
            ],
            'tables' => [
                ['title' => 'الرصيد الحالي', 'columns' => [['الصنف', 'text'], ['التصنيف', 'text'], ['الرصيد', 'qty'], ['الوحدة', 'text'], ['الحد الأدنى', 'qty'], ['التكلفة', 'money'], ['القيمة', 'money'], ['الحالة', 'text']],
                    'rows' => $stockRows, 'total' => ['الإجمالي', null, null, null, null, null, array_sum(array_column($stockRows, 6)), null]],
                ['title' => 'الحركة حسب الصنف', 'columns' => [['الصنف', 'text'], ['الوحدة', 'text'], ['الوارد', 'qty'], ['المصروف', 'qty'], ['المرتجع', 'qty'], ['التالف', 'qty'], ['قيمة المصروف', 'money']], 'rows' => $byItem],
                ['title' => 'المصروف حسب الموقع', 'columns' => [['الموقع', 'text'], ['الحركات', 'num'], ['القيمة', 'money']], 'rows' => $bySite],
                ['title' => 'الأجهزة المركّبة', 'columns' => [['التاريخ', 'datetime'], ['الجهاز', 'text'], ['الرقم التسلسلي', 'mono'], ['المكان', 'text'], ['الموظف', 'text']], 'rows' => $installs],
            ],
        ];
    }
}
