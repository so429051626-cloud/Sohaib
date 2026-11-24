<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminAuth;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Platform;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Ai;
use App\Services\BotAgent;
use App\Services\MadixCloud;
use App\Services\WhatsApp;

/**
 * لوحة المسؤول: بوت خدمة الزبائن للمنصة — صندوق المحادثات (الرد واستلام المحادثة من البوت) والإعدادات والقنوات.
 */
final class AdminBotController extends Controller
{
    /** حقول الإعدادات [المفتاح => [القاعدة، التسمية]]؛ الأسرار تُعالج وحدها */
    private const FIELDS = [
        'bot.enabled'        => ['bool', 'تشغيل البوت'],
        'bot.name'           => ['nullable|max:60', 'اسم المساعد'],
        'bot.knowledge'      => ['nullable|max:20000', 'معلومات للمساعد'],
        'bot.per_day'        => ['required|int|between:5,500', 'حد الرسائل اليومي'],
        'bot.retention_days' => ['required|int|between:7,3650', 'مدة الاحتفاظ بالمحادثات'],
        'bot.web'            => ['bool', 'نافذة الموقع'],
        'bot.whatsapp'       => ['bool', 'واتساب'],
        'bot.wa_phone_id'    => ['nullable|regex:/^\\d{5,25}$/', 'معرّف رقم الهاتف'],
        'bot.erp'            => ['bool', 'الربط مع MADIX Cloud'],
        'bot.erp_url'        => ['nullable|max:200|regex:#^https?://[^\\s]+$#i', 'رابط MADIX Cloud'],
    ];

    /** الحقول السرية: تُشفَّر بمفتاح المنصة، والحقل الفارغ يُبقي القيمة المحفوظة */
    private const SECRETS = ['bot.wa_token' => 'رمز الوصول', 'bot.wa_secret' => 'سر التطبيق', 'bot.erp_key' => 'مفتاح MADIX Cloud'];

    public const FILTERS = ['waiting' => 'بانتظار الفريق', 'network' => 'عند أصحاب الشبكات', 'open' => 'المفتوحة', 'closed' => 'المغلقة', 'all' => 'الكل'];

    private function admin(): string
    {
        return (string) (AdminAuth::user()['name'] ?? '');
    }

    // ------------------------------------------------------------------
    // صندوق المحادثات
    // ------------------------------------------------------------------

    public function index(Request $request): Response
    {
        $f = isset(self::FILTERS[$request->str('f')]) ? $request->str('f') : (BotAgent::waiting() > 0 ? 'waiting' : 'open');
        $channel = isset(BotAgent::CHANNELS[$request->str('channel')]) ? $request->str('channel') : '';
        $kind = isset(BotAgent::KINDS[$request->str('kind')]) ? $request->str('kind') : '';
        $q = trim($request->str('q'));
        $where = ["c.channel <> 'test'"];
        $params = [];
        if ($f === 'waiting') {
            $where[] = "c.status = 'open' AND c.needs_human = 1 AND c.handler = 'admin'";
        } elseif ($f === 'network') {
            $where[] = "c.status = 'open' AND c.handler = 'network' AND c.mode = 'human'";
        } elseif ($f !== 'all') {
            $where[] = 'c.status = ?';
            $params[] = $f;
        }
        if ($channel !== '') {
            $where[] = 'c.channel = ?';
            $params[] = $channel;
        }
        if ($kind !== '') {
            $where[] = 'c.kind = ?';
            $params[] = $kind;
        }
        if ($q !== '') {
            $like = '%' . DB::like($q) . '%';
            $where[] = '(c.name LIKE ? OR c.phone LIKE ? OR c.external_id LIKE ? OR c.last_message LIKE ? OR t.name LIKE ? OR t.slug LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM platform_bot_chats c LEFT JOIN tenants t ON t.id = c.tenant_id WHERE $sqlWhere", $params);
        $pager = Paginator::fromRequest($request, $total, 30);
        $chats = DB::rows(
            "SELECT c.*, t.name AS network FROM platform_bot_chats c LEFT JOIN tenants t ON t.id = c.tenant_id
             WHERE $sqlWhere ORDER BY (c.needs_human = 1 AND c.handler = 'admin') DESC, c.last_at DESC" . $pager->limitSql(),
            $params
        );
        $counts = DB::row(
            "SELECT SUM(status = 'open' AND needs_human = 1 AND handler = 'admin') AS waiting, SUM(status = 'open' AND handler = 'network' AND mode = 'human') AS network,
                    SUM(status = 'open') AS open FROM platform_bot_chats WHERE channel <> 'test'"
        ) ?? [];
        return view('admin/bot/index', [
            'request' => $request, 'chats' => $chats, 'pager' => $pager, 'f' => $f, 'channel' => $channel, 'kind' => $kind, 'q' => $q, 'counts' => $counts,
            'enabled' => BotAgent::enabled(),
        ], 'admin');
    }

    private function chatOr404(int $id): array
    {
        $chat = BotAgent::find($id);
        $this->notFoundUnless($chat);
        return (array) $chat;
    }

    public function show(Request $request, int $id): Response
    {
        $chat = $this->chatOr404($id);
        if ((int) $chat['unread'] > 0 && $chat['handler'] === 'admin') {
            DB::update('platform_bot_chats', ['unread' => 0], 'id = ?', [$id]);
        }
        $messages = array_reverse(DB::rows('SELECT * FROM platform_bot_messages WHERE chat_id = ? ORDER BY id DESC LIMIT 200', [$id]));
        $id2 = BotAgent::meta($chat)['id'] ?? [];
        $names = static function (array $ids): array {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            return $ids ? DB::rows('SELECT id, name, slug FROM tenants WHERE id IN (' . DB::in($ids) . ') ORDER BY name', $ids) : [];
        };
        return view('admin/bot/show', [
            'chat' => $chat, 'messages' => $messages,
            'network' => $chat['tenant_id'] ? DB::row('SELECT id, name, slug, phone, owner_name FROM tenants WHERE id = ?', [$chat['tenant_id']]) : null,
            'owned' => $names((array) ($id2['owner'] ?? [])),
            'subscribedIn' => $names(array_column(array_filter((array) ($id2['subs'] ?? []), 'is_array'), 't')),
        ], 'admin');
    }

    /** الرسائل الجديدة للتحديث التلقائي */
    public function poll(Request $request, int $id): Response
    {
        Session::release();
        $chat = $this->chatOr404($id);
        return json_response(self::pollData($chat, max(0, $request->int('after')), $chat['handler'] === 'admin'));
    }

    /**
     * رسائل المحادثة بعد رقم، مع حالتها (لصندوق المسؤول وصندوق صاحب الشبكة).
     * @param bool $markRead تصفير غير المقروء لأن من يقرأ هو من يرد
     * @param bool $forNetwork لصاحب الشبكة: بلا أسماء المسؤولين ولا أدوات المساعد الداخلية
     */
    public static function pollData(array $chat, int $after, bool $markRead, bool $forNetwork = false): array
    {
        // يُستدعى من نظام الشبكة أيضاً: قاعدة المنصة عبر Platform:: دائماً
        $rows = Platform::rows('SELECT id, role, body, sender, side, tools, created_at FROM platform_bot_messages WHERE chat_id = ? AND id > ? ORDER BY id LIMIT 100', [$chat['id'], $after]);
        if ($rows && $markRead && (int) $chat['unread'] > 0) {
            Platform::update('platform_bot_chats', ['unread' => 0], 'id = ?', [$chat['id']]);
        }
        return [
            'ok' => true,
            'mode' => $chat['mode'], 'needs_human' => (bool) (int) $chat['needs_human'], 'status' => $chat['status'], 'handler' => $chat['handler'],
            'messages' => array_map(static fn ($r) => [
                'id' => (int) $r['id'], 'role' => $r['role'], 'body' => $r['body'],
                'sender' => $forNetwork && $r['side'] === 'admin' ? '' : $r['sender'], 'side' => $r['side'],
                'tools' => $forNetwork ? null : $r['tools'], 'at' => fmt_dt((string) $r['created_at']),
            ], $rows),
        ];
    }

    public function reply(Request $request, int $id): Response
    {
        $chat = $this->chatOr404($id);
        $r = BotAgent::staffReply($chat, $request->str('body'), $this->admin(), 'admin');
        if ($request->wantsJson()) {
            return json_response($r, $r['ok'] ? 200 : 422);
        }
        if (!$r['ok']) {
            return $this->backWithErrors($request, ['body' => $r['message']], '/admin/bot/chats/' . $id);
        }
        return redirect('/admin/bot/chats/' . $id);
    }

    /** استلام المحادثة من البوت، أو إعادتها له */
    public function mode(Request $request, int $id): Response
    {
        $chat = $this->chatOr404($id);
        $toBot = $request->str('mode') === 'bot';
        DB::update('platform_bot_chats', $toBot
            ? ['mode' => 'bot', 'needs_human' => 0]
            : ['mode' => 'human', 'needs_human' => 0, 'handler' => 'admin', 'assigned_to' => mb_substr($this->admin(), 0, 120)], 'id = ?', [$id]);
        // نص الملاحظة بلا اسم المسؤول (تظهر لصاحب الشبكة في محادثات مشتركيه)، واسمه في المرسل يظهر للمسؤولين فقط
        BotAgent::store($chat, 'note', $toBot ? 'أُعيدت المحادثة للمساعد الآلي.' : 'استلمها فريق المنصة.', ['sender' => $this->admin(), 'side' => 'admin']);
        return $this->ok($toBot ? 'المحادثة الآن مع المساعد الآلي.' : 'المحادثة الآن معك، والبوت لن يرد فيها.', '/admin/bot/chats/' . $id);
    }

    /** تحويل المحادثة إلى صاحب شبكة المشترك، أو إعادتها لفريق المنصة */
    public function handler(Request $request, int $id): Response
    {
        $chat = $this->chatOr404($id);
        $toNetwork = $request->str('handler') === 'network';
        if ($toNetwork && !$chat['tenant_id']) {
            return $this->backWithErrors($request, ['handler' => 'المحادثة غير مرتبطة بشبكة.'], '/admin/bot/chats/' . $id);
        }
        DB::update('platform_bot_chats', ['handler' => $toNetwork ? 'network' : 'admin', 'mode' => 'human', 'needs_human' => 1, 'status' => 'open'], 'id = ?', [$id]);
        $network = $chat['tenant_id'] ? (string) DB::value('SELECT name FROM tenants WHERE id = ?', [$chat['tenant_id']]) : '';
        BotAgent::store($chat, 'note', $toNetwork ? 'حوّلها فريق المنصة لصاحب شبكة ' . $network . '.' : 'استلمها فريق المنصة.', ['sender' => $this->admin(), 'side' => 'admin']);
        if ($toNetwork) {
            BotAgent::notify((array) BotAgent::find($id), 'حوّلها فريق المنصة إليك.');
        }
        return $this->ok($toNetwork ? 'حُوّلت المحادثة لصاحب الشبكة.' : 'المحادثة الآن عند فريق المنصة.', '/admin/bot/chats/' . $id);
    }

    public function close(Request $request, int $id): Response
    {
        $this->chatOr404($id);
        $open = $request->str('status') === 'open';
        DB::update('platform_bot_chats', $open ? ['status' => 'open'] : ['status' => 'closed', 'needs_human' => 0, 'mode' => 'bot', 'handler' => 'admin'], 'id = ?', [$id]);
        return $this->ok($open ? 'أُعيد فتح المحادثة.' : 'أُغلقت المحادثة، ويرد البوت على الرسالة التالية.', $open ? '/admin/bot/chats/' . $id : '/admin/bot');
    }

    /** إعادة التعرّف على صاحب الرقم الآن (بعد تسجيل شبكة أو مشترك جديد) */
    public function identify(Request $request, int $id): Response
    {
        $chat = $this->chatOr404($id);
        Session::release();
        $r = BotAgent::identity($chat, true);
        Session::resume();
        $msg = $r['phone'] === '' ? 'لا رقم موثّقاً لهذه المحادثة.' : ($r['owner'] ? 'صاحب شبكة.' : ($r['subs'] ? 'مشترك في شبكة.' : 'الرقم غير مسجّل لصاحب شبكة ولا لمشترك.'));
        return $this->ok('أُعيد التعرّف على الرقم: ' . $msg, '/admin/bot/chats/' . $id);
    }

    // ------------------------------------------------------------------
    // الإعدادات
    // ------------------------------------------------------------------

    public function settings(Request $request): Response
    {
        WhatsApp::ensureKeys();
        if (!preg_match('/^[a-f0-9]{32}$/', Platform::setting('bot.web_key'))) {
            Platform::setSettings(['bot.web_key' => bin2hex(random_bytes(16))]);
        }
        $has = [];
        foreach (array_keys(self::SECRETS) as $k) {
            $has[$k] = Platform::setting($k) !== '';
        }
        return view('admin/bot/settings', ['has' => $has, 'aiState' => Ai::status(null)], 'admin');
    }

    public function save(Request $request): Response
    {
        $input = $this->settingsInput($request, self::FIELDS);
        $input['bot.erp_url'] = rtrim($input['bot.erp_url'], '/');
        foreach (self::SECRETS as $key => $label) {
            $form = str_replace('.', '__', $key);
            $raw = trim((string) $request->input($form, ''));
            if ($request->bool($form . '_clear')) {
                $input[$key] = '';
            } elseif ($raw !== '') {
                if (mb_strlen($raw) > 600 || preg_match('/\s/', $raw)) {
                    return $this->backWithErrors($request, [$form => $label . ' غير صالح.'], '/admin/bot/settings');
                }
                $input[$key] = Platform::seal($raw);
            }
        }
        if (array_key_exists('bot.erp_key', $input) || $input['bot.erp_url'] !== Platform::setting('bot.erp_url')) {
            $input['bot.erp_cache'] = '';
        }
        Platform::setSettings($input);
        Platform::log(null, 'admin', 'bot.settings', 'تعديل إعدادات بوت خدمة الزبائن', $this->admin());
        return $this->ok('تم حفظ إعدادات البوت.', '/admin/bot/settings');
    }

    /** تجربة البوت من صفحة الإعدادات (محادثة «تجربة» لكل مسؤول)، وبرقم هاتف ليجرّب كصاحب شبكة أو مشترك */
    public function try(Request $request): Response
    {
        Session::release();
        $external = 'admin:' . (int) AdminAuth::id();
        if ($request->bool('reset')) {
            DB::query("DELETE FROM platform_bot_chats WHERE channel = 'test' AND external_id = ?", [$external]);
            return json_response(['ok' => true]);
        }
        $text = trim($request->str('text'));
        if ($text === '') {
            return json_response(['ok' => false, 'message' => 'اكتب رسالة.'], 422);
        }
        if (!BotAgent::enabled()) {
            return json_response(['ok' => false, 'message' => 'شغّل البوت واحفظ أولاً.'], 422);
        }
        $phone = (string) preg_replace('/\D/', '', latin_digits($request->str('phone')));
        $chat = BotAgent::chat('test', $external, ['name' => $this->admin()]);
        $meta = BotAgent::meta($chat);
        if (($meta['as_phone'] ?? '') !== $phone) {
            // رقم تجربة جديد: تبدأ المحادثة من جديد بهوية هذا الرقم
            DB::query('DELETE FROM platform_bot_messages WHERE chat_id = ?', [$chat['id']]);
            DB::update('platform_bot_chats', ['meta' => json_encode(['as_phone' => $phone]), 'identified_at' => null, 'kind' => 'guest', 'tenant_id' => null, 'subscriber_id' => null], 'id = ?', [$chat['id']]);
        }
        if ($chat['mode'] === 'human') {
            DB::update('platform_bot_chats', ['mode' => 'bot', 'needs_human' => 0], 'id = ?', [$chat['id']]);
        }
        $r = BotAgent::receive('test', $external, mb_substr($text, 0, 1000));
        $c = $r['chat'];
        $tools = (string) DB::value("SELECT tools FROM platform_bot_messages WHERE chat_id = ? AND role = 'bot' ORDER BY id DESC LIMIT 1", [$c['id']]);
        $handoff = ($c['mode'] ?? '') === 'human';
        return json_response([
            'ok' => true,
            'reply' => $r['reply'] ?? 'لم يرد البوت (المحادثة حُوّلت).',
            'tools' => $tools,
            'as' => BotAgent::KINDS[$c['kind'] ?? 'guest'] ?? '',
            'handoff' => $handoff ? (($c['handler'] ?? '') === 'network' ? 'حُوّلت لصاحب الشبكة' : 'حُوّلت لفريق المنصة') : '',
        ]);
    }

    public function whatsappTest(Request $request): Response
    {
        Session::release();
        $token = trim($request->str('token'));
        $r = WhatsApp::test(array_filter(['phone_id' => trim($request->str('phone_id')), 'token' => $token], static fn ($v) => $v !== ''));
        return json_response($r, $r['ok'] ? 200 : 422);
    }

    public function erpTest(Request $request): Response
    {
        Session::release();
        $with = [];
        $url = rtrim(trim($request->str('url')), '/');
        if ($url !== '') {
            if (!preg_match('#^https?://[^\s]+$#i', $url)) {
                return json_response(['ok' => false, 'message' => 'الرابط غير صالح.'], 422);
            }
            $with['base'] = $url;
        }
        if (($key = trim($request->str('key'))) !== '') {
            $with['key'] = $key;
        }
        $r = MadixCloud::test($with);
        return json_response($r, $r['ok'] ? 200 : 422);
    }

    /** رابط جديد لنافذة الموقع (الرابط القديم يتوقف) */
    public function webKey(Request $request): Response
    {
        Platform::setSettings(['bot.web_key' => bin2hex(random_bytes(16))]);
        Platform::log(null, 'admin', 'bot.settings', 'تجديد رابط نافذة المحادثة', $this->admin());
        return $this->ok('أُنشئ رابط جديد؛ حدّث كود النافذة في أي موقع خارجي وضعته فيه.', '/admin/bot/settings');
    }
}
