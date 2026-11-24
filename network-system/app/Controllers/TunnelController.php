<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Tunnel;
use Throwable;

/**
 * الأنفاق الآمنة: ربط جهاز MikroTik لا يملك عنواناً عاماً بالخادم.
 */
final class TunnelController extends Controller
{
    private function peer(int $id): array
    {
        $peer = Tunnel::find($id);
        if (!$peer) {
            throw new HttpException(404);
        }
        return $peer;
    }

    public function index(Request $request): Response
    {
        $routers = DB::pairs('SELECT host, name FROM routers');
        $rows = [];
        foreach (Tunnel::all() as $p) {
            $rows[] = $p + ['state' => Tunnel::state($p), 'router' => $routers[$p['address']] ?? null];
        }
        return view('tunnels/index', ['rows' => $rows, 'available' => Tunnel::available(), 'show' => $request->int('show')]);
    }

    public function store(Request $request): Response
    {
        $d = $this->validate($request, ['name' => 'required|max:100'], ['name' => 'اسم جهاز MikroTik']);
        try {
            $peer = Tunnel::create($d['name'], Auth::user()['name'] ?? null);
        } catch (Throwable $e) {
            return $this->backWithErrors($request, ['name' => $e->getMessage()], '/tunnels');
        }
        Audit::log('tunnel.create', 'إنشاء نفق آمن ' . $peer['name'] . ' (' . $peer['address'] . ')', 'tunnel', (int) $peer['id']);
        if ($request->wantsJson()) {
            return json_response(['ok' => true, 'id' => (int) $peer['id'], 'name' => $peer['name'], 'address' => $peer['address'], 'script' => Tunnel::script($peer)]);
        }
        return $this->ok('تم إنشاء النفق.', '/tunnels', ['show' => $peer['id']]);
    }

    public function script(Request $request, int $id): Response
    {
        $peer = $this->peer($id);
        return json_response(['ok' => true, 'name' => $peer['name'], 'address' => $peer['address'], 'script' => Tunnel::script($peer)]);
    }

    public function download(Request $request, int $id): Response
    {
        $peer = $this->peer($id);
        Audit::log('tunnel.download', 'تنزيل ملف النفق ' . $peer['name'], 'tunnel', $id);
        return Response::download(Tunnel::script($peer), 'madix-tunnel-' . str_replace('.', '-', $peer['address']) . '.rsc', 'text/plain; charset=utf-8');
    }

    public function regenerate(Request $request, int $id): Response
    {
        $peer = $this->peer($id);
        Tunnel::regenerate($id);
        Audit::log('tunnel.regenerate', 'مفاتيح جديدة للنفق ' . $peer['name'], 'tunnel', $id);
        return $this->ok('تم إنشاء مفاتيح جديدة.', '/tunnels', ['show' => $id]);
    }

    public function destroy(Request $request, int $id): Response
    {
        $peer = $this->peer($id);
        Tunnel::delete($id);
        Audit::log('tunnel.delete', 'حذف النفق ' . $peer['name'] . ' (' . $peer['address'] . ')', 'tunnel', $id);
        return $this->ok('تم حذف النفق.', '/tunnels');
    }
}
