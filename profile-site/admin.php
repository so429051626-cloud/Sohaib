<?php
// لوحة تحكم صهيب قديح: إحصائيات الزوار + تعديل كل نصوص الصفحة + الصورة + كلمة المرور.

declare(strict_types=1);
require __DIR__ . '/inc/core.php';
require __DIR__ . '/inc/auth.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, max-age=0');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");

ensure_dirs();
admin_session_start();

const TABS = ['stats' => 'الإحصائيات', 'content' => 'تعديل الصفحة', 'photo' => 'الصورة', 'settings' => 'الإعدادات'];

/* ───────── تعريف حقول التعديل ───────── */

function content_schema(): array
{
    return [
        'hero' => ['title' => 'الواجهة الرئيسية', 'fields' => [
            'hello'         => ['التحية فوق الاسم', 'text'],
            'name'          => ['الاسم بالعربي', 'text'],
            'name_en'       => ['الاسم بالإنجليزي', 'text'],
            'badge'         => ['الشارة الذهبية اللافتة', 'text'],
            'headline'      => ['المسمّى الرئيسي', 'text'],
            'subline'       => ['الوصف تحت المسمّى', 'textarea'],
            'status'        => ['الحالة بجانب الصورة (اتركه فارغاً لإخفائها)', 'text'],
            'btn_primary'   => ['نص زر واتساب', 'text'],
            'btn_secondary' => ['نص زر الأعمال', 'text'],
        ]],
        'code' => ['title' => 'الكود الذي يُكتب تلقائياً', 'fields' => [
            'filename' => ['اسم الملف أعلى النافذة', 'text'],
            'lines'    => ['أسطر الكود (كل سطر في سطر، يفضّل 5 إلى 7 أسطر)', 'code'],
        ]],
        'about' => ['title' => 'النبذة', 'fields' => [
            'kicker' => ['العنوان الصغير', 'text'],
            'title'  => ['عنوان القسم', 'text'],
            'lead'   => ['السطر المميّز', 'text'],
            'text'   => ['النبذة', 'textarea'],
            'chips'  => ['النقاط المختصرة (كل نقطة في سطر)', 'textarea'],
        ]],
        'services' => ['title' => 'الخدمات', 'fields' => [
            'kicker' => ['العنوان الصغير', 'text'],
            'title'  => ['عنوان القسم', 'text'],
        ], 'list' => ['label' => 'خدمة', 'main' => 'title', 'fields' => [
            'icon'  => ['الأيقونة', 'icon'],
            'title' => ['اسم الخدمة', 'text'],
            'text'  => ['الوصف', 'textarea'],
        ]]],
        'projects' => ['title' => 'الأعمال والمنصات', 'fields' => [
            'kicker' => ['العنوان الصغير', 'text'],
            'title'  => ['عنوان القسم', 'text'],
            'cta'    => ['نص رابط الزيارة', 'text'],
        ], 'list' => ['label' => 'مشروع', 'main' => 'name', 'fields' => [
            'icon' => ['الأيقونة', 'icon'],
            'name' => ['الاسم', 'text'],
            'tag'  => ['التصنيف', 'text'],
            'text' => ['الوصف', 'textarea'],
            'url'  => ['الرابط', 'url'],
        ]]],
        'contact' => ['title' => 'التواصل والروابط', 'fields' => [
            'kicker'         => ['العنوان الصغير', 'text'],
            'title'          => ['عنوان القسم', 'text'],
            'text'           => ['الوصف', 'textarea'],
            'whatsapp'       => ['رقم واتساب مع مفتاح الدولة (أرقام فقط)', 'tel'],
            'whatsapp_text'  => ['الرسالة الجاهزة عند فتح واتساب', 'text'],
            'whatsapp_label' => ['اسم زر واتساب', 'text'],
            'facebook'       => ['رابط فيسبوك', 'url'],
            'facebook_label' => ['اسم زر فيسبوك', 'text'],
            'tiktok'         => ['رابط تيك توك', 'url'],
            'tiktok_label'   => ['اسم زر تيك توك', 'text'],
        ]],
        'nav' => ['title' => 'القائمة العلوية', 'fields' => [
            'about'    => ['رابط النبذة', 'text'],
            'services' => ['رابط الخدمات', 'text'],
            'projects' => ['رابط الأعمال', 'text'],
            'contact'  => ['رابط التواصل', 'text'],
            'cta'      => ['زر المراسلة', 'text'],
        ]],
        'footer' => ['title' => 'التذييل', 'fields' => [
            'name'    => ['الاسم في التذييل', 'text'],
            'rights'  => ['عبارة الحقوق', 'text'],
            'en_line' => ['السطر الإنجليزي', 'text'],
        ]],
        'seo' => ['title' => 'الظهور في جوجل (SEO)', 'fields' => [
            'title'       => ['عنوان الصفحة في نتائج البحث (أفضل طول: 50 إلى 65 حرفاً)', 'text', 70],
            'description' => ['الوصف في نتائج البحث (أفضل طول: 120 إلى 160 حرفاً)', 'textarea', 160],
            'keywords'    => ['الكلمات المفتاحية (مفصولة بفواصل)', 'textarea'],
            'alt_names'   => ['كل أشكال كتابة اسمك التي قد يبحث بها الناس (كل شكل في سطر)', 'textarea'],
            'site_url'    => ['رابط الصفحة النهائي (مثال: https://sohaib.example.com/)', 'url'],
            'google_verification' => ['كود التحقق من Google Search Console (الصق الوسم كاملاً أو قيمة content فقط)', 'text'],
        ]],
    ];
}

function clean_text($v, int $max = 4000): string
{
    $v = is_string($v) ? $v : '';
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    $v = trim($v);
    return mb_substr($v, 0, $max, 'UTF-8');
}

function clean_field(string $type, $raw): string
{
    switch ($type) {
        case 'url':
            return safe_url(clean_text($raw, 500));
        case 'tel':
            return digits(clean_text($raw, 30));
        case 'icon':
            $v = clean_text($raw, 40);
            return array_key_exists($v, selectable_icons()) ? $v : 'code-xml';
        case 'code':
            // الكود: نحافظ على المسافات في بداية الأسطر
            $v = is_string($raw) ? str_replace(["\r\n", "\r"], "\n", $raw) : '';
            $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
            $lines = array_slice(explode("\n", rtrim($v)), 0, 14);
            return mb_substr(implode("\n", array_map('rtrim', $lines)), 0, 1500, 'UTF-8');
        default:
            return clean_text($raw);
    }
}

function save_content_from_post(array $post): bool
{
    $schema = content_schema();
    $current = content();
    $new = $current;
    foreach ($schema as $sec => $def) {
        $in = isset($post[$sec]) && is_array($post[$sec]) ? $post[$sec] : [];
        foreach ($def['fields'] as $key => $f) {
            if (array_key_exists($key, $in)) {
                $new[$sec][$key] = clean_field($f[1], $in[$key]);
            }
        }
        if (isset($def['list'])) {
            $items = [];
            $rows = isset($in['items']) && is_array($in['items']) ? $in['items'] : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $item = [];
                foreach ($def['list']['fields'] as $key => $f) {
                    $item[$key] = clean_field($f[1], $row[$key] ?? '');
                }
                if ($item[$def['list']['main']] !== '' && count($items) < 20) {
                    $items[] = $item;
                }
            }
            $new[$sec]['items'] = $items;
        }
    }
    // لو لُصق وسم التحقق كاملاً نأخذ قيمة content فقط
    $gv = $new['seo']['google_verification'];
    if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $gv, $m)) {
        $gv = $m[1];
    }
    $new['seo']['google_verification'] = preg_replace('/[^A-Za-z0-9_\-]/', '', $gv) ?? '';
    if ($new['hero']['name'] === '') {
        $new['hero']['name'] = $current['hero']['name'];
    }

    return store_update('content', function () use ($new) {
        return $new;
    });
}

/* ───────── الصورة الشخصية ───────── */

function upload_error_text(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'حجم الصورة أكبر من المسموح على الاستضافة.';
        case UPLOAD_ERR_NO_FILE:
            return 'لم تختر صورة.';
        default:
            return 'تعذّر رفع الصورة، حاول مرة أخرى.';
    }
}

function handle_avatar_upload(): string
{
    $f = $_FILES['avatar'] ?? null;
    if (!is_array($f) || is_array($f['error'] ?? null)) {
        return 'لم تختر صورة.';
    }
    if ((int) $f['error'] !== UPLOAD_ERR_OK) {
        return upload_error_text((int) $f['error']);
    }
    if ((int) $f['size'] > 6 * 1024 * 1024) {
        return 'حجم الصورة أكبر من 6 ميغابايت.';
    }
    if (!is_uploaded_file($f['tmp_name'])) {
        return 'تعذّر رفع الصورة.';
    }
    $info = @getimagesize($f['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) {
        return 'الملف ليس صورة مدعومة (JPG أو PNG أو WEBP فقط).';
    }
    if ($info[0] < 64 || $info[1] < 64 || $info[0] > 8000 || $info[1] > 8000) {
        return 'أبعاد الصورة غير مناسبة.';
    }
    // الصور الضخمة جداً تستهلك ذاكرة أكثر مما تسمح به الاستضافات المشتركة
    if ($info[0] * $info[1] > 25000000) {
        return 'الصورة كبيرة جداً (أكثر من 25 ميغابكسل). صغّرها أو أرسلها لنفسك على واتساب ثم ارفعها.';
    }

    ensure_dirs();
    if (!is_file(UPLOAD_DIR . '/.htaccess')) {
        @file_put_contents(UPLOAD_DIR . '/.htaccess', "<IfModule mod_rewrite.c>\n  RewriteEngine On\n  RewriteRule \\.(?i:php[0-9]?|phtml|phar|pl|py|cgi|sh|asp|aspx|jsp)$ - [F,L]\n</IfModule>\n");
    }

    $name = 'avatar-' . bin2hex(random_bytes(6));
    $rel = '';

    // إن توفّرت مكتبة GD: نقصّ الصورة مربّعاً 512×512 ونعيد حفظها (يحذف أي بيانات مخفية فيها)
    if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg')) {
        @ini_set('memory_limit', '256M');
        $src = null;
        if ($info[2] === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg')) {
            $src = @imagecreatefromjpeg($f['tmp_name']);
            if ($src && function_exists('exif_read_data')) {
                $exif = @exif_read_data($f['tmp_name']);
                $o = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
                $angle = [3 => 180, 6 => 270, 8 => 90][$o] ?? 0;
                if ($angle) {
                    $rot = imagerotate($src, $angle, 0);
                    if ($rot) {
                        $src = $rot;
                    }
                }
            }
        } elseif ($info[2] === IMAGETYPE_PNG && function_exists('imagecreatefrompng')) {
            $src = @imagecreatefrompng($f['tmp_name']);
        } elseif ($info[2] === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
            $src = @imagecreatefromwebp($f['tmp_name']);
        }
        // لو كان قارئ هذا النوع موجوداً وفشل، فالصورة تالفة. لو لم يكن موجوداً (WEBP على استضافات قديمة) نحفظها كما هي
        $decoder = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'][$info[2]];
        if (!$src && function_exists($decoder)) {
            return 'تعذّر قراءة الصورة (قد تكون تالفة). جرّب صورة أخرى.';
        }
        if ($src) {
            $w = imagesx($src);
            $h = imagesy($src);
            $side = min($w, $h);
            $x = (int) (($w - $side) / 2);
            $y = $h > $w ? (int) (($h - $side) * 0.25) : 0; // الصور الطولية: الوجه غالباً في الأعلى
            $dst = imagecreatetruecolor(512, 512);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 10, 17, 32));
            imagecopyresampled($dst, $src, 0, 0, $x, $y, 512, 512, $side, $side);
            if (@imagejpeg($dst, UPLOAD_DIR . '/' . $name . '.jpg', 88)) {
                $rel = 'uploads/' . $name . '.jpg';
            }
        }
    }
    if ($rel === '') {
        $ext = $types[$info[2]];
        if (!@move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name . '.' . $ext)) {
            return 'تعذّر حفظ الصورة على الاستضافة (تأكد من صلاحيات مجلد uploads).';
        }
        $rel = 'uploads/' . $name . '.' . $ext;
    }

    if (!set_avatar($rel)) {
        @unlink(APP_ROOT . '/' . $rel);
        return 'تعذّر الحفظ: تأكد أن مجلد data قابل للكتابة وأن مساحة الاستضافة غير ممتلئة.';
    }
    return '';
}

function set_avatar(string $rel): bool
{
    $old = content()['avatar'];
    $ok = store_update('content', function (array $c) use ($rel) {
        $c = merge_content(default_content(), $c);
        $c['avatar'] = $rel;
        return $c;
    });
    if ($ok && $old !== '' && $old !== $rel && preg_match('#^uploads/avatar-[a-f0-9]+\.(jpg|png|webp)$#', $old)) {
        @unlink(APP_ROOT . '/' . $old);
    }
    return $ok;
}

// أقصى حجم رفع فعلي: الأصغر بين 6 ميغابايت وحدود الاستضافة
function ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || !preg_match('/^(\d+(?:\.\d+)?)\s*([kmg]?)/i', $v, $m)) {
        return PHP_INT_MAX;
    }
    $mult = ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower($m[2])];
    $n = (int) ((float) $m[1] * $mult);
    return $n > 0 ? $n : PHP_INT_MAX;
}

function upload_limit(): int
{
    return min(6 * 1048576, ini_bytes((string) ini_get('upload_max_filesize')), ini_bytes((string) ini_get('post_max_size')) - 65536);
}

const SAVE_FAILED = 'تعذّر الحفظ: تأكد أن مجلد data قابل للكتابة وأن مساحة الاستضافة غير ممتلئة.';

/* ───────── معالجة الطلبات ───────── */

$self = 'admin.php';
$tab = req_str($_GET, 'tab', 'stats');
if (!array_key_exists($tab, TABS)) {
    $tab = 'stats';
}
$formError = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = req_str($_POST, 'action');

    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // تجاوز حجم الطلب حدود الاستضافة فوصل فارغاً
        if (is_logged_in()) {
            flash('حجم الصورة أكبر من الحد المسموح على الاستضافة.', 'err');
            header('Location: ' . $self . '?tab=photo', true, 303);
            exit;
        }
        $formError = 'الطلب كبير جداً.';
    } elseif (!csrf_ok()) {
        if (is_logged_in()) {
            flash('انتهت صلاحية الصفحة، أعد المحاولة.', 'err');
            header('Location: ' . $self . '?tab=' . $tab, true, 303);
            exit;
        }
        $formError = 'انتهت صلاحية الصفحة، أعد المحاولة.';
    } elseif (auth_state() === 'none') {
        if ($action === 'setup') {
            $p1 = req_str($_POST, 'password');
            $p2 = req_str($_POST, 'password2');
            if (!setup_code_ok(req_str($_POST, 'setup_code'))) {
                usleep(400000);
                $formError = 'رمز الإعداد غير صحيح. انسخه من الملف data/setup-code.php.';
            } elseif (mb_strlen($p1, 'UTF-8') < 8) {
                $formError = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.';
            } elseif ($p1 !== $p2) {
                $formError = 'كلمتا المرور غير متطابقتين.';
            } elseif (!set_password($p1)) {
                $formError = SAVE_FAILED;
            } else {
                remove_setup_code();
                login_now();
                flash('تم إنشاء كلمة المرور. أهلاً بك في لوحة التحكم!');
                header('Location: ' . $self, true, 303);
                exit;
            }
        }
    } elseif (!is_logged_in()) {
        if ($action === 'login' && auth_state() === 'ok') {
            // المحاولة تُحتسب قبل فحص كلمة المرور، فلا يمكن تجاوز الحد بطلبات متزامنة
            $wait = reserve_attempt();
            if ($wait > 0) {
                $formError = 'محاولات كثيرة خاطئة. حاول بعد ' . (int) ceil($wait / 60) . ' دقيقة.';
            } elseif (password_verify(req_str($_POST, 'password'), auth()['hash'])) {
                clear_fails();
                login_now();
                header('Location: ' . $self, true, 303);
                exit;
            } else {
                usleep(400000);
                $formError = 'كلمة المرور غير صحيحة.';
            }
        }
    } else {
        switch ($action) {
            case 'save_content':
                if (save_content_from_post($_POST)) {
                    flash('تم حفظ التعديلات، وهي ظاهرة الآن على الصفحة.');
                } else {
                    flash(SAVE_FAILED, 'err');
                }
                $tab = 'content';
                break;
            case 'upload_avatar':
                $err = handle_avatar_upload();
                flash($err === '' ? 'تم تحديث الصورة.' : $err, $err === '' ? 'ok' : 'err');
                $tab = 'photo';
                break;
            case 'reset_avatar':
                if (set_avatar('')) {
                    flash('تمت إعادة الصورة الرمزية الافتراضية.');
                } else {
                    flash(SAVE_FAILED, 'err');
                }
                $tab = 'photo';
                break;
            case 'change_password':
                $tab = 'settings';
                $cur = req_str($_POST, 'current');
                $p1 = req_str($_POST, 'password');
                $p2 = req_str($_POST, 'password2');
                // أخطاء الكتابة في الكلمة الجديدة لا تُحسب محاولات خاطئة
                if (mb_strlen($p1, 'UTF-8') < 8) {
                    flash('كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل.', 'err');
                } elseif ($p1 !== $p2) {
                    flash('كلمتا المرور الجديدتان غير متطابقتين.', 'err');
                } elseif (($wait = reserve_attempt()) > 0) {
                    flash('محاولات كثيرة خاطئة. حاول بعد ' . (int) ceil($wait / 60) . ' دقيقة.', 'err');
                } elseif (!password_verify($cur, auth()['hash'])) {
                    flash('كلمة المرور الحالية غير صحيحة.', 'err');
                } elseif (!set_password($p1)) {
                    clear_fails();
                    flash(SAVE_FAILED, 'err');
                } else {
                    clear_fails();
                    refresh_login();
                    flash('تم تغيير كلمة المرور. أي جهاز آخر كان داخلاً إلى اللوحة خرج تلقائياً.');
                }
                break;
            case 'reset_stats':
                $tab = 'settings';
                if (req_str($_POST, 'confirm') !== 'yes') {
                    flash('ضع علامة التأكيد أولاً لتصفير الإحصائيات.', 'err');
                } elseif (store_update('stats', function () {
                    return stats_blank();
                })) {
                    flash('تم تصفير الإحصائيات.');
                } else {
                    flash(SAVE_FAILED, 'err');
                }
                break;
            case 'logout':
                logout_now();
                header('Location: ' . $self, true, 303);
                exit;
        }
        header('Location: ' . $self . '?tab=' . $tab, true, 303);
        exit;
    }
}

/* ───────── أدوات العرض ───────── */

function n($v): string
{
    return number_format((int) $v);
}

function days_sum(array $days, int $count, string $key): int
{
    $sum = 0;
    for ($i = 0; $i < $count; $i++) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $sum += (int) ($days[$d][$key] ?? 0);
    }
    return $sum;
}

function source_label(string $k): string
{
    $map = [
        'google' => 'جوجل', 'bing' => 'بينغ', 'facebook' => 'فيسبوك', 'instagram' => 'إنستغرام',
        'tiktok' => 'تيك توك', 'whatsapp' => 'واتساب', 'telegram' => 'تيليجرام', 'youtube' => 'يوتيوب',
        'x' => 'X (تويتر)', 'direct' => 'مباشر (رابط محفوظ أو من تطبيق)', 'other' => 'مواقع أخرى',
    ];
    return $map[$k] ?? $k;
}

function bars(array $data, callable $label): string
{
    arsort($data);
    $total = array_sum($data);
    if ($total <= 0) {
        return '<p class="empty">لا توجد بيانات بعد.</p>';
    }
    $html = '<ul class="hbars">';
    foreach ($data as $k => $v) {
        $pct = round($v * 100 / $total, 1);
        $html .= '<li><div class="hbars__row"><span>' . e($label((string) $k)) . '</span><b>' . n($v) . ' <small>(' . $pct . '%)</small></b></div>'
            . '<div class="hbars__track"><i style="width:' . max(1.5, $pct) . '%"></i></div></li>';
    }
    return $html . '</ul>';
}

function render_field(string $name, string $label, string $type, string $value, int $max = 0): string
{
    $id = 'f_' . preg_replace('/[^a-z0-9]+/i', '_', $name);
    $counter = $max ? ' data-max="' . $max . '"' : '';
    $html = '<div class="field"><label for="' . $id . '">' . e($label) . '</label>';
    switch ($type) {
        case 'textarea':
            $rows = max(3, min(8, (int) ceil(mb_strlen($value, 'UTF-8') / 70) + substr_count($value, "\n") + 1));
            $html .= '<textarea id="' . $id . '" name="' . e($name) . '" rows="' . $rows . '"' . $counter . '>' . e($value) . '</textarea>';
            break;
        case 'code':
            $html .= '<textarea id="' . $id . '" class="mono" dir="ltr" spellcheck="false" name="' . e($name) . '" rows="8">' . e($value) . '</textarea>';
            break;
        case 'icon':
            $html .= '<select id="' . $id . '" name="' . e($name) . '">';
            foreach (selectable_icons() as $k => $l) {
                $html .= '<option value="' . e($k) . '"' . ($k === $value ? ' selected' : '') . '>' . e($l) . '</option>';
            }
            $html .= '</select>';
            break;
        case 'url':
            // نوع text وليس url: حتى لا يمنع المتصفح الحفظ لو كُتب الرابط بدون https:// (نكمله نحن)
            $html .= '<input id="' . $id . '" type="text" dir="ltr" inputmode="url" autocapitalize="off" spellcheck="false" placeholder="https://" name="' . e($name) . '" value="' . e($value) . '">';
            break;
        case 'tel':
            $html .= '<input id="' . $id . '" type="text" dir="ltr" inputmode="tel" name="' . e($name) . '" value="' . e($value) . '">';
            break;
        default:
            $html .= '<input id="' . $id . '" type="text" name="' . e($name) . '" value="' . e($value) . '"' . $counter . '>';
    }
    if ($max) {
        $html .= '<small class="count" data-for="' . $id . '"></small>';
    }
    return $html . '</div>';
}

$flash = is_logged_in() ? take_flash() : null;
$c = content();
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>لوحة التحكم · <?= e($c['hero']['name']) ?></title>
<link rel="icon" href="<?= e(asset('assets/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap">
<link rel="stylesheet" href="<?= e(asset('assets/admin.css')) ?>">
</head>
<body>

<?php if (!is_logged_in()): $state = auth_state(); ?>
<main class="auth">
  <form class="auth__card" method="post" action="<?= e($self) ?>" autocomplete="on">
    <img src="<?= e(asset(avatar_path())) ?>" alt="" width="84" height="84" class="auth__avatar">
    <?php if ($state === 'broken'): ?>
      <h1>اللوحة مقفلة للحماية</h1>
      <p class="alert alert--err">ملف كلمة المرور تالف (غالباً بسبب امتلاء مساحة الاستضافة). من مدير الملفات احذف الملف <b dir="ltr">data/auth.json.php</b> ثم افتح هذه الصفحة من جديد لاختيار كلمة مرور.</p>
    <?php elseif ($state === 'none'): $code = setup_code(); ?>
      <h1>إعداد لوحة التحكم</h1>
      <?php if ($code === ''): ?>
        <p class="alert alert--err">تعذّر إنشاء رمز الإعداد. اجعل صلاحية مجلد <b dir="ltr">data</b> قابلة للكتابة (755 أو 775) ثم حدّث الصفحة.</p>
      <?php else: ?>
        <p class="muted">للحماية: افتح مدير الملفات في الاستضافة، ثم الملف <b dir="ltr">data/setup-code.php</b>، وانسخ الرمز المكتوب بعد <b dir="ltr">SETUP-CODE:</b></p>
        <input type="hidden" name="action" value="setup">
        <label for="sc">رمز الإعداد</label>
        <input id="sc" type="text" name="setup_code" dir="ltr" required maxlength="8" autocomplete="off" autocapitalize="characters" spellcheck="false" class="code-in">
        <label for="p1">كلمة المرور الجديدة (8 أحرف على الأقل)</label>
        <input id="p1" type="password" name="password" minlength="8" required autocomplete="new-password">
        <label for="p2">تأكيد كلمة المرور</label>
        <input id="p2" type="password" name="password2" minlength="8" required autocomplete="new-password">
      <?php endif; ?>
    <?php else: ?>
      <h1>لوحة التحكم</h1>
      <p class="muted">أدخل كلمة المرور للمتابعة.</p>
      <input type="hidden" name="action" value="login">
      <label for="p1">كلمة المرور</label>
      <input id="p1" type="password" name="password" required autofocus autocomplete="current-password">
    <?php endif; ?>
    <?= csrf_field() ?>
    <?php if ($formError): ?><p class="alert alert--err"><?= e($formError) ?></p><?php endif; ?>
    <?php if ($state === 'ok' || ($state === 'none' && $code !== '')): ?>
    <button class="btn btn--primary" type="submit"><?= icon('lock') ?><?= $state === 'ok' ? 'دخول' : 'حفظ والدخول' ?></button>
    <?php endif; ?>
    <a class="back" href="./"><?= icon('house') ?>العودة إلى الصفحة</a>
  </form>
</main>

<?php else:
    $s = stats();
    $days = $s['days'];
    $today = date('Y-m-d');
?>
<header class="top">
  <div class="top__in">
    <a class="top__brand" href="<?= e($self) ?>">
      <img src="<?= e(asset(avatar_path())) ?>" alt="" width="34" height="34">
      <span>لوحة التحكم</span>
    </a>
    <div class="top__actions">
      <a class="btn btn--ghost btn--sm" href="./" target="_blank" rel="noopener"><?= icon('eye') ?><span>عرض الصفحة</span></a>
      <form method="post" action="<?= e($self) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="logout">
        <button class="btn btn--ghost btn--sm" type="submit" aria-label="خروج"><?= icon('log-out') ?><span>خروج</span></button>
      </form>
    </div>
  </div>
  <nav class="tabs" aria-label="أقسام اللوحة">
    <?php
    $tabIcons = ['stats' => 'chart-column', 'content' => 'pencil', 'photo' => 'image', 'settings' => 'settings'];
    foreach (TABS as $k => $label): ?>
      <a href="<?= e($self . '?tab=' . $k) ?>" class="<?= $tab === $k ? 'is-on' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= icon($tabIcons[$k]) ?><span><?= e($label) ?></span></a>
    <?php endforeach; ?>
  </nav>
</header>

<main class="main">
  <?php if ($flash): ?>
    <p class="alert alert--<?= $flash['t'] === 'err' ? 'err' : 'ok' ?>" role="status"><?= icon($flash['t'] === 'err' ? 'zap' : 'check') ?><?= e($flash['m']) ?></p>
  <?php endif; ?>

  <?php if ($tab === 'stats'):
      $todayV = (int) ($days[$today]['v'] ?? 0);
      $todayU = (int) ($days[$today]['u'] ?? 0);
      $yest   = date('Y-m-d', strtotime('-1 day'));
      $chart  = [];
      $max    = 1;
      for ($i = 29; $i >= 0; $i--) {
          $d = date('Y-m-d', strtotime("-$i days"));
          $chart[$d] = ['v' => (int) ($days[$d]['v'] ?? 0), 'u' => (int) ($days[$d]['u'] ?? 0)];
          $max = max($max, $chart[$d]['v']);
      }
      $clickLabels = click_keys();
      $clicks = [];
      foreach ($s['clicks'] as $k => $v) {
          $clicks[$k] = (int) $v;
      }
  ?>
  <section class="kpis">
    <article class="kpi kpi--hl">
      <span class="kpi__icon"><?= icon('users') ?></span>
      <p>زوار اليوم</p><b><?= n($todayU) ?></b>
      <small>أمس: <?= n($days[$yest]['u'] ?? 0) ?></small>
    </article>
    <article class="kpi">
      <span class="kpi__icon"><?= icon('eye') ?></span>
      <p>مشاهدات اليوم</p><b><?= n($todayV) ?></b>
      <small>أمس: <?= n($days[$yest]['v'] ?? 0) ?></small>
    </article>
    <article class="kpi">
      <span class="kpi__icon"><?= icon('calendar') ?></span>
      <p>زوار آخر 7 أيام</p><b><?= n(days_sum($days, 7, 'u')) ?></b>
      <small>مشاهدات: <?= n(days_sum($days, 7, 'v')) ?></small>
    </article>
    <article class="kpi">
      <span class="kpi__icon"><?= icon('trending-up') ?></span>
      <p>زوار آخر 30 يوماً</p><b><?= n(days_sum($days, 30, 'u')) ?></b>
      <small>مشاهدات: <?= n(days_sum($days, 30, 'v')) ?></small>
    </article>
    <article class="kpi">
      <span class="kpi__icon"><?= icon('globe') ?></span>
      <p>إجمالي الزوار</p><b><?= n($s['total_uniques']) ?></b>
      <small>منذ <bdi dir="ltr"><?= e($s['since']) ?></bdi></small>
    </article>
    <article class="kpi">
      <span class="kpi__icon"><?= icon('mouse-pointer-click') ?></span>
      <p>إجمالي المشاهدات</p><b><?= n($s['total_views']) ?></b>
      <small>نقرات الروابط: <?= n(array_sum($clicks)) ?></small>
    </article>
  </section>

  <section class="panel">
    <header class="panel__head">
      <h2><?= icon('chart-column') ?>آخر 30 يوماً</h2>
      <p class="legend"><span class="lg lg--u"></span>زوار <span class="lg lg--v"></span>مشاهدات</p>
    </header>
    <div class="chart" role="img" aria-label="رسم بياني للزيارات في آخر 30 يوماً">
      <?php foreach ($chart as $d => $row): ?>
        <div class="chart__col<?= $d === $today ? ' is-today' : '' ?>" title="<?= e($d) ?> · زوار: <?= $row['u'] ?> · مشاهدات: <?= $row['v'] ?>">
          <div class="chart__bar">
            <i class="v" style="height:<?= round($row['v'] * 100 / $max, 2) ?>%"></i>
            <i class="u" style="height:<?= round($row['u'] * 100 / $max, 2) ?>%"></i>
          </div>
          <span><?= (int) substr($d, 8, 2) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="cols">
    <section class="panel">
      <header class="panel__head"><h2><?= icon('globe') ?>من أين يأتي الزوار</h2></header>
      <?= bars(array_map('intval', $s['sources']), 'source_label') ?>
    </section>
    <section class="panel">
      <header class="panel__head"><h2><?= icon('mouse-pointer-click') ?>النقرات على الروابط</h2></header>
      <?= bars($clicks, function ($k) use ($clickLabels) { return $clickLabels[$k] ?? (preg_match('/^(p|project)-/', $k) ? 'مشروع محذوف' : $k); }) ?>
    </section>
    <section class="panel">
      <header class="panel__head"><h2><?= icon('smartphone') ?>الأجهزة</h2></header>
      <?= bars(array_map('intval', $s['devices']), function ($k) { return ['mobile' => 'جوال', 'desktop' => 'كمبيوتر', 'tablet' => 'تابلت'][$k] ?? $k; }) ?>
    </section>
    <section class="panel">
      <header class="panel__head"><h2><?= icon('external-link') ?>أكثر المواقع إحالةً</h2></header>
      <?php $refs = array_map('intval', $s['referrers']); arsort($refs); $refs = array_slice($refs, 0, 10, true); ?>
      <?php if ($refs): ?>
        <table class="tbl"><tbody>
          <?php foreach ($refs as $host => $v): ?><tr><td dir="ltr"><?= e($host) ?></td><td><?= n($v) ?></td></tr><?php endforeach; ?>
        </tbody></table>
      <?php else: ?><p class="empty">لا توجد بيانات بعد.</p><?php endif; ?>
    </section>
  </div>
  <p class="note"><?= icon('shield-check') ?>زياراتك أنت من هذا الجهاز لا تُحتسب، وكذلك زيارات محركات البحث والروبوتات. "الزائر" يُحسب مرة واحدة في اليوم.</p>

  <?php elseif ($tab === 'content'): ?>
  <form method="post" action="<?= e($self) ?>" class="editor">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_content">
    <p class="note"><?= icon('pencil') ?>عدّل أي نص ثم اضغط "حفظ". التعديل يظهر على الصفحة فوراً. اترك عنصر القائمة فارغاً لحذفه.</p>
    <?php $first = true; foreach (content_schema() as $sec => $def): ?>
      <details class="panel group"<?= $first ? ' open' : '' ?>>
        <summary><h2><?= e($def['title']) ?></h2><?= icon('arrow-up-left', 'i chev') ?></summary>
        <div class="group__body">
          <?php foreach ($def['fields'] as $key => $f): ?>
            <?= render_field("{$sec}[{$key}]", $f[0], $f[1], (string) $c[$sec][$key], (int) ($f[2] ?? 0)) ?>
          <?php endforeach; ?>
          <?php if (isset($def['list'])):
              $items = $c[$sec]['items'];
              $items[] = array_fill_keys(array_keys($def['list']['fields']), '');
              foreach ($items as $i => $item):
                  $isNew = $i === count($items) - 1; ?>
              <fieldset class="item<?= $isNew ? ' item--new' : '' ?>">
                <legend><?= $isNew ? 'إضافة ' . e($def['list']['label']) . ' جديد' : e($def['list']['label']) . ' ' . ($i + 1) ?></legend>
                <?php foreach ($def['list']['fields'] as $key => $f): ?>
                  <?php $val = (string) ($item[$key] ?? ''); if ($f[1] === 'icon' && $val === '') { $val = 'code-xml'; } ?>
                  <?= render_field("{$sec}[items][{$i}][{$key}]", $f[0], $f[1], $val) ?>
                <?php endforeach; ?>
              </fieldset>
          <?php endforeach; endif; ?>
        </div>
      </details>
    <?php $first = false; endforeach; ?>
    <div class="savebar">
      <button class="btn btn--primary" type="submit"><?= icon('save') ?>حفظ التعديلات</button>
      <a class="btn btn--ghost" href="./" target="_blank" rel="noopener"><?= icon('eye') ?>معاينة</a>
    </div>
  </form>

  <?php elseif ($tab === 'photo'): ?>
  <section class="panel photo">
    <img src="<?= e(asset(avatar_path())) ?>" alt="الصورة الحالية" width="160" height="160">
    <div>
      <h2>الصورة الشخصية</h2>
      <?php $limitMb = rtrim(rtrim(number_format(upload_limit() / 1048576, 1), '0'), '.'); ?>
      <p class="muted">تظهر في الواجهة والقائمة العلوية ونتائج جوجل. الأفضل صورة مربعة واضحة للوجه (JPG أو PNG أو WEBP). الصور الكبيرة تُصغَّر تلقائياً قبل الرفع، وحد الاستضافة <?= e($limitMb) ?> ميغابايت.</p>
      <form method="post" action="<?= e($self) ?>" enctype="multipart/form-data" class="upload" data-limit="<?= (int) upload_limit() ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="upload_avatar">
        <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required>
        <button class="btn btn--primary" type="submit"><?= icon('upload') ?>رفع الصورة</button>
      </form>
      <?php if ($c['avatar'] !== ''): ?>
      <form method="post" action="<?= e($self) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="reset_avatar">
        <button class="btn btn--ghost" type="submit"><?= icon('rotate-ccw') ?>إرجاع الصورة الرمزية الافتراضية</button>
      </form>
      <?php endif; ?>
    </div>
  </section>

  <?php else: ?>
  <div class="cols">
    <section class="panel">
      <header class="panel__head"><h2><?= icon('lock') ?>تغيير كلمة المرور</h2></header>
      <form method="post" action="<?= e($self) ?>" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="change_password">
        <div class="field"><label for="cp0">كلمة المرور الحالية</label><input id="cp0" type="password" name="current" required autocomplete="current-password"></div>
        <div class="field"><label for="cp1">كلمة المرور الجديدة</label><input id="cp1" type="password" name="password" minlength="8" required autocomplete="new-password"></div>
        <div class="field"><label for="cp2">تأكيد الجديدة</label><input id="cp2" type="password" name="password2" minlength="8" required autocomplete="new-password"></div>
        <button class="btn btn--primary" type="submit"><?= icon('save') ?>تغيير</button>
      </form>
    </section>
    <section class="panel">
      <header class="panel__head"><h2><?= icon('trash-2') ?>تصفير الإحصائيات</h2></header>
      <p class="muted">يحذف كل أرقام الزيارات والنقرات ويبدأ العدّ من جديد. لا يمكن التراجع.</p>
      <form method="post" action="<?= e($self) ?>" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="reset_stats">
        <label class="check"><input type="checkbox" name="confirm" value="yes" required> نعم، أريد تصفير الإحصائيات</label>
        <button class="btn btn--danger" type="submit"><?= icon('trash-2') ?>تصفير</button>
      </form>
    </section>
  </div>
  <section class="panel">
    <header class="panel__head"><h2><?= icon('globe') ?>روابط مهمة لجوجل</h2></header>
    <ul class="links">
      <li><span>رابط الصفحة</span><a dir="ltr" href="<?= e(base_url()) ?>" target="_blank" rel="noopener"><?= e(base_url()) ?></a></li>
      <li><span>خريطة الموقع (Sitemap)</span><a dir="ltr" href="<?= e(base_url() . 'sitemap.php') ?>" target="_blank" rel="noopener"><?= e(base_url() . 'sitemap.php') ?></a></li>
      <li><span>Google Search Console</span><a dir="ltr" href="https://search.google.com/search-console" target="_blank" rel="noopener">search.google.com/search-console</a></li>
    </ul>
  </section>
  <?php endif; ?>
</main>
<footer class="foot">م. صهيب قديح · لوحة التحكم</footer>
<script src="<?= e(asset('assets/admin.js')) ?>" defer></script>
<?php endif; ?>
</body>
</html>
