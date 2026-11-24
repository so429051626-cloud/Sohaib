<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Platform;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Tenant;
use App\Services\BotAgent;

/**
 * نظام صاحب الشبكة: رسائل مشتركيه على واتساب المنصة. يرد عليها المساعد الآلي للمنصة،
 * وما يحتاج شخصاً منها يُحوَّل لصاحب الشبكة هنا ليرد بنفسه (الرد يصل المشترك على واتساب من رقم المنصة).
 * المحادثات في قاعدة المنصة، ولا يرى صاحب الشبكة إلا محادثات مشتركي شبكته.
 */
final class InboxController extends Controller
{
    private const FILTERS = ['waiting' => 'بانتظار ردك', 'open' => 'المفتوحة', 'all' => 'الكل'];

    /** هل الصفحة متاحة: وضع المنصة وبوتها يعمل */
    public static function available(): bool
    {
        return Platform::enabled() && Tenant::current() !== null && BotAgent::enabled();
    }

    /** المحادثات التي تنتظر رد صاحب هذه الشبكة (لشارة القائمة) */
    public static function waiting(): int
    {
        try {
            return (int) Platform::value(
                "SELECT COUNT(*) FROM platform_bot_chats WHERE tenant_id = ? AND kind = 'subscriber' AND handler = 'network' AND needs_human = 1 AND status = 'open'",
                [Tenant::id()]
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    private function chatOr404(int $id): array
    {
        $chat = Platform::row("SELECT * FROM platform_bot_chats WHERE id = ? AND tenant_id = ? AND kind = 'subscriber' AND channel <> 'test'", [$id, Tenant::id()]);
        $this->notFoundUnless($chat);
        return (array) $chat;
    }

    public function index(Request $request): Response
    {
        $this->notFoundUnless(self::available());
        $f = isset(self::FILTERS[$request->str('f')]) ? $request->str('f') : (self::waiting() > 0 ? 'waiting' : 'open');
        $where = "tenant_id = ? AND kind = 'subscriber' AND channel <> 'test'";
        $params = [Tenant::id()];
        if ($f === 'waiting') {
            $where .= " AND handler = 'network' AND needs_human = 1 AND status = 'open'";
        } elseif ($f === 'open') {
            $where .= " AND status = 'open'";
        }
        $total = (int) Platform::value("SELECT COUNT(*) FROM platform_bot_chats WHERE $where", $params);
        $pager = Paginator::fromRequest($request, $total, 30);
        $chats = Platform::rows("SELECT * FROM platform_bot_chats WHERE $where ORDER BY (handler = 'network' AND needs_human = 1) DESC, last_at DESC" . $pager->limitSql(), $params);
        $ids = array_values(array_filter(array_map(static fn ($c) => (int) $c['subscriber_id'], $chats)));
        $names = $ids ? DB::pairs('SELECT id, full_name FROM subscribers WHERE id IN (' . DB::in($ids) . ')', $ids) : [];
        return view('inbox/index', ['request' => $request, 'chats' => $chats, 'names' => $names, 'pager' => $pager, 'f' => $f, 'filters' => self::FILTERS, 'waiting' => self::waiting()]);
    }

    public function show(Request $request, int $id): Response
    {
        $this->notFoundUnless(self::available());
        $chat = $this->chatOr404($id);
        if ((int) $chat['unread'] > 0 && $chat['handler'] === 'network') {
            Platform::update('platform_bot_chats', ['unread' => 0], 'id = ?', [$id]);
        }
        $messages = array_reverse(Platform::rows('SELECT * FROM platform_bot_messages WHERE chat_id = ? ORDER BY id DESC LIMIT 200', [$id]));
        // مشتركو هذا الرقم في الشبكة (من التعرّف على الرقم)
        $subs = array_values(array_map(static fn ($x) => (int) $x['s'], array_filter((array) (BotAgent::meta($chat)['id']['subs'] ?? []), static fn ($x) => is_array($x) && (int) ($x['t'] ?? 0) === (int) Tenant::id())));
        $subscribers = $subs ? DB::rows('SELECT s.*, p.name AS plan FROM subscribers s JOIN plans p ON p.id = s.plan_id WHERE s.id IN (' . DB::in($subs) . ') ORDER BY s.full_name', $subs) : [];
        return view('inbox/show', ['chat' => $chat, 'messages' => $messages, 'subscribers' => $subscribers]);
    }

    public function poll(Request $request, int $id): Response
    {
        Session::release();
        $this->notFoundUnless(self::available());
        $chat = $this->chatOr404($id);
        return json_response(AdminBotController::pollData($chat, max(0, $request->int('after')), $chat['handler'] === 'network', true));
    }

    public function reply(Request $request, int $id): Response
    {
        $this->notFoundUnless(self::available());
        $chat = $this->chatOr404($id);
        $name = trim((string) (Auth::user()['name'] ?? ''));
        $r = BotAgent::staffReply($chat, $request->str('body'), $name !== '' ? $name : \App\Core\Settings::networkName(), 'network');
        if ($request->wantsJson()) {
            return json_response($r, $r['ok'] ? 200 : 422);
        }
        if (!$r['ok']) {
            return $this->backWithErrors($request, ['body' => $r['message']], '/inbox/' . $id);
        }
        return redirect('/inbox/' . $id);
    }

    /** استلام المحادثة من المساعد الآلي، أو إعادتها له */
    public function mode(Request $request, int $id): Response
    {
        $this->notFoundUnless(self::available());
        $chat = $this->chatOr404($id);
        $toBot = $request->str('mode') === 'bot';
        $name = trim((string) (Auth::user()['name'] ?? ''));
        Platform::update('platform_bot_chats', $toBot
            ? ['mode' => 'bot', 'needs_human' => 0]
            : ['mode' => 'human', 'needs_human' => 0, 'handler' => 'network', 'assigned_to' => mb_substr($name, 0, 120)], 'id = ?', [$id]);
        BotAgent::store($chat, 'note', $toBot ? 'أعادها صاحب الشبكة للمساعد الآلي.' : 'استلمها صاحب الشبكة.', ['sender' => $name, 'side' => 'network']);
        \App\Core\Audit::log('inbox.mode', ($toBot ? 'إعادة محادثة مشترك للمساعد الآلي: ' : 'استلام محادثة مشترك: ') . ($chat['name'] ?: $chat['phone']), 'subscriber', $chat['subscriber_id'] ? (int) $chat['subscriber_id'] : null);
        return $this->ok($toBot ? 'المحادثة الآن مع المساعد الآلي.' : 'المحادثة الآن معك، والمساعد لن يرد فيها.', '/inbox/' . $id);
    }

    public function close(Request $request, int $id): Response
    {
        $this->notFoundUnless(self::available());
        $this->chatOr404($id);
        Platform::update('platform_bot_chats', ['status' => 'closed', 'needs_human' => 0, 'mode' => 'bot'], 'id = ?', [$id]);
        return $this->ok('أُغلقت المحادثة، ويرد المساعد الآلي على رسالة المشترك التالية.', '/inbox');
    }
}
