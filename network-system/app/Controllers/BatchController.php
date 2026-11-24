<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\CardService;
use App\Services\CustomAccounts;
use App\Services\JobRunner;

final class BatchController extends Controller
{
    public function index(Request $request): Response
    {
        // دفعات الحسابات الخاصة تُدار من صفحتها
        $where = "b.source <> 'custom'";
        $params = [];
        if ($request->int('category') > 0) {
            $where .= ' AND b.category_id = ?';
            $params[] = $request->int('category');
        }
        if (in_array($request->str('source'), ['generated', 'imported', 'router'], true)) {
            $where .= ' AND b.source = ?';
            $params[] = $request->str('source');
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM batches b WHERE $where", $params);
        $pager = Paginator::fromRequest($request, $total, 50);
        $rows = DB::rows(
            "SELECT b.*, c.name AS category_name, c.color, e.name AS employee_name
             FROM batches b JOIN categories c ON c.id = b.category_id LEFT JOIN employees e ON e.id = b.created_by
             WHERE $where ORDER BY b.id DESC" . $pager->limitSql(),
            $params
        );
        // أعداد كروت دفعات الصفحة بمرور واحد على الكروت بدل خمسة استعلامات لكل دفعة
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $stats = [];
        if ($ids) {
            foreach (DB::rows(
                "SELECT batch_id, COUNT(*) AS cards, COALESCE(SUM(status = 'new' AND is_disabled = 0), 0) AS available,
                        COALESCE(SUM(first_login_at IS NOT NULL), 0) AS used, COALESCE(SUM(printed_at IS NOT NULL), 0) AS printed,
                        COALESCE(SUM(short_code IS NOT NULL), 0) AS short_codes
                 FROM cards WHERE batch_id IN (" . DB::in($ids) . ') GROUP BY batch_id',
                $ids
            ) as $s) {
                $stats[(int) $s['batch_id']] = $s;
            }
        }
        foreach ($rows as $i => $row) {
            foreach (['cards', 'available', 'used', 'printed', 'short_codes'] as $k) {
                $rows[$i][$k] = (int) ($stats[(int) $row['id']][$k] ?? 0);
            }
        }
        $categories = DB::pairs('SELECT id, name FROM categories WHERE is_custom = 0 ORDER BY sort, name');
        return view('batches/index', compact('rows', 'pager', 'categories'));
    }

    /** دفعة حساب خاص: تُدار من صفحة الحساب نفسه */
    private function accountRedirect(int $batchId): ?Response
    {
        if (DB::value("SELECT source FROM batches WHERE id = ?", [$batchId]) !== 'custom') {
            return null;
        }
        $card = CustomAccounts::cardForBatch($batchId);
        return $card ? redirect('/cards/accounts/' . $card . '/edit') : redirect('/cards/accounts');
    }

    public function show(Request $request, int $id): Response
    {
        if ($r = $this->accountRedirect($id)) {
            return $r;
        }
        $batch = DB::row(
            'SELECT b.*, c.name AS category_name, c.color, c.price, e.name AS employee_name
             FROM batches b JOIN categories c ON c.id = b.category_id LEFT JOIN employees e ON e.id = b.created_by WHERE b.id = ?',
            [$id]
        );
        $this->notFoundUnless($batch);
        $stats = DB::row(
            "SELECT COUNT(*) AS total, SUM(status = 'new' AND is_disabled = 0) AS available, SUM(status = 'sold') AS sold,
                    SUM(status = 'active') AS active, SUM(status IN ('expired','depleted')) AS ended, SUM(is_disabled = 1) AS disabled,
                    SUM(printed_at IS NOT NULL) AS printed, SUM(short_code IS NOT NULL) AS short_codes,
                    MIN(id) AS first_id, MAX(id) AS last_id, SUM(used_bytes_in + used_bytes_out) AS bytes, SUM(used_seconds) AS seconds
             FROM cards WHERE batch_id = ?",
            [$id]
        );
        $sample = DB::rows('SELECT id, username, password, short_code, short_password, status, is_disabled FROM cards WHERE batch_id = ? ORDER BY id LIMIT 10', [$id]);
        $templates = DB::pairs('SELECT id, name FROM print_templates ORDER BY is_default DESC, name');
        $routers = DB::pairs('SELECT id, name FROM routers WHERE is_active = 1 ORDER BY name');
        $linked = array_map('intval', DB::column('SELECT router_id FROM batch_routers WHERE batch_id = ?', [$id]));
        $catLimit = array_map('intval', DB::column('SELECT router_id FROM category_routers WHERE category_id = ?', [(int) $batch['category_id']]));
        return view('batches/show', compact('batch', 'stats', 'sample', 'templates', 'routers', 'linked', 'catLimit'));
    }

    /**
     * أجهزة MikroTik المرتبطة بالدفعة: كل الأجهزة، أو أجهزة محددة تعمل الكروت عليها فقط.
     * الأجهزة المضافة تُرفع إليها الكروت (إن طُلب)، والمُزالة تُحذف منها الكروت فوراً.
     */
    public function routers(Request $request, int $id): Response
    {
        if ($r = $this->accountRedirect($id)) {
            return $r;
        }
        $batch = DB::row('SELECT * FROM batches WHERE id = ?', [$id]);
        $this->notFoundUnless($batch);
        $old = array_map('intval', DB::column('SELECT router_id FROM batch_routers WHERE batch_id = ?', [$id]));
        $new = [];
        if ($request->str('router_mode') === 'selected') {
            $new = $request->ids('routers');
            if (!$new) {
                return $this->backWithErrors($request, ['routers' => 'اختر جهاز MikroTik واحداً على الأقل، أو اختر «كل الأجهزة».'], '/batches/' . $id);
            }
            $active = DB::column('SELECT id FROM routers WHERE is_active = 1 AND id IN (' . DB::in($new) . ')', $new);
            if (count($active) !== count($new)) {
                return $this->backWithErrors($request, ['routers' => 'أحد الأجهزة المختارة غير موجود أو غير مفعّل.'], '/batches/' . $id);
            }
            $catLimit = array_map('intval', DB::column('SELECT router_id FROM category_routers WHERE category_id = ?', [(int) $batch['category_id']]));
            if ($catLimit && array_diff($new, $catLimit)) {
                return $this->backWithErrors($request, ['routers' => 'باقة هذه الدفعة لا تعمل إلا على أجهزة محددة؛ اختر منها فقط.'], '/batches/' . $id);
            }
        }
        DB::transaction(static function () use ($id, $new) {
            DB::delete('batch_routers', 'batch_id = ?', [$id]);
            if ($new) {
                DB::insertMany('batch_routers', array_map(static fn ($r) => ['batch_id' => $id, 'router_id' => $r], $new));
            }
        });
        $cardIds = array_map('intval', DB::column('SELECT id FROM cards WHERE batch_id = ? ORDER BY id', [$id]));
        $jobs = [];
        $notes = [];
        if ($new && $cardIds) {
            // حذف الكروت من الأجهزة التي لم تعد مرتبطة بالدفعة
            $items = [];
            foreach (array_chunk($cardIds, 1000) as $chunk) {
                foreach (DB::rows("SELECT cr.router_id, cr.card_id, c.username FROM card_routers cr JOIN cards c ON c.id = cr.card_id WHERE cr.state = 'present' AND cr.card_id IN (" . DB::in($chunk) . ')', $chunk) as $r) {
                    if (!in_array((int) $r['router_id'], $new, true)) {
                        $items[] = [(int) $r['router_id'], (int) $r['card_id'], $r['username']];
                    }
                }
            }
            if ($items) {
                $jobs[] = JobRunner::create('remove_cards', 'حذف كروت الدفعة ' . $batch['name'] . ' من الأجهزة غير المرتبطة', ['items' => $items], count($items), Auth::id());
                $notes[] = 'تُحذف كروتها من الأجهزة الأخرى';
            }
            $added = $old ? array_values(array_diff($new, $old)) : $new;
            if ($added && $request->bool('push_now')) {
                $jobs[] = CardService::pushToRouters($cardIds, $added);
                $notes[] = 'تُرفع كروتها إلى الأجهزة المضافة';
            }
        }
        $names = $new ? implode('، ', DB::column('SELECT name FROM routers WHERE id IN (' . DB::in($new) . ') ORDER BY name', $new)) : '';
        Audit::log('batch.routers', $new ? 'ربط الدفعة ' . $batch['name'] . ' بالأجهزة: ' . $names : 'إتاحة الدفعة ' . $batch['name'] . ' على كل الأجهزة', 'batch', $id);
        $msg = $new ? 'الدفعة تعمل الآن فقط على: ' . $names . '.' : 'الدفعة تعمل الآن على كل الأجهزة.';
        if ($notes) {
            $msg .= ' ' . implode('، و', $notes) . '.';
        }
        if (count($jobs) === 1) {
            flash('success', $msg);
            return redirect('/jobs/' . $jobs[0]);
        }
        return $this->ok($msg, '/batches/' . $id);
    }

    public function update(Request $request, int $id): Response
    {
        if ($r = $this->accountRedirect($id)) {
            return $r;
        }
        $batch = DB::row('SELECT * FROM batches WHERE id = ?', [$id]);
        $this->notFoundUnless($batch);
        $d = $this->validate($request, ['name' => 'required|max:120', 'notes' => 'nullable|max:255'], ['name' => 'اسم الدفعة']);
        DB::update('batches', ['name' => $d['name'], 'notes' => $d['notes']], 'id = ?', [$id]);
        return $this->ok('تم حفظ الدفعة.', '/batches/' . $id);
    }

    public function destroy(Request $request, int $id): Response
    {
        if ($r = $this->accountRedirect($id)) {
            return $r;
        }
        $batch = DB::row('SELECT * FROM batches WHERE id = ?', [$id]);
        $this->notFoundUnless($batch);
        $used = (int) DB::value('SELECT COUNT(*) FROM cards WHERE batch_id = ? AND (first_login_at IS NOT NULL OR sale_id IS NOT NULL)', [$id]);
        if ($used > 0 && !$request->bool('force')) {
            return $this->backWithErrors($request, ['id' => 'تحتوي الدفعة على ' . $used . ' كرت مستخدم أو مباع. أكّد الحذف القسري لحذفها.'], '/batches/' . $id);
        }
        $ids = array_map('intval', DB::column('SELECT id FROM cards WHERE batch_id = ?', [$id]));
        $result = $ids ? CardService::delete($ids) : ['job' => null];
        DB::delete('batches', 'id = ?', [$id]);
        Audit::log('batch.delete', 'حذف الدفعة ' . $batch['name'] . ' (' . count($ids) . ' كرت)', 'batch', $id);
        if (!empty($result['job'])) {
            flash('success', 'تم حذف الدفعة، ويجري حذف كروتها من أجهزة MikroTik.');
            return redirect('/jobs/' . $result['job']);
        }
        return $this->ok('تم حذف الدفعة.', '/batches');
    }
}
