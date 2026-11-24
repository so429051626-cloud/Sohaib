<?php
declare(strict_types=1);

namespace App\Platform;

use App\Core\Platform;
use App\Services\Mailer;
use Throwable;

/**
 * بريد المنصة إلى أصحاب الحسابات:
 *  - إعداد خادم SMTP من لوحة المسؤول (كلمة المرور مشفّرة بمفتاح المنصة ولا تُعرض).
 *  - رسائل تلقائية لكل منها مفتاح تشغيل وعنوان ونص يكتبهما المسؤول: الترحيب، الفاتورة عند كل دفعة،
 *    تذكير قرب الانتهاء وفترتي السماح والقراءة فقط، إشعار الإيقاف، استلام التحويل البنكي ورفضه، ورد الدعم الفني.
 *  - بريد جماعي يكتبه المسؤول، وسجل لكل رسالة أُرسلت أو تعذّر إرسالها.
 * لا تُوقف أي رسالة العملية التي سبّبتها إن تعذّر إرسالها: يُسجَّل الخطأ ويستمر النظام.
 */
final class PlatformMail
{
    /** الرسائل التلقائية: المفتاح => [مفتاح الإعداد، التسمية، الأيقونة، القوالب [المفتاح => التسمية]] */
    public const FEATURES = [
        'welcome'  => ['mail.welcome', 'رسالة ترحيب بالبريد بعد إنشاء الحساب', 'user-plus', ['welcome' => '']],
        'invoice'  => ['mail.invoice', 'الفاتورة بالبريد عند كل دفعة أو تمديد للاشتراك', 'receipt', ['invoice' => '']],
        'reminder' => ['mail.reminder', 'تذكير بقرب انتهاء الاشتراك، ثم فترة السماح والقراءة فقط', 'calendar-clock', ['reminder' => '']],
        'expired'  => ['mail.expired', 'إشعار إيقاف الحساب عند انتهاء الاشتراك', 'circle-x', ['expired' => '']],
        'transfer' => ['mail.transfer', 'تأكيد استلام التحويل البنكي، وسبب رفضه إن رُفض', 'hand-coins', ['transfer' => 'عند الاستلام', 'transfer_rejected' => 'عند الرفض']],
        'ticket'   => ['mail.ticket', 'إشعار رد الدعم الفني على تذكرة الزبون', 'message-circle', ['ticket' => '']],
    ];

    public const TEMPLATES = [
        'welcome_subject' => 'مرحباً بك في {المنصة}',
        'welcome' => "أهلاً وسهلاً بك {الاسم}،\n\nتم إنشاء حساب شبكتك «{الشبكة}» على {المنصة} بنجاح.\n\nاسم المستخدم: {اسم_المستخدم}\nرابط الدخول: {الرابط}\n\nنرجو الاحتفاظ باسم المستخدم وكلمة المرور في مكان آمن وعدم مشاركتهما مع أحد.\n\nمع أطيب التحيات،\nفريق {المنصة}",
        'invoice_subject' => 'فاتورة اشتراك {الشبكة} رقم {رقم_الفاتورة}',
        'invoice' => "مرحباً {الاسم}،\n\nشكراً لك. استلمنا دفعتك وتم تفعيل اشتراك شبكتك «{الشبكة}».\n\nالمبلغ: {المبلغ}\nالباقة: {الباقة}\nالمدة: {المدة}\nالاشتراك فعّال حتى: {حتى}\n\nتجد تفاصيل الفاتورة أدناه، ويمكنك طباعتها من صفحة الاشتراك في نظامك.\n\nمع أطيب التحيات،\nفريق {المنصة}",
        'reminder_subject' => 'تنبيه بخصوص اشتراك {الشبكة}',
        'reminder' => "مرحباً {الاسم}،\n\n{التنبيه}\n\nلتجديد الاشتراك ادخل إلى نظامك وافتح صفحة «الاشتراك»، واختر الباقة وأرسل التحويل، ويتمدد الاشتراك فور الموافقة عليه.\n\nمع أطيب التحيات،\nفريق {المنصة}",
        'expired_subject' => 'تم إيقاف حساب {الشبكة}',
        'expired' => "مرحباً {الاسم}،\n\nتم إيقاف حساب شبكتك «{الشبكة}» على {المنصة}: {السبب}.\n\nبياناتك محفوظة كما هي، ويعود الحساب للعمل فور تجديد الاشتراك. للتجديد تواصل معنا أو ادخل إلى نظامك.\n\nمع أطيب التحيات،\nفريق {المنصة}",
        'transfer_subject' => 'استلمنا طلب تجديد اشتراك {الشبكة}',
        'transfer' => "مرحباً {الاسم}،\n\nاستلمنا تحويلك بمبلغ {المبلغ} لباقة «{الباقة}» لمدة {المدة}، وهو الآن قيد المراجعة.\n\nسيتمدد اشتراكك فور الموافقة على التحويل، وتصلك الفاتورة بالبريد.\n\nمع أطيب التحيات،\nفريق {المنصة}",
        'transfer_rejected_subject' => 'لم تتم الموافقة على تحويل {الشبكة}',
        'transfer_rejected' => "مرحباً {الاسم}،\n\nنعتذر، لم تتم الموافقة على تحويلك بمبلغ {المبلغ}.\nالسبب: {السبب}\n\nيمكنك إرسال طلب جديد من صفحة «الاشتراك» في نظامك، أو التواصل معنا للمساعدة.\n\nمع أطيب التحيات،\nفريق {المنصة}",
        'ticket_subject' => 'رد جديد على تذكرتك: {الموضوع}',
        'ticket' => "مرحباً {الاسم}،\n\nرد فريق الدعم على تذكرتك «{الموضوع}»:\n\n{الرد}\n\nيمكنك متابعة المحادثة من صفحة «الدعم الفني» في نظامك.\n\nمع أطيب التحيات،\nفريق {المنصة}",
    ];

    /** رموز الترحيب (تبقى لتوافق الشاشات القديمة) */
    public const PLACEHOLDERS = ['{الاسم}' => 'اسم المالك', '{الشبكة}' => 'اسم الشبكة', '{اسم_المستخدم}' => 'اسم المستخدم', '{الرابط}' => 'رابط الدخول', '{المنصة}' => 'اسم المنصة'];

    /** رموز كل قالب: المشتركة ثم الخاصة */
    private const COMMON = ['{الاسم}' => 'اسم المالك', '{الشبكة}' => 'اسم الشبكة', '{المنصة}' => 'اسم المنصة', '{الرابط}' => 'رابط النظام'];
    private const TOKENS = [
        'welcome' => ['{اسم_المستخدم}' => 'اسم المستخدم'],
        'invoice' => ['{رقم_الفاتورة}' => 'رقم الفاتورة', '{المبلغ}' => 'المبلغ', '{الباقة}' => 'الباقة', '{المدة}' => 'المدة', '{حتى}' => 'فعّال حتى', '{طريقة_الدفع}' => 'طريقة الدفع', '{التاريخ}' => 'التاريخ'],
        'reminder' => ['{التنبيه}' => 'نص التنبيه', '{تاريخ_الانتهاء}' => 'تاريخ الانتهاء'],
        'expired' => ['{السبب}' => 'السبب'],
        'transfer' => ['{المبلغ}' => 'المبلغ', '{الباقة}' => 'الباقة', '{المدة}' => 'المدة', '{رقم_العملية}' => 'رقم العملية'],
        'transfer_rejected' => ['{المبلغ}' => 'المبلغ', '{السبب}' => 'السبب'],
        'ticket' => ['{الموضوع}' => 'موضوع التذكرة', '{الرد}' => 'نص الرد'],
        'broadcast' => [],
    ];

    /** أنواع السجل */
    public const KINDS = [
        'welcome' => 'ترحيب', 'invoice' => 'فاتورة', 'reminder' => 'تذكير', 'expired' => 'إيقاف', 'transfer' => 'تحويل',
        'ticket' => 'دعم فني', 'broadcast' => 'بريد جماعي', 'test' => 'تجريبية',
    ];

    public const SUBJECT_MAX = 150;
    public const BODY_MAX = 4000;

    /**
     * إعدادات الخادم المحفوظة، أو ما كُتب في النموذج قبل الحفظ (الحقل الفارغ يعني المحفوظ، وكلمة المرور الفارغة تعني المحفوظة).
     * @return array{host:string, port:int, secure:string, user:string, pass:string, from:string, from_name:string}
     */
    public static function config(array $with = []): array
    {
        $get = static fn (string $k, string $saved): string => array_key_exists($k, $with) && trim((string) $with[$k]) !== '' ? trim((string) $with[$k]) : $saved;
        $secure = $get('secure', Platform::setting('mail.secure', 'tls'));
        return [
            'host' => $get('host', Platform::setting('mail.host', '')),
            'port' => (int) $get('port', Platform::setting('mail.port', '587')),
            'secure' => isset(Mailer::SECURE[$secure]) ? $secure : 'tls',
            'user' => $get('user', Platform::setting('mail.user', '')),
            'pass' => $get('pass', Platform::secret('mail.pass')),
            'from' => $get('from', Platform::setting('mail.from', '')),
            'from_name' => $get('from_name', Platform::setting('mail.from_name', '')) ?: PlatformSms::name(),
        ];
    }

    public static function configured(): bool
    {
        $c = self::config();
        return $c['host'] !== '' && filter_var($c['from'], FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function enabled(string $feature): bool
    {
        $key = self::FEATURES[$feature][0] ?? null;
        return $key !== null && Platform::setting($key) === '1' && self::configured();
    }

    public static function template(string $key): string
    {
        $t = trim(Platform::setting('mail.tpl_' . $key, ''));
        return $t !== '' ? $t : (self::TEMPLATES[$key] ?? '');
    }

    /** رموز قالب (المشتركة والخاصة) للأزرار في لوحة المسؤول */
    public static function tokens(string $tpl): array
    {
        return self::COMMON + (self::TOKENS[$tpl] ?? []);
    }

    /** @return array{0:string, 1:string} [العنوان، النص] بعد تعويض الرموز */
    public static function render(string $tpl, array $vars): array
    {
        $vars += ['{المنصة}' => PlatformSms::name(), '{الرابط}' => Platform::rootUrl() . '/login'];
        // الرموز غير المعروفة في هذا القالب تُحذف بدل أن تصل كما هي
        $clean = static fn (string $s): string => trim((string) preg_replace('/\{[\p{Arabic}_]+\}/u', '', strtr($s, $vars)));
        return [$clean(self::template($tpl . '_subject')), $clean(self::template($tpl))];
    }

    /** @return array{0:string, 1:string} [العنوان، النص] */
    public static function welcomeContent(string $ownerName, string $network, string $username): array
    {
        return self::render('welcome', ['{الاسم}' => trim($ownerName), '{الشبكة}' => trim($network), '{اسم_المستخدم}' => $username]);
    }

    /**
     * قالب HTML من اليمين لليسار بلون المنصة: الفقرات، ثم جدول التفاصيل إن وُجد (الفاتورة)، ثم الزر.
     * @param list<array{0:string, 1:string}> $rows صفوف جدول [التسمية، القيمة]، والأخير يُبرز إن كان $total
     */
    public static function html(string $subject, string $text, ?string $button = null, ?string $buttonLabel = null, array $rows = [], bool $total = false): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $paras = '';
        foreach (preg_split("/\n{2,}/", trim($text)) ?: [] as $p) {
            $p = preg_replace_callback('#https?://[^\s<]+#u', static fn ($m) => '<a href="' . $e($m[0]) . '" style="color:#1d4ed8">' . $e($m[0]) . '</a>', $e($p));
            $paras .= '<p style="margin:0 0 14px;line-height:1.9">' . nl2br((string) $p) . '</p>';
        }
        $table = '';
        if ($rows) {
            $table = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:6px 0 18px;border:1px solid #e2e8f0;border-radius:12px;border-collapse:separate;overflow:hidden">';
            $last = count($rows) - 1;
            foreach (array_values($rows) as $i => [$label, $value]) {
                $strong = $total && $i === $last;
                $bg = $strong ? '#eff6ff' : ($i % 2 ? '#f8fafc' : '#ffffff');
                $table .= '<tr><td style="padding:10px 14px;background:' . $bg . ';color:#64748b;font-size:13px;text-align:right;border-top:' . ($i ? '1px solid #e2e8f0' : '0') . '" dir="rtl">' . $e($label) . '</td>'
                    . '<td style="padding:10px 14px;background:' . $bg . ';color:' . ($strong ? '#1d4ed8' : '#0f172a') . ';font-size:' . ($strong ? '17px' : '14px') . ';font-weight:700;text-align:left;border-top:' . ($i ? '1px solid #e2e8f0' : '0') . '" dir="auto">' . $e($value) . '</td></tr>';
            }
            $table .= '</table>';
        }
        $btn = $button !== null
            ? '<p style="margin:22px 0 6px"><a href="' . $e($button) . '" style="display:inline-block;background:#1d4ed8;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 26px;border-radius:10px">' . $e($buttonLabel ?? 'الدخول إلى حسابك') . '</a></p>'
            : '';
        $name = $e(PlatformSms::name());
        return '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $e($subject) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Tahoma,Arial,sans-serif;color:#0f172a">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:24px 12px"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 8px 30px rgba(15,23,42,.08)">'
            . '<tr><td style="background:linear-gradient(135deg,#1e3a8a,#2563eb 60%,#0891b2);background-color:#1e3a8a;padding:22px 28px;color:#ffffff;font-size:20px;font-weight:700;text-align:right" dir="rtl">' . $name . '</td></tr>'
            . '<tr><td style="padding:28px;font-size:15px;text-align:right" dir="rtl">' . $paras . $table . $btn . '</td></tr>'
            . '<tr><td style="padding:16px 28px;background:#f8fafc;color:#64748b;font-size:12px;text-align:right" dir="rtl">' . $name . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** @return array{ok:bool, message:string} */
    public static function send(string $to, string $subject, string $text, ?string $button = null, array $with = [], ?string $buttonLabel = null, array $rows = [], bool $total = false): array
    {
        return Mailer::send(self::config($with), $to, $subject, self::html($subject, $text, $button, $buttonLabel, $rows, $total), $text);
    }

    /**
     * إرسال مع التسجيل في سجل البريد. لا يرمي استثناءً: الفشل يُسجَّل ويُعاد.
     * @return array{ok:bool, message:string}
     */
    public static function deliver(string $kind, ?int $tenantId, string $to, string $subject, string $text, ?string $button = null, ?string $buttonLabel = null, array $rows = [], bool $total = false, ?int $adminId = null): array
    {
        try {
            $r = self::send($to, $subject, $text, $button, [], $buttonLabel, $rows, $total);
        } catch (Throwable $e) {
            $r = ['ok' => false, 'message' => $e->getMessage()];
        }
        self::log($kind, $tenantId, $to, $subject, $r, $adminId);
        if (!$r['ok']) {
            log_message('error', 'platform mail (' . $kind . ($tenantId ? ' #' . $tenantId : '') . '): ' . $r['message']);
        }
        return $r;
    }

    public static function log(string $kind, ?int $tenantId, string $to, string $subject, array $r, ?int $adminId = null): void
    {
        try {
            Platform::insert('platform_mail_log', [
                'tenant_id' => $tenantId, 'kind' => mb_substr($kind, 0, 20), 'to_email' => mb_substr($to, 0, 190), 'subject' => mb_substr($subject, 0, 255),
                'ok' => $r['ok'] ? 1 : 0, 'error' => $r['ok'] ? null : mb_substr((string) $r['message'], 0, 255), 'admin_id' => $adminId, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            log_message('error', 'platform mail log: ' . $e->getMessage());
        }
    }

    /** بريد الحساب إن كان صالحاً والرسالة مفعّلة */
    private static function recipient(?array $tenant, string $feature): ?string
    {
        $to = trim((string) ($tenant['email'] ?? ''));
        return $tenant !== null && $to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) && self::enabled($feature) ? $to : null;
    }

    private static function base(array $tenant): array
    {
        return ['{الاسم}' => trim((string) ($tenant['owner_name'] ?? '')), '{الشبكة}' => trim((string) ($tenant['name'] ?? '')),
            '{الرابط}' => Platform::rootUrl() . '/' . ($tenant['slug'] ?? '')];
    }

    private static function money(float $amount, string $currency): string
    {
        $n = abs($amount - round($amount)) < 0.005 ? number_format($amount, 0, '.', ',') : number_format($amount, 2, '.', ',');
        return $n . ' ' . $currency;
    }

    // ------------------------------------------------------------------
    // الرسائل التلقائية
    // ------------------------------------------------------------------

    /** رسالة الترحيب بالبريد بعد إنشاء الحساب (لا توقف إنشاء الحساب إن تعذّر إرسالها) */
    public static function welcome(array $tenant, string $username): bool
    {
        $to = self::recipient($tenant, 'welcome');
        if ($to === null) {
            return false;
        }
        [$subject, $text] = self::welcomeContent((string) $tenant['owner_name'], (string) $tenant['name'], $username);
        return self::deliver('welcome', isset($tenant['id']) ? (int) $tenant['id'] : null, $to, $subject, $text, Platform::rootUrl() . '/login')['ok'];
    }

    /** محتوى الفاتورة: [العنوان، النص، صفوف الجدول، رابط الفاتورة] */
    public static function invoiceContent(array $p): array
    {
        $amount = self::money((float) $p['amount'], (string) $p['currency']);
        $period = $p['months'] ? Billing::monthsLabel((int) $p['months']) : '—';
        $until = $p['paid_until'] ? substr((string) $p['paid_until'], 0, 10) : '—';
        $vars = self::base(['owner_name' => $p['owner_name'] ?? '', 'name' => $p['tenant_name'] ?? '', 'slug' => $p['slug'] ?? '']) + [
            '{رقم_الفاتورة}' => (string) $p['invoice_no'], '{المبلغ}' => $amount, '{الباقة}' => (string) ($p['plan_name'] ?? '—'), '{المدة}' => $period,
            '{حتى}' => $until, '{طريقة_الدفع}' => (string) ($p['method'] ?? '—'), '{التاريخ}' => substr((string) $p['created_at'], 0, 10),
        ];
        [$subject, $text] = self::render('invoice', $vars);
        $rows = array_values(array_filter([
            ['رقم الفاتورة', (string) $p['invoice_no']],
            ['التاريخ', substr((string) $p['created_at'], 0, 10)],
            ['الحساب', (string) ($p['tenant_name'] ?? '')],
            !empty($p['plan_name']) ? ['الباقة', (string) $p['plan_name']] : null,
            $p['months'] ? ['المدة', $period] : null,
            $p['paid_until'] ? ['الاشتراك فعّال حتى', $until] : null,
            !empty($p['method']) ? ['طريقة الدفع', (string) $p['method']] : null,
            ['المبلغ المدفوع', $amount],
        ]));
        $url = !empty($p['slug']) ? Platform::rootUrl() . '/' . $p['slug'] . '/billing/invoice/' . (int) $p['id'] : null;
        return [$subject, $text, $rows, $url];
    }

    /** الفاتورة بالبريد بعد تسجيل دفعة */
    public static function invoice(int $paymentId): bool
    {
        $p = Billing::invoice($paymentId);
        if (!$p || !$p['tenant_id']) {
            return false;
        }
        $tenant = Platform::row('SELECT * FROM tenants WHERE id = ?', [$p['tenant_id']]);
        $to = self::recipient($tenant, 'invoice');
        if ($to === null) {
            return false;
        }
        [$subject, $text, $rows, $url] = self::invoiceContent($p + ['tenant_name' => $tenant['name']]);
        return self::deliver('invoice', (int) $tenant['id'], $to, $subject, $text, $url, 'عرض الفاتورة وطباعتها', $rows, true)['ok'];
    }

    /** تذكير الاشتراك (قبل الانتهاء، فترة السماح، القراءة فقط) */
    public static function reminder(array $tenant, string $notice, string $end): bool
    {
        $to = self::recipient($tenant, 'reminder');
        if ($to === null) {
            return false;
        }
        [$subject, $text] = self::render('reminder', self::base($tenant) + ['{التنبيه}' => $notice, '{تاريخ_الانتهاء}' => substr($end, 0, 10)]);
        return self::deliver('reminder', (int) $tenant['id'], $to, $subject, $text, Platform::rootUrl() . '/' . $tenant['slug'] . '/billing', 'تجديد الاشتراك')['ok'];
    }

    /** إشعار إيقاف الحساب */
    public static function expired(array $tenant, string $reason): bool
    {
        $to = self::recipient($tenant, 'expired');
        if ($to === null) {
            return false;
        }
        [$subject, $text] = self::render('expired', self::base($tenant) + ['{السبب}' => $reason]);
        return self::deliver('expired', (int) $tenant['id'], $to, $subject, $text, Platform::rootUrl() . '/' . $tenant['slug'] . '/billing', 'تجديد الاشتراك')['ok'];
    }

    /** تأكيد استلام طلب التحويل */
    public static function transferReceived(array $tenant, array $t): bool
    {
        $to = self::recipient($tenant, 'transfer');
        if ($to === null) {
            return false;
        }
        $amount = self::money((float) $t['amount'], (string) $t['currency']);
        [$subject, $text] = self::render('transfer', self::base($tenant) + ['{المبلغ}' => $amount, '{الباقة}' => (string) $t['plan_name'],
            '{المدة}' => Billing::monthsLabel((int) $t['months']), '{رقم_العملية}' => (string) ($t['reference'] ?? '—')]);
        $rows = array_values(array_filter([['الباقة', (string) $t['plan_name']], ['المدة', Billing::monthsLabel((int) $t['months'])],
            !empty($t['reference']) ? ['رقم العملية', (string) $t['reference']] : null, ['المبلغ', $amount]]));
        return self::deliver('transfer', (int) $tenant['id'], $to, $subject, $text, null, null, $rows, true)['ok'];
    }

    /** رفض التحويل مع السبب */
    public static function transferRejected(array $tenant, array $t, string $reason): bool
    {
        $to = self::recipient($tenant, 'transfer');
        if ($to === null) {
            return false;
        }
        [$subject, $text] = self::render('transfer_rejected', self::base($tenant) + ['{المبلغ}' => self::money((float) $t['amount'], (string) $t['currency']),
            '{السبب}' => $reason !== '' ? $reason : 'لم يُذكر سبب']);
        return self::deliver('transfer', (int) $tenant['id'], $to, $subject, $text, Platform::rootUrl() . '/' . $tenant['slug'] . '/billing', 'صفحة الاشتراك')['ok'];
    }

    /** رد الدعم الفني على تذكرة */
    public static function ticketReply(array $tenant, array $ticket, string $body): bool
    {
        $to = self::recipient($tenant, 'ticket');
        if ($to === null) {
            return false;
        }
        [$subject, $text] = self::render('ticket', self::base($tenant) + ['{الموضوع}' => (string) $ticket['subject'], '{الرد}' => mb_substr(trim($body), 0, 3000)]);
        return self::deliver('ticket', (int) $tenant['id'], $to, $subject, $text, Platform::rootUrl() . '/' . $tenant['slug'] . '/support/' . (int) $ticket['id'], 'فتح التذكرة')['ok'];
    }

    /** عيّنة لمعاينة قالب في لوحة المسؤول: [العنوان، النص، الصفوف، الزر، تسمية الزر] */
    public static function sample(string $tpl): array
    {
        $tenant = ['id' => 0, 'owner_name' => 'محمد', 'name' => 'شبكة النور', 'slug' => 'alnoor'];
        $cur = Platform::setting('platform.currency', '₪');
        return match ($tpl) {
            'invoice' => (static function () use ($cur): array {
                [$s, $t, $rows, $url] = self::invoiceContent(['id' => 12, 'invoice_no' => Billing::invoiceNo(12), 'amount' => 150, 'currency' => $cur, 'months' => 3,
                    'paid_until' => date('Y-m-d', strtotime('+3 months')), 'method' => 'تحويل بنكي', 'plan_name' => 'الاحترافية', 'created_at' => date('Y-m-d H:i:s'),
                    'owner_name' => 'محمد', 'tenant_name' => 'شبكة النور', 'slug' => 'alnoor']);
                return [$s, $t, $rows, $url, 'عرض الفاتورة وطباعتها'];
            })(),
            'reminder' => [...self::render('reminder', self::base($tenant) + ['{التنبيه}' => 'اشتراك شبكة النور ينتهي خلال 3 أيام.', '{تاريخ_الانتهاء}' => date('Y-m-d', strtotime('+3 days'))]), [], Platform::rootUrl() . '/alnoor/billing', 'تجديد الاشتراك'],
            'expired' => [...self::render('expired', self::base($tenant) + ['{السبب}' => 'انتهت مدة الاشتراك']), [], Platform::rootUrl() . '/alnoor/billing', 'تجديد الاشتراك'],
            'transfer' => [...self::render('transfer', self::base($tenant) + ['{المبلغ}' => self::money(150, $cur), '{الباقة}' => 'الاحترافية', '{المدة}' => Billing::monthsLabel(3), '{رقم_العملية}' => '784512']),
                [['الباقة', 'الاحترافية'], ['المدة', Billing::monthsLabel(3)], ['رقم العملية', '784512'], ['المبلغ', self::money(150, $cur)]], null, null],
            'transfer_rejected' => [...self::render('transfer_rejected', self::base($tenant) + ['{المبلغ}' => self::money(150, $cur), '{السبب}' => 'الصورة غير واضحة']), [], Platform::rootUrl() . '/alnoor/billing', 'صفحة الاشتراك'],
            'ticket' => [...self::render('ticket', self::base($tenant) + ['{الموضوع}' => 'مشكلة في طباعة الكروت', '{الرد}' => 'أهلاً بك، تم حل المشكلة. جرّب الطباعة من جديد.']), [], Platform::rootUrl() . '/alnoor/support/1', 'فتح التذكرة'],
            default => [...self::welcomeContent('محمد', 'شبكة النور', 'mohammad'), [], Platform::rootUrl() . '/login', null],
        };
    }
}
