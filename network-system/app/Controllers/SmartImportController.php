<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Tenant;
use App\Services\Ai;
use App\Services\SmartImport;
use RuntimeException;

/**
 * الاستيراد الذكي للمشتركين: ترتيب القائمة أو الصورة في جدول، ثم المراجعة، ثم الحفظ بعد موافقة المستخدم.
 */
final class SmartImportController extends Controller
{
    public function index(Request $request): Response
    {
        return view('subscribers/smart-import', [
            'plans' => DB::pairs('SELECT id, name FROM plans WHERE is_active = 1 ORDER BY sort, price'),
            'routers' => DB::pairs('SELECT r.id, r.name FROM routers r WHERE r.is_active = 1' . Auth::routerScope('r.id')[0] . ' ORDER BY r.name', Auth::routerScope('r.id')[1]),
            'aiOffered' => Ai::offered(), 'aiOk' => Ai::available(), 'aiState' => Ai::stateLabel(Ai::status()),
        ]);
    }

    /** ترتيب النص الملصوق و/أو صور الدفتر في جدول (لا يُحفظ شيء) */
    public function parse(Request $request): Response
    {
        $images = [];
        $files = $request->files['images'] ?? null;
        if (is_array($files) && isset($files['tmp_name'])) {
            $tmp = (array) $files['tmp_name'];
            foreach ($tmp as $i => $path) {
                if (!is_string($path) || $path === '' || (int) ((array) $files['error'])[$i] !== UPLOAD_ERR_OK) {
                    continue;
                }
                if (count($images) >= SmartImport::MAX_IMAGES) {
                    return json_response(['ok' => false, 'message' => 'ثلاث صور على الأكثر في المرة الواحدة.'], 422);
                }
                $size = (int) ((array) $files['size'])[$i];
                $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
                if (!in_array($mime, SmartImport::IMAGE_TYPES, true)) {
                    return json_response(['ok' => false, 'message' => 'الصور بصيغة JPG أو PNG أو WEBP فقط.'], 422);
                }
                if ($size > SmartImport::MAX_IMAGE_BYTES) {
                    return json_response(['ok' => false, 'message' => 'حجم الصورة أكبر من 5 ميغابايت.'], 422);
                }
                $images[] = ['mime' => $mime, 'data' => base64_encode((string) file_get_contents($path))];
            }
        }
        Session::release();
        try {
            $r = SmartImport::parse($request->str('text'), $images);
        } catch (RuntimeException $e) {
            return json_response(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        if (!$r['rows']) {
            return json_response(['ok' => false, 'message' => 'لم يُعثر على مشتركين في ما أُدخل.'], 422);
        }
        return json_response(['ok' => true, 'rows' => $r['rows'], 'source' => $r['source'], 'count' => count($r['rows'])]);
    }

    /** الحفظ بعد المراجعة: كل الصفوف أو لا شيء */
    public function save(Request $request): Response
    {
        $in = $request->json();
        $raw = array_slice((array) ($in['rows'] ?? []), 0, SmartImport::MAX_ROWS);
        $rows = [];
        foreach ($raw as $r) {
            if (is_array($r)) {
                $rows[] = SmartImport::clean(array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $r));
            }
        }
        if (!$rows) {
            return json_response(['ok' => false, 'message' => 'لا توجد صفوف للحفظ.'], 422);
        }
        $plan = (int) ($in['plan_id'] ?? 0);
        if ($plan && !DB::value('SELECT 1 FROM plans WHERE id = ? AND is_active = 1', [$plan])) {
            $plan = 0;
        }
        $router = (int) ($in['router_id'] ?? 0);
        if ($router && (!DB::value('SELECT 1 FROM routers WHERE id = ?', [$router]) || !Auth::canRouter($router))) {
            return json_response(['ok' => false, 'message' => 'جهاز MikroTik غير متاح.'], 422);
        }
        if (!$router && Auth::routerIds() !== null) {
            return json_response(['ok' => false, 'message' => 'اختر جهاز MikroTik.'], 422);
        }
        $current = Tenant::current();
        if ($current && !empty($current['max_subscribers']) && (int) DB::value('SELECT COUNT(*) FROM subscribers') + count($rows) > (int) $current['max_subscribers']) {
            return json_response(['ok' => false, 'message' => 'يتجاوز الاستيراد الحد الأقصى للمشتركين في باقتك (' . (int) $current['max_subscribers'] . ').'], 422);
        }
        $errors = SmartImport::validate($rows, $plan);
        if ($errors) {
            return json_response(['ok' => false, 'message' => 'صحّح ' . ar_plural(count($errors), 'الصف المعلَّم', 'الصفين المعلَّمين', 'الصفوف المعلَّمة', 'صفاً معلَّماً') . ' ثم احفظ.', 'errors' => $errors], 422);
        }
        try {
            $ids = SmartImport::save($rows, $plan, $router ?: null);
        } catch (RuntimeException $e) {
            return json_response(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        flash('success', 'تمت إضافة ' . ar_plural(count($ids), 'مشترك واحد', 'مشتركين', 'مشتركين', 'مشتركاً') . '.');
        return json_response(['ok' => true, 'count' => count($ids), 'redirect' => url('/subscribers')]);
    }
}
