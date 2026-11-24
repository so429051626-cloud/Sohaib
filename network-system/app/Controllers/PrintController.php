<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;
use App\Services\CardService;
use App\Services\PrintTemplates;

final class PrintController extends Controller
{
    public const MAX_CARDS = 5000;

    public function index(Request $request): Response
    {
        PrintTemplates::ensureDefault();
        $templates = DB::rows('SELECT t.*, c.name AS category_name FROM print_templates t LEFT JOIN categories c ON c.id = t.category_id ORDER BY t.is_default DESC, t.name');
        $selection = (array) Session::get('print_selection', []);
        return view('print/index', [
            'templates' => $templates,
            'batches' => DB::rows('SELECT b.id, b.name, (SELECT COUNT(*) FROM cards k WHERE k.batch_id = b.id) AS cards, (SELECT COUNT(*) FROM cards k WHERE k.batch_id = b.id AND k.printed_at IS NULL) AS unprinted FROM batches b WHERE b.source <> \'custom\' ORDER BY b.id DESC LIMIT 200'),
            'categories' => DB::rows("SELECT c.id, c.name, (SELECT COUNT(*) FROM cards k WHERE k.category_id = c.id AND k.status = 'new' AND k.printed_at IS NULL AND k.is_disabled = 0) AS unprinted FROM categories c WHERE c.is_custom = 0 ORDER BY c.sort, c.name"),
            'selection' => $request->str('source') === 'selection' ? $selection : [],
            'request' => $request,
        ]);
    }

    /** تحديد الكروت المطلوب طباعتها حسب المصدر */
    private function resolveIds(Request $request): array
    {
        switch ($request->str('source')) {
            case 'batch':
                $sql = 'SELECT id FROM cards WHERE batch_id = ?';
                $params = [$request->int('batch')];
                if ($request->bool('unprinted')) {
                    $sql .= ' AND printed_at IS NULL';
                }
                return array_map('intval', DB::column($sql . ' ORDER BY id LIMIT ' . self::MAX_CARDS, $params));
            case 'category':
                $sql = "SELECT id FROM cards WHERE category_id = ? AND is_disabled = 0 AND status IN ('new','sold')";
                if ($request->bool('unprinted')) {
                    $sql .= ' AND printed_at IS NULL';
                }
                $limit = max(1, min(self::MAX_CARDS, $request->int('count', 40)));
                return array_map('intval', DB::column($sql . ' ORDER BY id LIMIT ' . $limit, [$request->int('category')]));
            case 'range':
                $from = $request->int('from');
                $to = $request->int('to');
                if ($from <= 0 || $to < $from) {
                    return [];
                }
                return array_map('intval', DB::column('SELECT id FROM cards WHERE id BETWEEN ? AND ? ORDER BY id LIMIT ' . self::MAX_CARDS, [$from, $to]));
            case 'selection':
                return array_slice(array_map('intval', (array) Session::get('print_selection', [])), 0, self::MAX_CARDS);
            case 'ids':
                return array_slice(array_values(array_filter(array_map('intval', explode(',', $request->str('ids'))))), 0, self::MAX_CARDS);
            case 'sale':
                return array_map('intval', DB::column('SELECT id FROM cards WHERE sale_id = ? ORDER BY id LIMIT ' . self::MAX_CARDS, [$request->int('sale')]));
        }
        return [];
    }

    public function render(Request $request): Response
    {
        PrintTemplates::ensureDefault();
        $template = $request->int('template') > 0
            ? DB::row('SELECT * FROM print_templates WHERE id = ?', [$request->int('template')])
            : DB::row('SELECT * FROM print_templates ORDER BY is_default DESC, id LIMIT 1');
        $this->notFoundUnless($template);
        $ids = $this->resolveIds($request);
        if (!$ids) {
            flash('error', 'لا توجد كروت ضمن التحديد.');
            return redirect('/print');
        }
        $cards = PrintTemplates::cardData($ids);
        $html = View::render('print/render', [
            'template' => $template,
            'design' => json_decode((string) $template['design'], true) ?: PrintTemplates::defaultDesign(),
            'cards' => $cards,
            'ids' => $ids,
            'markPrinted' => $request->bool('mark', true),
            'logo' => (string) Settings::get('general.logo', ''),
        ], 'print');
        return new Response($html);
    }

    public function mark(Request $request): Response
    {
        $ids = $request->ids('ids');
        $n = CardService::markPrinted($ids, true);
        Audit::log('cards.print', 'طباعة ' . count($ids) . ' كرت', 'card');
        return json_response(['ok' => true, 'count' => $n]);
    }

    // ---------------------------------------------------------------------
    // القوالب
    // ---------------------------------------------------------------------

    public function create(Request $request): Response
    {
        return $this->designer(null);
    }

    public function edit(Request $request, int $id): Response
    {
        $t = DB::row('SELECT * FROM print_templates WHERE id = ?', [$id]);
        $this->notFoundUnless($t);
        return $this->designer($t);
    }

    private function designer(?array $t): Response
    {
        $template = $t ?? [
            'id' => 0, 'name' => 'قالب جديد', 'paper' => 'A4', 'paper_width' => 210, 'paper_height' => 297,
            'cols' => 4, 'rows' => 10, 'margin_top' => 8, 'margin_right' => 8, 'margin_bottom' => 8, 'margin_left' => 8,
            'gap_x' => 2, 'gap_y' => 2, 'design' => json_encode(PrintTemplates::defaultDesign(), JSON_UNESCAPED_UNICODE),
            'category_id' => null, 'is_default' => 0,
        ];
        return view('print/designer', [
            'template' => $template,
            'design' => json_decode((string) $template['design'], true) ?: PrintTemplates::defaultDesign(),
            'sample' => PrintTemplates::sample(),
            'categories' => DB::pairs('SELECT id, name FROM categories WHERE is_custom = 0 ORDER BY sort, name'),
            'logo' => (string) Settings::get('general.logo', ''),
        ]);
    }

    public function save(Request $request): Response
    {
        $data = $request->json() ?: $request->all();
        $id = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            return json_response(['ok' => false, 'message' => 'أدخل اسماً للقالب (حتى 100 حرف).'], 422);
        }
        $paper = (string) ($data['paper'] ?? 'A4');
        if (!isset(PrintTemplates::PAPERS[$paper])) {
            $paper = 'A4';
        }
        $num = static fn ($v, float $min, float $max) => max($min, min($max, (float) $v));
        $pw = $paper === 'custom' ? $num($data['paper_width'] ?? 210, 30, 1000) : PrintTemplates::PAPERS[$paper][1];
        $ph = $paper === 'custom' ? $num($data['paper_height'] ?? 297, 30, 1000) : PrintTemplates::PAPERS[$paper][2];
        if (($data['orientation'] ?? 'portrait') === 'landscape' && $paper !== 'custom') {
            [$pw, $ph] = [$ph, $pw];
        }
        $row = [
            'name' => $name,
            'paper' => $paper,
            'paper_width' => $pw,
            'paper_height' => $ph,
            'cols' => (int) $num($data['cols'] ?? 4, 1, 20),
            'rows' => (int) $num($data['rows'] ?? 10, 1, 40),
            'margin_top' => $num($data['margin_top'] ?? 8, 0, 100),
            'margin_right' => $num($data['margin_right'] ?? 8, 0, 100),
            'margin_bottom' => $num($data['margin_bottom'] ?? 8, 0, 100),
            'margin_left' => $num($data['margin_left'] ?? 8, 0, 100),
            'gap_x' => $num($data['gap_x'] ?? 2, 0, 50),
            'gap_y' => $num($data['gap_y'] ?? 2, 0, 50),
            'design' => json_encode(PrintTemplates::sanitizeDesign($data['design'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'category_id' => !empty($data['category_id']) && DB::value('SELECT id FROM categories WHERE id = ?', [(int) $data['category_id']]) ? (int) $data['category_id'] : null,
            'is_default' => !empty($data['is_default']) ? 1 : 0,
        ];
        $cardW = ($pw - $row['margin_left'] - $row['margin_right'] - $row['gap_x'] * ($row['cols'] - 1)) / $row['cols'];
        $cardH = ($ph - $row['margin_top'] - $row['margin_bottom'] - $row['gap_y'] * ($row['rows'] - 1)) / $row['rows'];
        if ($cardW < 10 || $cardH < 8) {
            return json_response(['ok' => false, 'message' => 'مقاس الكرت الناتج صغير جداً. قلّل عدد الصفوف أو الأعمدة أو الهوامش.'], 422);
        }
        DB::transaction(static function () use (&$id, $row) {
            if ($row['is_default']) {
                DB::query('UPDATE print_templates SET is_default = 0');
            }
            if ($id > 0 && DB::value('SELECT id FROM print_templates WHERE id = ?', [$id])) {
                DB::update('print_templates', $row + ['updated_at' => now()], 'id = ?', [$id]);
            } else {
                $id = DB::insert('print_templates', $row + ['created_by' => Auth::id(), 'created_at' => now()]);
            }
        });
        Audit::log('template.save', 'حفظ قالب الطباعة ' . $name, 'template', $id);
        return json_response(['ok' => true, 'id' => $id, 'message' => 'تم حفظ القالب.']);
    }

    public function destroy(Request $request, int $id): Response
    {
        $t = DB::row('SELECT * FROM print_templates WHERE id = ?', [$id]);
        $this->notFoundUnless($t);
        if ((int) DB::value('SELECT COUNT(*) FROM print_templates') <= 1) {
            return $this->backWithErrors($request, ['id' => 'لا يمكن حذف القالب الوحيد.'], '/print');
        }
        DB::delete('print_templates', 'id = ?', [$id]);
        Audit::log('template.delete', 'حذف قالب الطباعة ' . $t['name'], 'template', $id);
        return $this->ok('تم حذف القالب.', '/print');
    }

    public function duplicate(Request $request, int $id): Response
    {
        $t = DB::row('SELECT * FROM print_templates WHERE id = ?', [$id]);
        $this->notFoundUnless($t);
        unset($t['id']);
        $t['name'] = mb_substr($t['name'] . ' (نسخة)', 0, 100);
        $t['is_default'] = 0;
        $t['created_at'] = now();
        $t['updated_at'] = null;
        $t['created_by'] = Auth::id();
        $newId = DB::insert('print_templates', $t);
        return $this->ok('تم نسخ القالب.', '/print/templates/' . $newId);
    }

    /** رفع صورة لتصميم الكرت */
    public function upload(Request $request): Response
    {
        if ($err = $request->uploadError('image')) {
            return json_response(['ok' => false, 'message' => $err], 422);
        }
        $file = $request->file('image');
        if (!$file) {
            return json_response(['ok' => false, 'message' => 'اختر صورة.'], 422);
        }
        $path = SettingsController::saveImage($file, 'card', 3 * 1048576);
        if ($path === null) {
            return json_response(['ok' => false, 'message' => 'الصورة يجب أن تكون PNG أو JPG أو WEBP بحجم لا يتجاوز 3 ميغابايت.'], 422);
        }
        return json_response(['ok' => true, 'path' => $path, 'url' => url($path)]);
    }
}
