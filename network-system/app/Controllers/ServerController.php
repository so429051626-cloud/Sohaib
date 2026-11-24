<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminAuth;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Platform;
use App\Core\Request;
use App\Core\Response;
use App\Core\Server;
use App\Core\Session;
use App\Services\ServerPush;
use Throwable;

/**
 * صفحة الخادم: الصحة الحية، الخدمات، النسخ الاحتياطي، التنبيهات، والنسخ الخارجي.
 * في وضع المنصة ضمن لوحة المسؤول، وفي تثبيت الشبكة الواحدة لمالك النظام.
 */
final class ServerController extends Controller
{
    private function base(): string
    {
        return Platform::enabled() ? '/admin/server' : '/server';
    }

    private function guard(): void
    {
        if (!Platform::enabled() && !Auth::isOwner()) {
            throw new HttpException(403, 'صفحة الخادم متاحة لمالك النظام فقط.');
        }
        if (!Server::managed()) {
            throw new HttpException(404);
        }
    }

    private function note(string $action, string $text): void
    {
        if (Platform::enabled()) {
            Platform::log(null, 'admin', $action, $text, AdminAuth::user()['name'] ?? null);
        } else {
            Audit::log($action, $text, 'server');
        }
    }

    /** البيانات الحية التي تعرضها الصفحة وتحدّثها دورياً */
    private function payload(): array
    {
        $s = Server::status();
        $alerts = [];
        foreach (array_slice(Server::alerts()['items'], 0, 30) as $a) {
            $alerts[] = ['id' => (int) ($a['id'] ?? 0), 'time' => (int) ($a['time'] ?? 0), 'level' => (string) ($a['level'] ?? 'info'), 'text' => ServerPush::alertText($a)];
        }
        $tunnel = $s['tunnel'] ?? [];
        $peers = is_array($tunnel['peers'] ?? null) ? $tunnel['peers'] : [];
        $now = time();
        return [
            'ok' => true,
            'now' => $now,
            'fresh' => Server::fresh($s),
            'time' => (int) ($s['time'] ?? 0),
            'version' => (string) ($s['version'] ?? ''),
            'host' => $s['host'] ?? [],
            'cpu' => $s['cpu'] ?? [],
            'mem' => $s['mem'] ?? [],
            'disk' => $s['disk'] ?? [],
            'services' => $s['services'] ?? [],
            'ssl' => $s['ssl'] ?? null,
            'updates' => $s['updates'] ?? [],
            'backup' => $s['backup'] ?? ['last' => 0, 'count' => 0, 'size' => 0, 'list' => []],
            'backup_pending' => Server::requested('backup'),
            'cron' => $s['cron'] ?? [],
            'software' => $s['software'] ?? [],
            'tunnel' => [
                'available' => !empty($tunnel['available']),
                'total' => count($peers),
                'connected' => count(array_filter($peers, static fn ($p) => (int) ($p['handshake'] ?? 0) > $now - 200)),
            ],
            'alerts' => $alerts,
            'offsite' => [
                'enabled' => ServerPush::offsiteEnabled(),
                'last' => Server::setting('offsite_last'),
                'status' => Server::setting('offsite_status'),
            ],
        ];
    }

    public function index(Request $request): Response
    {
        $this->guard();
        $sizes = Server::databaseSizes();
        $accounts = [];
        if (Platform::enabled()) {
            foreach (DB::rows('SELECT id, name, slug, db_name FROM tenants') as $t) {
                $accounts[] = ['id' => (int) $t['id'], 'name' => $t['name'], 'slug' => $t['slug'], 'size' => (int) ($sizes[$t['db_name']] ?? 0)];
            }
            usort($accounts, static fn ($a, $b) => $b['size'] <=> $a['size']);
        }
        return view('server/index', [
            'data' => $this->payload(),
            'base' => $this->base(),
            'accounts' => array_slice($accounts, 0, 8),
            'dbTotal' => array_sum($sizes),
            'config' => [
                'token_set' => ServerPush::token() !== '',
                'chat' => Server::setting('tg_chat'),
                'alerts' => Server::setting('alerts', '1') === '1',
                'offsite' => Server::setting('offsite') === '1',
                'pass_set' => Server::secret('offsite_pass') !== '',
            ],
        ], Platform::enabled() ? 'admin' : 'app');
    }

    public function status(Request $request): Response
    {
        $this->guard();
        return json_response($this->payload());
    }

    public function backup(Request $request): Response
    {
        $this->guard();
        if (!Server::request('backup')) {
            return json_response(['ok' => false, 'message' => 'تعذّر إرسال الطلب إلى الخادم.'], 500);
        }
        $this->note('server.backup', 'طلب نسخة احتياطية للخادم');
        return json_response(['ok' => true, 'message' => 'بدأ إنشاء النسخة الاحتياطية.']);
    }

    public function settings(Request $request): Response
    {
        $this->guard();
        $d = $this->validate($request, [
            'tg_token' => 'nullable|max:120|regex:/^\d{5,15}:[A-Za-z0-9_-]{20,80}$/',
            'tg_chat' => 'nullable|max:200',
            'offsite_pass' => 'nullable|min:8|max:100',
        ], ['tg_token' => 'رمز البوت', 'tg_chat' => 'معرّف المحادثة', 'offsite_pass' => 'كلمة مرور النسخ'], function ($v) use ($request) {
            $hasToken = $request->str('tg_token') !== '' || ServerPush::token() !== '';
            $hasPass = (string) $request->input('offsite_pass', '') !== '' || Server::secret('offsite_pass') !== '';
            if ($request->bool('offsite') && !$hasPass) {
                $v->addError('offsite_pass', 'أدخل كلمة مرور لتشفير النسخ.');
            }
            if (($request->bool('offsite') || $request->bool('alerts')) && (!$hasToken || trim($request->str('tg_chat')) === '')) {
                $v->addError('tg_token', 'أدخل رمز البوت ومعرّف المحادثة.');
            }
        });
        if ($request->str('tg_token') !== '') {
            Server::setSecret('tg_token', $request->str('tg_token'));
        } elseif ($request->bool('tg_clear')) {
            Server::setSecret('tg_token', '');
        }
        if ((string) $request->input('offsite_pass', '') !== '') {
            Server::setSecret('offsite_pass', (string) $request->input('offsite_pass'));
        }
        Server::setSettings([
            'tg_chat' => trim((string) $d['tg_chat']),
            'alerts' => $request->bool('alerts') ? '1' : '0',
            'offsite' => $request->bool('offsite') ? '1' : '0',
        ]);
        $this->note('server.settings', 'تعديل إشعارات الخادم والنسخ الخارجي');
        return $this->ok('تم حفظ الإعدادات.', $this->base());
    }

    public function test(Request $request): Response
    {
        $this->guard();
        Session::release();
        if (($typed = \App\Services\Telegram::typedToken($request->str('token'))) === null) {
            return json_response(['ok' => false, 'message' => 'رمز البوت غير صالح.'], 422);
        }
        try {
            $r = ServerPush::test($typed, $request->str('chat'));
        } catch (Throwable $e) {
            $r = ['ok' => false, 'message' => $e->getMessage()];
        }
        return json_response($r, $r['ok'] ? 200 : 422);
    }

    /** المحادثات التي راسلت البوت مؤخراً (لتعبئة معرّف المحادثة) */
    public function detect(Request $request): Response
    {
        $this->guard();
        Session::release();
        if (($typed = \App\Services\Telegram::typedToken($request->str('token'))) === null) {
            return json_response(['ok' => false, 'message' => 'رمز البوت غير صالح.'], 422);
        }
        $token = $typed !== '' ? $typed : ServerPush::token();
        $r = \App\Services\Telegram::recentChats($token);
        $r['message'] = str_replace($token, '***', $r['message']);
        return json_response($r, $r['ok'] ? 200 : 422);
    }
}
