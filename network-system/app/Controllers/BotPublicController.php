<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Kernel;
use App\Core\Lang;
use App\Core\Platform;
use App\Core\Request;
use App\Core\Response;
use App\Core\Throttle;
use App\Services\BotAgent;
use App\Services\WhatsApp;
use Throwable;

/**
 * الواجهات العامة لبوت خدمة الزبائن في المنصة: Webhook واتساب، ونافذة المحادثة (في موقع المنصة أو أي موقع آخر)، وصفحة المحادثة /help.
 */
final class BotPublicController extends Controller
{
    private static function hookOk(string $hook): bool
    {
        $stored = Platform::setting('bot.wa_hook');
        return $stored !== '' && hash_equals($stored, $hook);
    }

    /** تحقق Meta من رابط Webhook عند إضافته (hub.mode و hub.verify_token و hub.challenge) */
    public function whatsappVerify(Request $request, string $hook): Response
    {
        $verify = Platform::setting('bot.wa_verify');
        // PHP يحوّل النقطة في أسماء متغيرات الرابط إلى شرطة سفلية
        $mode = (string) ($request->query['hub_mode'] ?? '');
        $token = (string) ($request->query['hub_verify_token'] ?? '');
        $challenge = (string) ($request->query['hub_challenge'] ?? '');
        if (!self::hookOk($hook) || $verify === '' || $mode !== 'subscribe' || !hash_equals($verify, $token) || !preg_match('/^[\w\-]{1,128}$/', $challenge)) {
            return Response::text('forbidden', 403);
        }
        return Response::text($challenge);
    }

    /** رسائل واتساب: الرد 200 فوراً، والمعالجة بعد إغلاق الاتصال */
    public function whatsappWebhook(Request $request, string $hook): Response
    {
        if (!self::hookOk($hook)) {
            return Response::text('forbidden', 403);
        }
        $raw = $request->raw();
        if (!WhatsApp::signatureValid($raw, $request->header('X-Hub-Signature-256'))) {
            log_message('warning', 'whatsapp webhook: bad signature from ' . $request->ip());
            return Response::text('forbidden', 403);
        }
        $payload = json_decode($raw, true);
        $messages = is_array($payload) ? WhatsApp::parse($payload) : [];
        if ($messages && WhatsApp::configured()) {
            Kernel::afterResponse(static function () use ($messages): void {
                foreach ($messages as $m) {
                    try {
                        $media = $m['text'] === '' ? self::mediaLabel($m['type']) : '';
                        $r = BotAgent::receive('whatsapp', $m['from'], $media !== '' ? $media : $m['text'], [
                            'name' => $m['name'], 'phone' => '+' . $m['from'], 'message_id' => 'wa:' . $m['id'], 'media' => $media !== '',
                        ]);
                        if (!empty($r['duplicate'])) {
                            continue;
                        }
                        WhatsApp::markRead($m['id']);
                        if ($r['reply'] !== null) {
                            $s = WhatsApp::send($m['from'], $r['reply']);
                            if (!$s['ok']) {
                                log_message('warning', 'whatsapp reply: ' . $s['message']);
                            }
                        }
                    } catch (Throwable $e) {
                        log_message('error', 'whatsapp message: ' . WhatsApp::scrub($e->getMessage()));
                    }
                }
            });
        }
        return json_response(['ok' => true]);
    }

    private static function mediaLabel(string $type): string
    {
        return match ($type) {
            'audio' => '[رسالة صوتية]',
            'image' => '[صورة]',
            'video' => '[فيديو]',
            'document' => '[ملف]',
            'sticker' => '[ملصق]',
            'location' => '[موقع]',
            'contacts' => '[جهة اتصال]',
            default => '[رسالة غير نصية]',
        };
    }

    // ------------------------------------------------------------------
    // نافذة المحادثة في المواقع
    // ------------------------------------------------------------------

    public static function webOn(): bool
    {
        return BotAgent::enabled() && Platform::setting('bot.web') === '1' && preg_match('/^[a-f0-9]{32}$/', Platform::setting('bot.web_key'));
    }

    private static function webOk(string $key): bool
    {
        return self::webOn() && hash_equals(Platform::setting('bot.web_key'), $key);
    }

    /** رابط واجهة النافذة: كاملاً لكود التضمين في موقع آخر، ونسبياً في صفحات المنصة نفسها (يعمل بأي نطاق تُفتح به) */
    public static function endpoint(bool $absolute = true): string
    {
        return ($absolute ? BotAgent::publicUrl() : root_path()) . '/api/bot/' . Platform::setting('bot.web_key');
    }

    /** النافذة قد تُضمَّن في موقع آخر: الطلبات بلا ملفات تعريف، فيُسمح بأي مصدر */
    private static function cors(Response $r): Response
    {
        return $r->withHeader('Access-Control-Allow-Origin', '*')->withHeader('Cache-Control', 'no-store');
    }

    /** رسائل المحادثة بعد رقم (أو آخر 50 عند الفتح) */
    private static function since(int $chatId, int $after): array
    {
        $rows = $after > 0
            ? Platform::rows("SELECT id, role, body, created_at FROM platform_bot_messages WHERE chat_id = ? AND id > ? AND role IN ('user','bot','staff') ORDER BY id LIMIT 50", [$chatId, $after])
            : array_reverse(Platform::rows("SELECT id, role, body, created_at FROM platform_bot_messages WHERE chat_id = ? AND role IN ('user','bot','staff') ORDER BY id DESC LIMIT 50", [$chatId]));
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'role' => $r['role'], 'body' => $r['body'], 'at' => date('H:i', (int) strtotime((string) $r['created_at'])),
        ], $rows);
    }

    private static function sid(mixed $v): ?string
    {
        $v = (string) $v;
        return preg_match('/^[a-f0-9]{32}$/', $v) ? $v : null;
    }

    public function webSend(Request $request, string $key): Response
    {
        Lang::set('ar');
        if (!self::webOk($key)) {
            return self::cors(json_response(['ok' => false, 'message' => 'المحادثة غير متاحة.'], 404));
        }
        // النافذة ترسل JSON بنوع text/plain حتى لا يحتاج المتصفح طلب إذن مسبق من المواقع الأخرى
        $data = json_decode($request->raw(), true);
        $data = is_array($data) ? $data : $request->all();
        $sid = self::sid($data['sid'] ?? '');
        $text = trim((string) ($data['text'] ?? ''));
        if ($sid === null || $text === '') {
            return self::cors(json_response(['ok' => false, 'message' => 'اكتب رسالتك.'], 422));
        }
        if (!Throttle::rate('pbot:web:' . $request->ip(), 20, 60)) {
            return self::cors(json_response(['ok' => false, 'message' => 'رسائل كثيرة خلال وقت قصير. انتظر دقيقة.'], 429));
        }
        $r = BotAgent::receive('web', $sid, mb_substr($text, 0, 1000), ['name' => mb_substr(trim((string) ($data['name'] ?? '')), 0, 60)]);
        return self::cors(json_response(['ok' => true, 'messages' => self::since((int) $r['chat']['id'], max(0, (int) ($data['after'] ?? 0)))]));
    }

    public function webPoll(Request $request, string $key): Response
    {
        Lang::set('ar');
        if (!self::webOk($key)) {
            return self::cors(json_response(['ok' => false, 'message' => 'المحادثة غير متاحة.'], 404));
        }
        $sid = self::sid($request->str('sid'));
        if ($sid === null) {
            return self::cors(json_response(['ok' => false], 422));
        }
        if (!Throttle::rate('pbot:poll:' . $request->ip(), 90, 60)) {
            return self::cors(json_response(['ok' => false], 429));
        }
        $id = Platform::value("SELECT id FROM platform_bot_chats WHERE channel = 'web' AND external_id = ?", [$sid]);
        return self::cors(json_response(['ok' => true, 'messages' => $id ? self::since((int) $id, max(0, $request->int('after'))) : []]));
    }

    /** صفحة محادثة كاملة على رابط المنصة /help (تُرسل للزبائن أو تُفتح من أي مكان) */
    public function webPage(Request $request): Response
    {
        Lang::set('ar');
        if (!self::webOn()) {
            return Response::text('<!doctype html><meta charset="utf-8"><title>غير متاح</title><p style="font-family:sans-serif;text-align:center;margin-top:20vh">المحادثة غير متاحة.</p>', 404, 'text/html; charset=UTF-8');
        }
        $title = BotAgent::name();
        $html = '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>' . e($title) . '</title>'
            . '<style>html,body{margin:0;height:100%;background:#f4f6fb}#mxb-page{position:fixed;inset:0}</style></head><body>'
            . '<div id="mxb-page"></div>'
            . '<script src="' . e(asset('widget/bot-widget.js')) . '" data-endpoint="' . e(self::endpoint(false)) . '" data-title="' . e($title) . '" data-mode="page" data-target="mxb-page"'
            . ' data-greeting="' . e('أهلاً بك! اسألني عن ' . Platform::setting('platform.name') . ' أو عن اشتراكك أو كرتك.') . '"></script>'
            . '</body></html>';
        return Response::text($html, 200, 'text/html; charset=UTF-8')->withHeader('Cache-Control', 'no-store');
    }
}
