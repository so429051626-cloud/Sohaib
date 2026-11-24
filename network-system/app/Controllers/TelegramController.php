<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\Telegram;
use App\Services\TelegramBot;
use Throwable;

final class TelegramController extends Controller
{
    private const TIME = 'required|regex:/^[0-2]\\d:[0-5]\\d$/';
    private const TOKEN = 'nullable|max:120|regex:/^\\d{5,}:[\\w-]{20,}$/';

    /** حقول البوت [المفتاح => [القاعدة، التسمية]] */
    private const FIELDS = [
        'notify.telegram_enabled' => ['bool', 'تفعيل البوت'],
        'notify.telegram_token'   => [self::TOKEN, 'رمز البوت'],
        'notify.telegram_chat'    => ['nullable|max:255', 'معرّفات المحادثات'],
        'telegram.commands'       => ['bool', 'الأوامر'],
        'telegram.report_daily'   => ['bool', 'التقرير اليومي'],
        'telegram.daily_time'     => [self::TIME, 'وقت التقرير اليومي'],
        'telegram.ai_summary'     => ['bool', 'الملخص اليومي الذكي'],
        'telegram.report_weekly'  => ['bool', 'التقرير الأسبوعي'],
        'telegram.weekly_day'     => ['required|int|between:0,6', 'يوم التقرير الأسبوعي'],
        'telegram.weekly_time'    => [self::TIME, 'وقت التقرير الأسبوعي'],
        'telegram.report_monthly' => ['bool', 'التقرير الشهري'],
        'telegram.monthly_time'   => [self::TIME, 'وقت التقرير الشهري'],
        'telegram.customers'      => ['bool', 'بوت الزبائن'],
        'telegram.customer_token' => [self::TOKEN, 'رمز بوت الزبائن'],
        'telegram.remind'         => ['bool', 'تذكير المشتركين'],
        'telegram.remind_days'    => ['required|int|between:1,30', 'أيام التذكير'],
    ];

    public function index(Request $request): Response
    {
        $stats = [
            'links' => (int) DB::value('SELECT COUNT(DISTINCT subscriber_id) FROM telegram_links'),
            'chats' => (int) DB::value('SELECT COUNT(DISTINCT chat_id) FROM telegram_links'),
        ];
        return view('telegram/index', ['stats' => $stats]);
    }

    public function save(Request $request): Response
    {
        // الرمز الملصوق مع كلمة bot أو مسافات يُنظَّف قبل التحقق
        foreach (['notify.telegram_token', 'telegram.customer_token'] as $k) {
            $f = str_replace('.', '__', $k);
            $raw = trim((string) $request->input($f, ''));
            if ($raw !== '' && ($clean = Telegram::cleanToken($raw)) !== '') {
                $request->post[$f] = $clean;
            }
        }
        $fields = self::FIELDS;
        if (!\App\Services\Ai::offered()) {
            // خيار التحليل الذكي لا يظهر والذكاء مطفأ: يبقى على قيمته حتى يُفعَّل الذكاء للحساب
            unset($fields['telegram.ai_summary']);
        }
        $input = $this->settingsInput($request, $fields);
        if ($input['notify.telegram_token'] === '' || $input['notify.telegram_chat'] === '') {
            $input['notify.telegram_enabled'] = '0';
        }
        Settings::setMany($input);
        unset($input['notify.telegram_token'], $input['telegram.customer_token']);
        Audit::log('settings.update', 'تعديل إعدادات بوت تيليجرام', 'settings', null, $input);
        return $this->ok('تم حفظ إعدادات البوت.', '/telegram');
    }

    public function links(Request $request): Response
    {
        $q = $request->str('q');
        $where = '1 = 1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (s.full_name LIKE ? OR s.username LIKE ? OR s.phone LIKE ? OR l.chat_id = ?)';
            array_push($params, DB::like($q), DB::like($q), DB::like($q), $q);
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM telegram_links l JOIN subscribers s ON s.id = l.subscriber_id WHERE $where", $params);
        $pager = Paginator::fromRequest($request, $total, 50);
        $rows = DB::rows(
            "SELECT l.*, s.full_name, s.username, s.phone, s.expires_at, s.status, p.name AS plan
             FROM telegram_links l JOIN subscribers s ON s.id = l.subscriber_id JOIN plans p ON p.id = s.plan_id
             WHERE $where ORDER BY l.linked_at DESC" . $pager->limitSql(),
            $params
        );
        return view('telegram/links', compact('rows', 'pager', 'q', 'request'));
    }

    public function unlink(Request $request, int $id): Response
    {
        $row = DB::row('SELECT l.*, s.full_name FROM telegram_links l JOIN subscribers s ON s.id = l.subscriber_id WHERE l.id = ?', [$id]);
        $this->notFoundUnless($row);
        DB::delete('telegram_links', 'id = ?', [$id]);
        Audit::log('settings.update', 'إلغاء ربط تيليجرام للمشترك ' . $row['full_name'], 'subscriber', (int) $row['subscriber_id']);
        return $this->ok('تم إلغاء الربط.', '/telegram/links');
    }

    /** استقبال تحديثات تيليجرام */
    public function webhook(Request $request, string $secret): Response
    {
        $stored = (string) Settings::get('telegram.secret', '');
        $header = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');
        if ($stored === '' || !hash_equals($stored, $secret) || !hash_equals($stored, $header)) {
            return json_response(['ok' => false], 403);
        }
        $update = $request->json();
        if ($update) {
            try {
                TelegramBot::handle($update);
            } catch (Throwable $e) {
                log_message('error', 'telegram webhook: ' . $e->getMessage());
            }
        }
        return json_response(['ok' => true]);
    }

    /** تحديثات بوت الزبائن المنفصل */
    public function customerWebhook(Request $request, string $secret): Response
    {
        $stored = (string) Settings::get('telegram.secret', '');
        $header = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');
        if ($stored === '' || !hash_equals($stored, $secret) || !hash_equals($stored, $header) || \App\Services\Telegram::customerToken() === '') {
            return json_response(['ok' => false], 403);
        }
        $update = $request->json();
        if ($update) {
            try {
                \App\Services\Telegram::using(\App\Services\Telegram::customerToken(), static fn () => TelegramBot::handle($update, true));
            } catch (Throwable $e) {
                log_message('error', 'telegram customer webhook: ' . $e->getMessage());
            }
        }
        return json_response(['ok' => true]);
    }

    /** رسالة تجريبية بالرمز والمحادثات المحفوظة، أو بما كُتب في النموذج قبل الحفظ، مع فحص استقبال البوت للأوامر */
    public function test(Request $request): Response
    {
        Session::release();
        if (($typed = Telegram::typedToken($request->str('token'))) === null) {
            return json_response(['ok' => false, 'message' => 'رمز البوت غير صالح.'], 422);
        }
        $token = $typed !== '' ? $typed : Telegram::token();
        $rawChats = $request->str('chat');
        $chats = $rawChats !== '' ? Telegram::parseChats($rawChats) : Telegram::chats();
        if ($rawChats !== '' && !$chats) {
            return json_response(['ok' => false, 'message' => 'معرّف المحادثة غير صالح: يكون أرقاماً مثل 123456789 أو -1001234567890.'], 422);
        }
        $r = Telegram::deliver($token, $chats, '<b>' . Telegram::esc(Settings::networkName()) . "</b>\nرسالة تجريبية: تم ربط البوت بنجاح.\n<i>MADIX NEXUS</i>");
        $message = str_replace($token, '***', $r['message']);
        if ($r['bot'] !== '' && $token === Telegram::token()) {
            if ($r['bot'] !== (string) Settings::get('telegram.bot_username', '')) {
                Settings::set('telegram.bot_username', $r['bot']);
            }
            // استقبال الأوامر يُفحص في النجاح والفشل: بدونه لا يرد البوت على «ابدأ» ولا على /id
            if (Settings::bool('telegram.commands') && ($problem = Telegram::webhookProblem($token, (string) Settings::get('telegram.mode', ''))) !== null) {
                $message .= ' ' . $problem;
            }
        }
        return json_response(['ok' => $r['ok'], 'message' => $message], $r['ok'] ? 200 : 422);
    }

    /** المحادثات التي راسلت البوت مؤخراً، لتعبئة معرّف المحادثة */
    public function detect(Request $request): Response
    {
        Session::release();
        if (($typed = Telegram::typedToken($request->str('token'))) === null) {
            return json_response(['ok' => false, 'message' => 'رمز البوت غير صالح.'], 422);
        }
        $token = $typed !== '' ? $typed : Telegram::token();
        // عند استقبال الرسائل برابط Webhook لا تُقرأ من تيليجرام، فتُستعمل المحادثات التي سجّلها النظام عند وصولها
        $seen = $token === Telegram::token() ? TelegramBot::seenChats() : [];
        $r = Telegram::recentChats($token, $seen);
        $r['message'] = str_replace($token, '***', $r['message']);
        return json_response($r, $r['ok'] ? 200 : 422);
    }

    /** فحص بوت الزبائن: الرمز، التفعيل، واستقبال الرسائل */
    public function customerTest(Request $request): Response
    {
        Session::release();
        if (($typed = Telegram::typedToken($request->str('token'))) === null) {
            return json_response(['ok' => false, 'message' => 'رمز البوت غير صالح.'], 422);
        }
        $own = $typed !== '' ? $typed : Telegram::customerToken();
        $token = $own !== '' ? $own : Telegram::token();
        if ($token === '') {
            return json_response(['ok' => false, 'message' => 'أدخل رمز البوت.'], 422);
        }
        try {
            $me = Telegram::call('getMe', [], 10, $token);
        } catch (Throwable $e) {
            return json_response(['ok' => false, 'message' => str_replace($token, '***', $e->getMessage())], 422);
        }
        if (empty($me['ok'])) {
            return json_response(['ok' => false, 'message' => Telegram::explain($me)], 422);
        }
        $bot = (string) ($me['result']['username'] ?? '');
        if ($own !== '' && $typed === '' && $bot !== '') {
            Settings::set('telegram.customer_username', $bot);
        }
        $parts = ['البوت @' . $bot . ' يعمل.'];
        if (!Settings::bool('telegram.customers')) {
            $parts[] = 'خدمة الزبائن غير مفعّلة: فعّلها واحفظ.';
        } elseif ($typed === '') {
            $mode = $own !== '' ? (string) Settings::get('telegram.customer_mode', 'poll') : (string) Settings::get('telegram.mode', 'poll');
            if (($problem = Telegram::webhookProblem($token, $mode)) !== null) {
                $parts[] = $problem;
            }
        }
        return json_response(['ok' => true, 'message' => implode(' ', $parts), 'bot' => $bot]);
    }

    public function connect(Request $request): Response
    {
        Session::release();
        try {
            $r = TelegramBot::connect();
            if ($r['ok']) {
                $c = TelegramBot::connectCustomer();
                if (!$c['ok']) {
                    return json_response($c, 422);
                }
                if ($c['message'] !== '') {
                    $r['message'] .= ' بوت الزبائن: ' . $c['message'] . '.';
                }
            }
        } catch (Throwable $e) {
            return json_response(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        if ($r['ok']) {
            Audit::log('settings.update', 'ربط بوت تيليجرام', 'settings');
        }
        return json_response($r, $r['ok'] ? 200 : 422);
    }

    public function report(Request $request): Response
    {
        Session::release();
        $type = in_array($request->str('type'), ['daily', 'weekly', 'monthly'], true) ? $request->str('type') : 'daily';
        try {
            $r = TelegramBot::sendReport($type);
        } catch (Throwable $e) {
            return json_response(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        return json_response($r, $r['ok'] ? 200 : 422);
    }
}
