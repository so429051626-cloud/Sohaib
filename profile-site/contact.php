<?php
// يستقبل نموذج "تواصل معي": يحفظ الرسالة لتظهر في لوحة التحكم (تبويب الرسائل).
// مع JavaScript: يرجع JSON والصفحة تفتح واتساب برسالة جاهزة.
// بدون JavaScript: يحفظ الرسالة ثم يحوّل الزائر إلى واتساب مباشرة.

declare(strict_types=1);
require __DIR__ . '/inc/core.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$ajax = req_str($_POST, 'ajax') === '1';

function reply(bool $ajax, string $status, string $redirect): void
{
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status === 'ok' ? 200 : ($status === 'limit' ? 429 : 400));
        echo json_encode(['status' => $status]);
    } else {
        header('Location: ' . $redirect, true, 303);
    }
    exit;
}

$lang = req_str($_POST, 'lang') === 'ar' ? 'ar' : 'en';
$back = base_url() . ($lang === 'ar' ? '?lang=ar&' : '?') . 'sent=1#contact';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . base_url(), true, 303);
    exit;
}

// نقبل الطلبات القادمة من نفس الموقع فقط
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$host   = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
if ($origin !== '' && $origin !== 'null' && strtolower((string) parse_url($origin, PHP_URL_HOST)) !== $host) {
    reply($ajax, 'bad', base_url());
}

// الحقل المخفي: الروبوتات تملؤه والبشر لا يرونه (ولا نرفض متصفحات حقيقية بسبب اسمها)
if (trim(req_str($_POST, 'website')) !== '' || user_agent() === '') {
    reply($ajax, 'ok', $back);
}

function field(string $key, int $max): string
{
    $v = str_replace(["\r\n", "\r"], "\n", req_str($_POST, $key));
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr(trim($v), 0, $max, 'UTF-8');
}

$msg = [
    'name'     => field('name', 80),
    'whatsapp' => field('whatsapp', 30),
    'email'    => field('email', 120),
    'company'  => field('company', 100),
    'service'  => field('service', 100),
    'budget'   => field('budget', 60),
    'timeline' => field('timeline', 60),
    'details'  => field('details', 2000),
    'lang'     => $lang,
];
if ($msg['email'] !== '' && !filter_var($msg['email'], FILTER_VALIDATE_EMAIL)) {
    $msg['email'] = '';
}
if ($msg['name'] === '' || $msg['details'] === '') {
    reply($ajax, 'bad', base_url() . ($lang === 'ar' ? '?lang=ar' : '') . '#contact');
}

$err = message_add($msg);
if ($err === 'limit') {
    reply($ajax, 'limit', wa_link(content()['contact']['whatsapp']) ?: base_url());
}
if ($err !== '') {
    reply($ajax, 'save', $back);
}
if (!$ajax) {
    track_click('form');
    // بدون JavaScript: نفتح واتساب برسالة جاهزة تحوي تفاصيل الطلب
    $lines = array_filter([
        $msg['name'], $msg['whatsapp'], $msg['email'], $msg['company'], $msg['service'], $msg['budget'], $msg['timeline'], '', $msg['details'],
    ], function ($v) { return $v !== null; });
    $wa = wa_link(content()['contact']['whatsapp'], implode("\n", $lines));
    header('Location: ' . ($wa !== '' ? $wa : $back), true, 303);
    exit;
}
reply(true, 'ok', $back);
