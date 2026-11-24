<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Kernel;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\SmsService;
use App\Services\TweetSms;

/**
 * الرسائل النصية: الإرسال، السجل، القوالب، الإعدادات.
 */
final class SmsController extends Controller
{
    private function filters(Request $request): array
    {
        $ids = [];
        foreach ($request->arr('pick') as $v) {
            if (is_string($v) && preg_match('/^s:(\d+)$/', $v, $m)) {
                $ids[] = (int) $m[1];
            }
        }
        return [
            'state' => array_key_exists($request->str('state', 'all'), SmsService::STATES) ? $request->str('state', 'all') : 'all',
            'plan' => $request->int('plan'), 'area' => $request->int('area'), 'router' => $request->int('router'), 'ids' => $ids,
        ];
    }

    public function compose(Request $request): Response
    {
        // المشتركون المختارون قبل خطأ في النموذج يعودون كما كانوا (ولا أحد إن لم يُختر أحد)
        $oldIds = [];
        foreach ((array) old('pick', []) as $v) {
            if (is_string($v) && preg_match('/^s:(\d+)$/', $v, $m)) {
                $oldIds[] = (int) $m[1];
            }
        }
        return view('sms/compose', [
            'configured' => SmsService::configured(),
            'plans' => DB::pairs('SELECT id, name FROM plans ORDER BY name'),
            'areas' => DB::pairs('SELECT id, name FROM areas ORDER BY name'),
            'routers' => DB::pairs('SELECT id, name FROM routers ORDER BY name'),
            'templates' => DB::rows('SELECT * FROM sms_templates ORDER BY name'),
            'recent' => DB::rows('SELECT * FROM sms_messages ORDER BY id DESC LIMIT 6'),
            'prefill' => $request->str('to'), 'draft' => mb_substr($request->str('text'), 0, 1000),
            'picked' => $oldIds ? SmsService::pickSubscribers('', $oldIds) : [],
        ]);
    }

    /** البحث عن مشتركين لاختيارهم مستلمين */
    public function recipients(Request $request): Response
    {
        return json_response(['ok' => true, 'items' => SmsService::pickSubscribers($request->str('q'))]);
    }

    /** عدد المستلمين للفلاتر الحالية */
    public function count(Request $request): Response
    {
        $subs = SmsService::audience($this->filters($request));
        [$ok, $bad] = TweetSms::parseNumbers($request->str('numbers'));
        $all = array_unique(array_merge(array_column($subs, 'msisdn'), $ok));
        return json_response(['ok' => true, 'subscribers' => count($subs), 'numbers' => count($ok), 'invalid' => count($bad), 'total' => count($all)]);
    }

    public function send(Request $request): Response
    {
        if (!SmsService::configured()) {
            return $this->backWithErrors($request, ['body' => 'أكمل إعدادات الرسائل أولاً.'], '/sms');
        }
        $body = trim((string) $request->input('body', ''));
        if ($body === '' || mb_strlen($body) > 1000) {
            return $this->backWithErrors($request, ['body' => 'اكتب نص الرسالة (حتى 1000 حرف).'], '/sms');
        }
        $f = $this->filters($request);
        $subs = SmsService::audience($f);
        [$numbers, $bad] = TweetSms::parseNumbers($request->str('numbers'));
        if (!$subs && !$numbers) {
            return $this->backWithErrors($request, ['numbers' => $bad ? 'الأرقام المدخلة غير صالحة.' : 'لا يوجد مستلمون.'], '/sms');
        }
        $scheduled = null;
        if ($request->bool('schedule')) {
            $ts = strtotime($request->str('date') . ' ' . $request->str('time'));
            if (!$ts || $ts < time() + 120) {
                return $this->backWithErrors($request, ['date' => 'اختر موعداً لاحقاً للإرسال.'], '/sms');
            }
            $scheduled = date('Y-m-d H:i:00', $ts);
        }
        $parts = [];
        if ($f['state'] === 'pick') {
            $parts[] = SmsService::STATES['pick'] . ' (' . count($subs) . ')';
        } elseif ($f['state'] !== 'none') {
            $parts[] = SmsService::STATES[$f['state']];
            foreach (['plan' => 'plans', 'area' => 'areas', 'router' => 'routers'] as $k => $table) {
                if ($f[$k] > 0) {
                    $parts[] = (string) DB::value("SELECT name FROM $table WHERE id = ?", [$f[$k]]);
                }
            }
        }
        if ($numbers) {
            $parts[] = count($numbers) . ' رقم';
        }
        $id = SmsService::queue($body, $subs, $numbers, 'manual', implode(' · ', $parts), $scheduled);
        if ($request->str('template_name') !== '') {
            DB::insert('sms_templates', ['name' => mb_substr($request->str('template_name'), 0, 80), 'body' => $body, 'created_at' => now()]);
        }
        Audit::log('sms.send', 'إرسال رسالة نصية إلى ' . DB::value('SELECT recipients FROM sms_messages WHERE id = ?', [$id]) . ' مستلم', 'sms', $id);
        Session::release();
        @set_time_limit(0);
        Kernel::afterResponse(static fn () => SmsService::process($id, 600.0));
        flash('success', 'جارٍ إرسال الرسالة.');
        return redirect('/sms/log/' . $id);
    }

    public function log(Request $request): Response
    {
        $q = latin_digits($request->str('q'));
        $where = '1 = 1';
        $params = [];
        if ($q !== '') {
            $n = TweetSms::normalize($q);
            $where .= ' AND (m.body LIKE ? OR m.audience LIKE ? OR m.id IN (SELECT message_id FROM sms_recipients WHERE phone = ? OR name LIKE ?))';
            array_push($params, DB::like($q), DB::like($q), $n ?? $q, DB::like($q));
        }
        if (in_array($request->str('kind'), ['manual', 'auto', 'test'], true)) {
            $where .= ' AND m.kind = ?';
            $params[] = $request->str('kind');
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM sms_messages m WHERE $where", $params);
        $pager = Paginator::fromRequest($request, $total, 30);
        $rows = DB::rows("SELECT m.*, e.name AS employee_name FROM sms_messages m LEFT JOIN employees e ON e.id = m.employee_id WHERE $where ORDER BY m.id DESC" . $pager->limitSql(), $params);
        $stats = DB::row('SELECT COALESCE(SUM(sent), 0) AS sent, COALESCE(SUM(failed), 0) AS failed, COALESCE(SUM(sent * segments), 0) AS units FROM sms_messages WHERE created_at >= ?', [date('Y-m-01')]);
        return view('sms/log', compact('rows', 'pager', 'stats', 'request', 'q'));
    }

    public function show(Request $request, int $id): Response
    {
        $msg = DB::row('SELECT m.*, e.name AS employee_name FROM sms_messages m LEFT JOIN employees e ON e.id = m.employee_id WHERE m.id = ?', [$id]);
        $this->notFoundUnless($msg);
        if ($request->wantsJson()) {
            return json_response(['ok' => true, 'status' => $msg['status'], 'sent' => (int) $msg['sent'], 'failed' => (int) $msg['failed'], 'recipients' => (int) $msg['recipients']]);
        }
        $status = $request->str('status');
        $where = 'r.message_id = ?';
        $params = [$id];
        if (in_array($status, ['pending', 'sent', 'failed'], true)) {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }
        $recipients = DB::rows("SELECT r.*, q.description FROM sms_recipients r LEFT JOIN sms_requests q ON q.id = r.request_id WHERE $where ORDER BY r.id LIMIT 1000", $params);
        $requests = DB::rows('SELECT * FROM sms_requests WHERE message_id = ? ORDER BY id DESC LIMIT 50', [$id]);
        return view('sms/show', compact('msg', 'recipients', 'requests', 'status'));
    }

    /** إعادة إرسال الأرقام الفاشلة في رسالة جديدة */
    public function retry(Request $request, int $id): Response
    {
        $msg = DB::row('SELECT * FROM sms_messages WHERE id = ?', [$id]);
        $this->notFoundUnless($msg);
        $failed = DB::rows("SELECT r.*, s.full_name, s.username, s.expires_at, s.balance, p.name AS plan_name FROM sms_recipients r
                            LEFT JOIN subscribers s ON s.id = r.subscriber_id LEFT JOIN plans p ON p.id = s.plan_id WHERE r.message_id = ? AND r.status = 'failed'", [$id]);
        if (!$failed) {
            return redirect('/sms/log/' . $id);
        }
        $subs = [];
        $nums = [];
        foreach ($failed as $f) {
            if ($f['subscriber_id']) {
                $subs[] = ['id' => $f['subscriber_id'], 'full_name' => $f['full_name'] ?? $f['name'], 'username' => $f['username'], 'expires_at' => $f['expires_at'], 'balance' => $f['balance'], 'plan_name' => $f['plan_name'], 'msisdn' => $f['phone']];
            } else {
                $nums[] = $f['phone'];
            }
        }
        $new = SmsService::queue($msg['body'], $subs, $nums, 'manual', 'إعادة إرسال #' . $id);
        Session::release();
        Kernel::afterResponse(static fn () => SmsService::process($new, 600.0));
        return $this->ok('جارٍ إعادة الإرسال.', '/sms/log/' . $new);
    }

    // ------------------------------------------------------------------
    // القوالب
    // ------------------------------------------------------------------

    public function templateSave(Request $request): Response
    {
        $d = $this->validate($request, ['name' => 'required|max:80', 'body' => 'required|max:1000'], ['name' => 'اسم القالب', 'body' => 'نص القالب']);
        $id = $request->int('id');
        if ($id > 0) {
            DB::update('sms_templates', ['name' => $d['name'], 'body' => $d['body']], 'id = ?', [$id]);
        } else {
            DB::insert('sms_templates', ['name' => $d['name'], 'body' => $d['body'], 'created_at' => now()]);
        }
        return $this->ok('تم حفظ القالب.', '/sms/settings');
    }

    public function templateDelete(Request $request, int $id): Response
    {
        DB::delete('sms_templates', 'id = ?', [$id]);
        return $this->ok('تم حذف القالب.', '/sms/settings');
    }

    // ------------------------------------------------------------------
    // الإعدادات
    // ------------------------------------------------------------------

    public function settings(Request $request): Response
    {
        return view('sms/settings', [
            'hasKey' => SmsService::apiKey() !== '',
            'templates' => DB::rows('SELECT * FROM sms_templates ORDER BY name'),
        ]);
    }

    public function settingsSave(Request $request): Response
    {
        $d = $this->validate($request, [
            'sender' => 'nullable|max:11|regex:/^[0-9A-Za-z_.,;:?@ -]{2,11}$/',
            'remind_days' => 'required|int|between:1,30',
            'text_remind' => 'nullable|max:600', 'text_expired' => 'nullable|max:600', 'text_renew' => 'nullable|max:600',
        ], ['sender' => 'اسم المرسل', 'remind_days' => 'أيام التذكير']);
        $values = [
            'sms.sender' => (string) $d['sender'], 'sms.remind_days' => (string) $d['remind_days'],
            'sms.auto_remind' => $request->bool('auto_remind') ? '1' : '0', 'sms.auto_expired' => $request->bool('auto_expired') ? '1' : '0',
            'sms.auto_renew' => $request->bool('auto_renew') ? '1' : '0',
            'sms.text_remind' => (string) $d['text_remind'], 'sms.text_expired' => (string) $d['text_expired'], 'sms.text_renew' => (string) $d['text_renew'],
        ];
        $key = trim((string) $request->input('api_key', ''));
        if ($key !== '') {
            $values['sms.api_key'] = Crypto::encrypt($key);
        } elseif ($request->bool('remove_key')) {
            $values['sms.api_key'] = '';
        }
        Settings::setMany($values);
        Audit::log('settings.update', 'تعديل إعدادات الرسائل النصية', 'settings');
        return $this->ok('تم حفظ الإعدادات.', '/sms/settings');
    }

    /** فحص الاتصال والرصيد وأسماء المرسل */
    public function status(Request $request): Response
    {
        Session::release();
        $key = SmsService::apiKey();
        if ($key === '') {
            return json_response(['ok' => false, 'message' => 'أدخل مفتاح الربط.'], 422);
        }
        $user = TweetSms::call('chk_user', [], $key, 15);
        if (!$user['ok']) {
            return json_response(['ok' => false, 'message' => TweetSms::otherMessage($user)], 422);
        }
        [$okBal, $balance, $balMsg] = TweetSms::balance($key);
        return json_response([
            'ok' => true, 'message' => 'الربط يعمل.', 'balance' => $okBal ? $balance : null, 'balance_text' => $balMsg,
            'senders' => TweetSms::senders($key),
        ]);
    }

    /** رسالة تجريبية لرقم واحد */
    public function test(Request $request): Response
    {
        Session::release();
        $n = TweetSms::normalize($request->str('phone'));
        if ($n === null) {
            return json_response(['ok' => false, 'message' => 'الرقم غير صالح.'], 422);
        }
        if (!SmsService::configured()) {
            return json_response(['ok' => false, 'message' => 'أكمل إعدادات الرسائل أولاً.'], 422);
        }
        $id = SmsService::queue('رسالة تجريبية من ' . Settings::networkName() . '.', [], [$n], 'test', 'رسالة تجريبية');
        $r = SmsService::process($id, 30.0);
        $msg = DB::row('SELECT error FROM sms_messages WHERE id = ?', [$id]);
        return json_response(['ok' => $r['sent'] > 0, 'message' => $r['sent'] > 0 ? 'تم الإرسال.' : ($msg['error'] ?? 'تعذّر الإرسال.')], $r['sent'] > 0 ? 200 : 422);
    }
}
