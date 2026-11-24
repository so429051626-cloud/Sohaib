<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\ShortCodes;
use RuntimeException;

/**
 * مختصِر الأرقام: ربط رقم قصير بكل كرت طويل (مثل كروت شركة الاتصالات 12+6).
 */
final class ShortenerController extends Controller
{
    public function index(Request $request): Response
    {
        $batches = DB::rows(
            "SELECT b.id, b.name, c.name AS category_name, b.source,
                    COUNT(k.id) AS total, SUM(k.short_code IS NOT NULL) AS with_short,
                    MAX(CHAR_LENGTH(k.username)) AS max_len
             FROM batches b JOIN categories c ON c.id = b.category_id LEFT JOIN cards k ON k.batch_id = b.id
             WHERE b.source <> 'custom'
             GROUP BY b.id ORDER BY b.id DESC LIMIT 200"
        );
        $stats = DB::row('SELECT COUNT(*) AS total, SUM(short_code IS NOT NULL) AS with_short FROM cards');
        $lengths = DB::pairs('SELECT CHAR_LENGTH(short_code) AS l, COUNT(*) FROM cards WHERE short_code IS NOT NULL GROUP BY l ORDER BY l');
        $categories = DB::pairs('SELECT id, name FROM categories WHERE is_custom = 0 ORDER BY sort, name');
        $lookup = null;
        if (($q = trim(latin_digits($request->str('lookup')))) !== '') {
            $lookup = DB::row('SELECT c.id, c.username, c.short_code, c.short_password, cat.name AS category_name FROM cards c JOIN categories cat ON cat.id = c.category_id WHERE c.short_code = ? OR c.username = ?', [$q, $q]) ?: false;
        }
        return view('shortener/index', [
            'batches' => $batches, 'stats' => $stats, 'lengths' => $lengths, 'categories' => $categories,
            'selectedBatch' => $request->int('batch'), 'lookup' => $lookup, 'lookupQuery' => $q,
        ]);
    }

    public function apply(Request $request): Response
    {
        $source = $request->str('source');
        $where = '1 = 1';
        $params = [];
        if ($source === 'batch') {
            $where .= ' AND batch_id = ?';
            $params[] = $request->int('batch_id');
        } elseif ($source === 'category') {
            $where .= ' AND category_id = ?';
            $params[] = $request->int('category_id');
        } elseif ($source !== 'all') {
            return $this->backWithErrors($request, ['source' => 'اختر نطاق الكروت.'], '/shortener');
        }
        if ($request->bool('only_available')) {
            $where .= " AND status IN ('new','sold')";
        }
        $ids = array_map('intval', DB::column("SELECT id FROM cards WHERE $where ORDER BY id", $params));
        if (!$ids) {
            return $this->backWithErrors($request, ['source' => 'لا توجد كروت ضمن النطاق المحدد.'], '/shortener');
        }
        try {
            Session::release();
            if ($request->str('op') === 'clear') {
                $n = ShortCodes::clear($ids);
                $msg = 'تمت إزالة ' . num($n) . ' رقماً مختصراً.';
            } else {
                $n = ShortCodes::assign(
                    $ids,
                    $request->int('short_length', 6),
                    $request->str('short_mode', 'random'),
                    $request->bool('overwrite'),
                    $request->str('short_start') !== '' ? $request->int('short_start') : null,
                    $request->int('short_password_length', 0)
                );
                $msg = 'تم تخصيص ' . num($n) . ' رقماً مختصراً.';
            }
        } catch (RuntimeException $e) {
            return $this->backWithErrors($request, ['short_length' => $e->getMessage()], '/shortener');
        }
        return $this->ok($msg, '/shortener');
    }
}
