<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Tenant;
use App\Services\Ai;
use App\Services\Assistant;

/**
 * المساعد الذكي داخل نظام الشبكة: محادثة تجيب من بيانات الشبكة، وإجراءات لا تُنفَّذ إلا بزر «تأكيد».
 */
final class AssistantController extends Controller
{
    /** @return array{used:int, limit:int} استهلاك الحساب هذا الشهر */
    private function usage(): array
    {
        $t = Tenant::current();
        return $t ? ['used' => Ai::used((int) $t['id']), 'limit' => Tenant::aiLimit($t)] : ['used' => 0, 'limit' => 0];
    }

    public function index(Request $request): Response
    {
        if (!Assistant::visible()) {
            throw new HttpException(404);
        }
        return view('assistant/index', ['state' => Ai::status(), 'samples' => Assistant::samples()] + $this->usage());
    }

    public function ask(Request $request): Response
    {
        if (!Assistant::visible()) {
            return json_response(['ok' => false, 'message' => Ai::stateLabel(Ai::status())], 422);
        }
        $in = $request->json();
        $messages = (array) ($in['messages'] ?? []);
        // إجراءات هذا الرد تُحفظ في الجلسة قبل إغلاقها، والطلب الطويل لا يقفل بقية الصفحات
        $r = Assistant::ask($messages);
        Session::release();
        return json_response($r + $this->usage(), $r['ok'] ? 200 : 422);
    }

    public function confirm(Request $request): Response
    {
        if (!Assistant::visible()) {
            return json_response(['ok' => false, 'message' => Ai::stateLabel(Ai::status())], 422);
        }
        $in = $request->json();
        $values = is_array($in['values'] ?? null) ? $in['values'] : [];
        $r = Assistant::confirm((string) ($in['id'] ?? $request->str('id')), $values);
        return json_response($r, $r['ok'] ? 200 : 422);
    }
}
