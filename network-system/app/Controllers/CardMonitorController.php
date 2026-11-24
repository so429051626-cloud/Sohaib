<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\CardMonitor;

/**
 * متابعة الكروت: النشطة، والمنتهية (مع الحذف)، والمُدخلة خطأ في صفحة الهوتسبوت، والبحث عن كرت.
 */
final class CardMonitorController extends Controller
{
    private function tab(Request $request): string
    {
        $tab = $request->str('tab', 'active');
        return isset(CardMonitor::TABS[$tab]) ? $tab : 'active';
    }

    private function filters(Request $request, string $tab): array
    {
        $f = [];
        foreach (CardMonitor::FILTERS[$tab] as $k) {
            $f[$k] = $request->str($k);
        }
        return $f;
    }

    public function index(Request $request): Response
    {
        $tab = $this->tab($request);
        $filters = $this->filters($request, $tab);

        // البحث عن كرت وعرض تفاصيله
        $find = trim(latin_digits($request->str('find')));
        $detail = null;
        $matches = [];
        $findWrong = 0;
        if ($request->int('card') > 0) {
            $detail = CardMonitor::details($request->int('card'));
            if (!$detail) {
                // رابط قديم لكرت حُذف: يظهر كبحث بلا نتيجة بدل صفحة خطأ
                $find = '#' . $request->int('card');
            }
        } elseif ($find !== '') {
            $ids = CardMonitor::find($find);
            if (count($ids) === 1) {
                $detail = CardMonitor::details($ids[0]);
            } else {
                $matches = CardMonitor::summaries($ids);
            }
            if (!$ids) {
                $findWrong = CardMonitor::wrongCountFor($find);
            }
        }

        [$where, $params] = match ($tab) {
            'ended' => CardMonitor::endedWhere($filters),
            'wrong' => CardMonitor::wrongWhere($filters),
            default => CardMonitor::activeWhere($filters),
        };
        $total = (int) DB::value(
            $tab === 'wrong' ? "SELECT COUNT(*) FROM login_log l WHERE $where" : "SELECT COUNT(*) FROM cards c WHERE $where",
            $params
        );
        $pager = Paginator::fromRequest($request, $total, 50);
        $rows = match ($tab) {
            'ended' => CardMonitor::endedRows($filters, $pager->limitSql()),
            'wrong' => CardMonitor::wrongRows($filters, $pager->limitSql()),
            default => CardMonitor::activeRows($filters, $pager->limitSql()),
        };
        [$rScope, $rParams] = Auth::routerScope('id');
        return view('cards/monitor', [
            'tab' => $tab, 'filters' => $filters, 'rows' => $rows, 'pager' => $pager, 'total' => $total,
            'counts' => CardMonitor::counts(), 'request' => $request,
            'find' => $find, 'detail' => $detail, 'matches' => $matches, 'findWrong' => $findWrong,
            'categories' => DB::pairs('SELECT id, name FROM categories WHERE is_custom = 0 ORDER BY sort, name'),
            'routers' => DB::pairs("SELECT id, name FROM routers WHERE 1 = 1$rScope ORDER BY name", $rParams),
        ]);
    }

    /** حذف الكروت المنتهية المحددة أو كل المنتهية المطابقة للتصفية */
    public function delete(Request $request): Response
    {
        if (!can('cards.delete')) {
            throw new HttpException(403);
        }
        $ids = $request->bool('all_matching')
            ? CardMonitor::endedIds($this->filters($request, 'ended'))
            : $request->ids('ids');
        if (!$ids) {
            flash('error', 'حدد كرتاً واحداً على الأقل.');
            return back('/cards/monitor?tab=ended');
        }
        $r = CardMonitor::deleteEnded($ids);
        if ($r['count'] === 0) {
            flash('error', 'لا توجد كروت منتهية ضمن التحديد.');
            return back('/cards/monitor?tab=ended');
        }
        $msg = 'تم حذف ' . num($r['count']) . ' كرت منتهٍ.';
        if ($r['skipped'] > 0) {
            $msg .= ' تُخطي ' . num($r['skipped']) . ' كرت غير منتهٍ.';
        }
        if (!empty($r['job'])) {
            flash('success', $msg . ' يجري تطبيق التغيير على أجهزة MikroTik.');
            return redirect('/jobs/' . $r['job']);
        }
        flash('success', $msg);
        return back('/cards/monitor?tab=ended');
    }
}
