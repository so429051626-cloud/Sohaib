<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;

final class AreaController extends Controller
{
    public function index(Request $request): Response
    {
        $areas = DB::rows(
            'SELECT a.*, (SELECT COUNT(*) FROM routers r WHERE r.area_id = a.id) AS routers,
                    (SELECT COUNT(*) FROM subscribers s WHERE s.area_id = a.id) AS subscribers,
                    (SELECT COUNT(*) FROM distributors d WHERE d.area_id = a.id) AS distributors
             FROM areas a ORDER BY a.name'
        );
        $edit = $request->int('edit') > 0 ? DB::row('SELECT * FROM areas WHERE id = ?', [$request->int('edit')]) : null;
        return view('areas/index', compact('areas', 'edit'));
    }

    public function save(Request $request): Response
    {
        $id = $request->int('id');
        $data = $this->validate($request, ['name' => 'required|max:100', 'notes' => 'nullable|max:255'], ['name' => 'اسم المنطقة', 'notes' => 'ملاحظات'], function ($v) use ($request, $id) {
            if (DB::value('SELECT id FROM areas WHERE name = ? AND id <> ?', [$request->str('name'), $id])) {
                $v->addError('name', 'توجد منطقة بهذا الاسم.');
            }
        });
        if ($id > 0) {
            DB::update('areas', ['name' => $data['name'], 'notes' => $data['notes']], 'id = ?', [$id]);
        } else {
            $id = DB::insert('areas', ['name' => $data['name'], 'notes' => $data['notes'], 'created_at' => now()]);
        }
        Audit::log('area.save', 'حفظ المنطقة ' . $data['name'], 'area', $id);
        return $this->ok('تم حفظ المنطقة.', '/areas');
    }

    public function destroy(Request $request, int $id): Response
    {
        $area = DB::row('SELECT * FROM areas WHERE id = ?', [$id]);
        $this->notFoundUnless($area);
        DB::delete('areas', 'id = ?', [$id]);
        Audit::log('area.delete', 'حذف المنطقة ' . $area['name'], 'area', $id);
        return $this->ok('تم حذف المنطقة.', '/areas');
    }
}
