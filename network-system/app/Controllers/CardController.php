<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\CardGenerator;
use App\Services\CardImporter;
use App\Services\CardQuery;
use App\Services\CardService;
use App\Services\ShortCodes;
use App\Services\Units;
use App\Support\Spreadsheet;
use RuntimeException;

final class CardController extends Controller
{
    private function filters(Request $request): array
    {
        $f = [];
        foreach (CardQuery::FILTERS as $k) {
            $f[$k] = $request->str($k);
        }
        return $f;
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        [$where, $params] = CardQuery::fromArray($filters);
        $total = (int) DB::value("SELECT COUNT(*) FROM cards c WHERE $where", $params);
        $pager = Paginator::fromRequest($request, $total, 50);
        // أرقام الصفحة أولاً من الفهرس، ثم تفاصيلها: لا ترتيب لكل الكروت لعرض خمسين منها
        $ids = array_map('intval', DB::column("SELECT c.id FROM cards c WHERE $where ORDER BY c.id DESC" . $pager->limitSql(), $params));
        $rows = $ids ? DB::rows(
            'SELECT c.*, cat.name AS category_name, cat.color AS category_color, b.name AS batch_name, d.name AS distributor_name
             FROM cards c JOIN categories cat ON cat.id = c.category_id JOIN batches b ON b.id = c.batch_id
             LEFT JOIN distributors d ON d.id = c.distributor_id
             WHERE c.id IN (' . DB::in($ids) . ') ORDER BY c.id DESC',
            $ids
        ) : [];
        $counts = DB::pairs("SELECT c.status, COUNT(*) FROM cards c WHERE $where GROUP BY c.status", $params);
        return view('cards/index', [
            'rows' => $rows, 'pager' => $pager, 'filters' => $filters, 'counts' => $counts, 'total' => $total,
            'categories' => DB::pairs('SELECT id, name FROM categories WHERE is_custom = 0 ORDER BY sort, name'),
            'hasAccounts' => (bool) DB::value('SELECT 1 FROM categories WHERE is_custom = 1 LIMIT 1'),
            'batches' => DB::pairs("SELECT id, name FROM batches WHERE source <> 'custom' ORDER BY id DESC LIMIT 300"),
            'distributors' => DB::pairs('SELECT id, name FROM distributors ORDER BY name'),
            'routers' => DB::pairs('SELECT id, name FROM routers WHERE is_active = 1 ORDER BY name'),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        return redirect('/check/card/' . $id);
    }

    public function export(Request $request): Response
    {
        $ids = $request->str('ids') !== ''
            ? array_values(array_filter(array_map('intval', explode(',', $request->str('ids')))))
            : CardQuery::ids($this->filters($request));
        return $this->download($ids, $request->str('format', 'xlsx'));
    }

    private function download(array $ids, string $format): Response
    {
        if (!$ids) {
            flash('error', 'لا توجد كروت للتصدير.');
            return back('/cards');
        }
        $rows = CardQuery::exportRows($ids, can('cards.passwords'));
        $name = 'cards-' . date('Ymd-His');
        Audit::log('cards.export', 'تصدير ' . count($ids) . ' كرت', 'card', null, ['format' => $format]);
        if ($format === 'csv' || !class_exists(\ZipArchive::class)) {
            return Response::download(Spreadsheet::csv(CardQuery::EXPORT_HEADER, $rows), $name . '.csv', 'text/csv; charset=UTF-8');
        }
        return Response::download(Spreadsheet::xlsx(CardQuery::EXPORT_HEADER, $rows, 'الكروت'), $name . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    // ---------------------------------------------------------------------
    // التوليد
    // ---------------------------------------------------------------------

    public function generateForm(Request $request): Response
    {
        $categories = DB::rows('SELECT * FROM categories WHERE is_active = 1 AND is_custom = 0 ORDER BY sort, price');
        $catRouters = [];
        foreach (DB::rows('SELECT category_id, router_id FROM category_routers') as $r) {
            $catRouters[(int) $r['category_id']][] = (int) $r['router_id'];
        }
        return view('cards/generate', [
            'categories' => $categories,
            'selected'   => $request->int('category'),
            'routers'    => DB::rows('SELECT id, name, host, status, identity FROM routers WHERE is_active = 1 ORDER BY name'),
            'catRouters' => $catRouters,
        ]);
    }

    /**
     * أجهزة MikroTik المختارة للدفعة: يجب أن تكون مفعّلة ومسموحة لباقة الكروت.
     * يعيد [] عند اختيار «كل الأجهزة»، ونص الخطأ عند اختيار غير صالح.
     */
    private function chosenRouters(Request $request, int $categoryId): array|string
    {
        if ($request->str('router_mode') !== 'selected') {
            return [];
        }
        $ids = $request->ids('routers');
        if (!$ids) {
            return 'اختر جهاز MikroTik واحداً على الأقل، أو اختر «كل الأجهزة».';
        }
        $active = array_map('intval', DB::column('SELECT id FROM routers WHERE is_active = 1 AND id IN (' . DB::in($ids) . ')', $ids));
        if (count($active) !== count($ids)) {
            return 'أحد الأجهزة المختارة غير موجود أو غير مفعّل.';
        }
        $allowed = array_map('intval', DB::column('SELECT router_id FROM category_routers WHERE category_id = ?', [$categoryId]));
        if ($allowed && array_diff($ids, $allowed)) {
            return 'هذه الباقة لا تعمل إلا على أجهزة محددة؛ اختر من الأجهزة المسموحة لها فقط.';
        }
        return $ids;
    }

    public function generate(Request $request): Response
    {
        $d = $this->validate($request, [
            'category_id'     => 'required|int',
            'quantity'        => 'required|int|between:1,' . CardGenerator::MAX_QUANTITY,
            'username_length' => 'required|int|between:4,32',
            'charset'         => 'required|in:' . implode(',', array_keys(CardGenerator::CHARSETS)),
            'password_mode'   => 'required|in:same,separate',
            'password_length' => 'nullable|int|between:3,32',
            'prefix'          => 'nullable|max:10|regex:/^[A-Za-z0-9]*$/',
            'short_length'    => 'nullable|int|between:' . ShortCodes::MIN_LENGTH . ',' . ShortCodes::MAX_LENGTH,
            'short_mode'      => 'nullable|in:random,sequence',
            'short_password_length' => 'nullable|int|between:0,' . ShortCodes::MAX_PASSWORD,
            'batch_name'      => 'nullable|max:120',
            'notes'           => 'nullable|max:255',
        ], [
            'category_id' => 'الباقة', 'quantity' => 'العدد', 'username_length' => 'طول الرمز', 'charset' => 'نوع الرموز',
            'password_length' => 'طول كلمة المرور', 'prefix' => 'البادئة', 'short_length' => 'طول الرقم المختصر', 'short_password_length' => 'طول كلمة المرور المختصرة', 'batch_name' => 'اسم الدفعة',
        ]);
        if (!$request->bool('with_short')) {
            $d['short_length'] = null;
        }
        $routerIds = $this->chosenRouters($request, (int) $d['category_id']);
        if (is_string($routerIds)) {
            return $this->backWithErrors($request, ['routers' => $routerIds], '/cards/generate');
        }
        try {
            Session::release();
            $result = CardGenerator::generate($d, \App\Core\Auth::id());
        } catch (RuntimeException $e) {
            Session::resume();
            return $this->backWithErrors($request, ['quantity' => $e->getMessage()], '/cards/generate');
        }
        Session::resume();
        Settings::setMany([
            'cards.username_length' => (string) $d['username_length'],
            'cards.charset' => $d['charset'],
            'cards.password_mode' => $d['password_mode'],
            'cards.password_length' => (string) ($d['password_length'] ?? 4),
        ]);
        if ($routerIds) {
            // الدفعة مرتبطة بالأجهزة المختارة: تعمل عليها فقط، وتُرفع إليها الآن إن طُلب
            DB::insertMany('batch_routers', array_map(static fn ($r) => ['batch_id' => (int) $result['batch_id'], 'router_id' => $r], $routerIds));
            $names = implode('، ', DB::column('SELECT name FROM routers WHERE id IN (' . DB::in($routerIds) . ') ORDER BY name', $routerIds));
            if ($request->bool('push_now')) {
                $cardIds = array_map('intval', DB::column('SELECT id FROM cards WHERE batch_id = ? ORDER BY id', [(int) $result['batch_id']]));
                $job = CardService::pushToRouters($cardIds, $routerIds);
                flash('success', 'تم توليد ' . num($result['count']) . ' كرت مرتبطة بـ: ' . $names . '. جارٍ رفعها إلى الأجهزة الآن.');
                return redirect('/jobs/' . $job);
            }
            return $this->ok('تم توليد ' . num($result['count']) . ' كرت تعمل فقط على: ' . $names . '.', '/batches/' . $result['batch_id']);
        }
        return $this->ok('تم توليد ' . num($result['count']) . ' كرت.', '/batches/' . $result['batch_id']);
    }

    // ---------------------------------------------------------------------
    // الاستيراد
    // ---------------------------------------------------------------------

    public function importForm(Request $request): Response
    {
        return view('cards/import', []);
    }

    public function importStage(Request $request): Response
    {
        if ($err = $request->uploadError('file')) {
            return $this->backWithErrors($request, ['file' => $err], '/cards/import');
        }
        try {
            $token = CardImporter::stage($request->file('file'), (string) $request->input('paste', ''));
        } catch (RuntimeException $e) {
            return $this->backWithErrors($request, ['file' => $e->getMessage()], '/cards/import');
        }
        return redirect('/cards/import/' . $token);
    }

    public function importPreview(Request $request, string $token): Response
    {
        try {
            $rows = CardImporter::load($token);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            return redirect('/cards/import');
        }
        $guess = CardImporter::guessColumns($rows);
        return view('cards/import-preview', [
            'token' => $token,
            'rows' => $rows,
            'preview' => array_slice($rows, 0, 15),
            'guess' => $guess,
            'categories' => DB::pairs('SELECT id, name FROM categories WHERE is_active = 1 AND is_custom = 0 ORDER BY sort, name'),
        ]);
    }

    public function importCommit(Request $request, string $token): Response
    {
        $d = $this->validate($request, [
            'category_id'  => 'required|int',
            'username_col' => 'required|int|min:0',
            'password_col' => 'required|int|min:-1',
            'serial_col'   => 'required|int|min:-1',
            'short_length' => 'nullable|int|between:' . ShortCodes::MIN_LENGTH . ',' . ShortCodes::MAX_LENGTH,
            'short_mode'   => 'nullable|in:random,tail,sequence',
            'short_start'  => 'nullable|int|min:0',
            'short_password_length' => 'nullable|int|between:0,' . ShortCodes::MAX_PASSWORD,
            'batch_name'   => 'nullable|max:120',
            'notes'        => 'nullable|max:255',
        ], ['category_id' => 'الباقة', 'username_col' => 'عمود اسم المستخدم', 'short_length' => 'طول الرقم المختصر', 'short_password_length' => 'طول كلمة المرور المختصرة']);
        $d['skip_header'] = $request->bool('skip_header');
        if (!$request->bool('with_short')) {
            $d['short_length'] = null;
        }
        try {
            Session::release();
            $r = CardImporter::commit($token, $d, \App\Core\Auth::id());
        } catch (RuntimeException $e) {
            Session::resume();
            return $this->backWithErrors($request, ['category_id' => $e->getMessage()], '/cards/import/' . $token);
        }
        Session::resume();
        $msg = 'تم استيراد ' . num($r['imported']) . ' كرت.';
        if ($r['existing'] || $r['duplicates'] || $r['invalid']) {
            $msg .= ' تُخطي: موجود مسبقاً ' . num($r['existing']) . '، مكرر في الملف ' . num($r['duplicates']) . '، غير صالح ' . num($r['invalid']) . '.';
        }
        flash($r['imported'] > 0 ? 'success' : 'warning', $msg);
        return $r['batch_id'] ? redirect('/batches/' . $r['batch_id']) : redirect('/cards/import');
    }

    // ---------------------------------------------------------------------
    // الإجراءات الجماعية
    // ---------------------------------------------------------------------

    public function bulk(Request $request): Response
    {
        $action = $request->str('action');
        $ids = $request->ids('ids');
        if ($request->bool('all_matching')) {
            $ids = CardQuery::ids($this->filters($request));
        }
        if (!$ids) {
            flash('error', 'حدد كرتاً واحداً على الأقل.');
            return back('/cards');
        }
        $need = match ($action) {
            'delete' => 'cards.delete',
            'short', 'clear_short' => 'cards.short',
            'export' => 'cards.export',
            'print' => 'cards.print',
            'sell' => 'sales.create',
            default => 'cards.manage',
        };
        if (!can($need)) {
            throw new HttpException(403);
        }
        try {
            switch ($action) {
                case 'disable':
                    $r = CardService::disable($ids, $request->str('reason') ?: null);
                    return $this->after($r, 'تم إيقاف ' . num($r['count']) . ' كرت.');
                case 'enable':
                    $r = CardService::enable($ids);
                    return $this->after($r, 'تم تفعيل ' . num($r['count']) . ' كرت.');
                case 'delete':
                    $r = CardService::delete($ids);
                    return $this->after($r, 'تم حذف ' . num($r['count']) . ' كرت.');
                case 'move':
                    $r = CardService::move($ids, $request->int('category_id'));
                    return $this->after($r, 'تم نقل ' . num($r['count']) . ' كرت.');
                case 'extend':
                    $r = CardService::extend(
                        $ids,
                        (int) Units::toSeconds($request->str('add_time'), $request->str('add_time_unit', 'hour')),
                        (int) Units::toBytes($request->str('add_data'), $request->str('add_data_unit', 'GB')),
                        (int) Units::toSeconds($request->str('add_validity'), $request->str('add_validity_unit', 'day'))
                    );
                    return $this->after($r, 'تم تمديد ' . num($r['count']) . ' كرت.');
                case 'unbind':
                    $r = CardService::unbind($ids);
                    return $this->after($r, 'تم فك ربط ' . num($r['count']) . ' كرت.');
                case 'mark_printed':
                    $n = CardService::markPrinted($ids, true);
                    return $this->ok('تم تعليم ' . num($n) . ' كرت كمطبوع.', '/cards');
                case 'unmark_printed':
                    $n = CardService::markPrinted($ids, false);
                    return $this->ok('تم إلغاء تعليم الطباعة.', '/cards');
                case 'push':
                    $routers = $request->ids('routers');
                    if (!$routers) {
                        throw new RuntimeException('اختر جهاز MikroTik واحداً على الأقل.');
                    }
                    return redirect('/jobs/' . CardService::pushToRouters($ids, $routers));
                case 'short':
                    $n = ShortCodes::assign($ids, $request->int('short_length', 6), $request->str('short_mode', 'random'), $request->bool('overwrite'), $request->str('short_start') !== '' ? $request->int('short_start') : null, $request->int('short_password_length', 0));
                    return $this->ok('تم تخصيص ' . num($n) . ' رقماً مختصراً.', '/cards');
                case 'clear_short':
                    $n = ShortCodes::clear($ids);
                    return $this->ok('تمت إزالة ' . num($n) . ' رقماً مختصراً.', '/cards');
                case 'export':
                    return $this->download($ids, $request->str('format', 'xlsx'));
                case 'print':
                    Session::set('print_selection', $ids);
                    return redirect('/print', ['source' => 'selection']);
                case 'sell':
                    Session::set('sale_selection', $ids);
                    return redirect('/sales/create', ['source' => 'selection']);
            }
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            return back('/cards');
        }
        throw new HttpException(400);
    }

    private function after(array $r, string $msg): Response
    {
        if (!empty($r['job'])) {
            flash('success', $msg . ' يجري تطبيق التغيير على أجهزة MikroTik.');
            return redirect('/jobs/' . $r['job']);
        }
        flash('success', $msg);
        return back('/cards');
    }
}
