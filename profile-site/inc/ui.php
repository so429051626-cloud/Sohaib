<?php
// نصوص الواجهة الثابتة (أزرار وحقول النموذج...) بالعربية والإنجليزية، والشعار، والأعلام.

declare(strict_types=1);

const UI = [
    'skip'          => ['تخطَّ إلى المحتوى', 'Skip to content'],
    'sections'      => ['الأقسام', 'Sections'],
    'menu'          => ['القائمة', 'Menu'],
    'close'         => ['إغلاق', 'Close'],
    'theme'         => ['الوضع الليلي / النهاري', 'Dark / light mode'],
    'lang_name'     => ['English', 'العربية'],
    'lang_code'     => ['EN', 'AR'],
    'socials'       => ['حساباتي', 'My accounts'],
    'whatsapp'      => ['واتساب', 'WhatsApp'],
    'facebook'      => ['فيسبوك', 'Facebook'],
    'tiktok'        => ['تيك توك', 'TikTok'],
    'instagram'     => ['إنستغرام', 'Instagram'],
    'github'        => ['GitHub', 'GitHub'],
    'linkedin'      => ['لينكدإن', 'LinkedIn'],
    'email'         => ['البريد الإلكتروني', 'Email'],
    'phone'         => ['اتصال مباشر', 'Phone'],
    'location'      => ['الموقع', 'Location'],
    'chat_label'    => ['تواصل سريع', 'Quick chat'],
    'chat_hello'    => ['أهلاً بك 👋', 'Hi there 👋'],
    'chat_text'     => ['كيف أقدر أساعدك؟ اكتب لي على واتساب وأرد عليك بأسرع وقت.', 'How can I help? Message me on WhatsApp and I will reply as soon as I can.'],
    'chat_btn'      => ['ابدأ المحادثة', 'Start chatting'],
    'online'        => ['متصل الآن', 'Online'],
    'to_top'        => ['العودة للأعلى', 'Back to top'],
    'top'           => ['للأعلى', 'Top'],
    'follow'        => ['تابعني', 'Follow me'],
    'browse'        => ['تصفح', 'Browse'],
    'get_in_touch'  => ['تواصل معي', 'Get in touch'],
    'credit'        => ['صُمم وطُوّر بواسطة', 'Designed & developed by'],
    'less'          => ['عرض أقل', 'Show less'],
    'prev'          => ['السابق', 'Previous'],
    'next'          => ['التالي', 'Next'],
    'rating'        => ['التقييم', 'Rating'],
    'client'        => ['عميل', 'Client'],
    'photo_alt'     => ['صهيب شوقي', 'Sohaib Shawqi'],
    'form_name'     => ['الاسم', 'Name'],
    'form_name_ph'  => ['اسمك الكامل', 'Your full name'],
    'form_wa'       => ['رقم واتساب', 'WhatsApp number'],
    'form_wa_ph'    => ['059xxxxxxx', '+970 59 xxx xxxx'],
    'form_email'    => ['البريد الإلكتروني', 'Email'],
    'form_email_ph' => ['you@example.com', 'you@example.com'],
    'optional'      => ['(اختياري)', '(optional)'],
    'form_company'  => ['اسم المشروع أو الشركة', 'Brand / company name'],
    'form_company_ph' => ['اسم نشاطك التجاري', 'Your brand or company name'],
    'form_service'  => ['الخدمة المطلوبة', 'Service needed'],
    'form_other'    => ['شيء آخر', 'Something else'],
    'form_budget'   => ['الميزانية التقريبية', 'Approximate budget'],
    'form_time'     => ['المدة المتوقعة', 'Expected timeline'],
    'form_pick'     => ['اختر...', 'Select...'],
    'budget_1'      => ['أقل من 300$', 'Under $300'],
    'budget_2'      => ['300$ – 1000$', '$300 – $1,000'],
    'budget_3'      => ['1000$ – 3000$', '$1,000 – $3,000'],
    'budget_4'      => ['أكثر من 3000$', 'Over $3,000'],
    'budget_5'      => ['لم أحدد بعد', 'Not sure yet'],
    'time_1'        => ['مستعجل (أقل من أسبوع)', 'Urgent (under a week)'],
    'time_2'        => ['خلال أسبوعين', 'Within two weeks'],
    'time_3'        => ['خلال شهر', 'Within a month'],
    'time_4'        => ['مرن', 'Flexible'],
    'form_details'  => ['تفاصيل المشروع', 'Project details'],
    'form_details_ph' => ['اكتب لي فكرة مشروعك باختصار...', 'Tell me a bit about your project...'],
    'form_send'     => ['إرسال عبر واتساب', 'Send via WhatsApp'],
    'form_note'     => ['تُفتح رسالتك جاهزة في واتساب، وتصلني نسخة منها هنا أيضاً.', 'Your message opens ready in WhatsApp, and a copy reaches me here too.'],
    'form_ok'       => ['تم تجهيز رسالتك ✓ أكمل الإرسال من واتساب.', 'Your message is ready ✓ finish sending it in WhatsApp.'],
    'form_saved'    => ['وصلتني رسالتك ✓ سأتواصل معك قريباً.', 'Got your message ✓ I will contact you soon.'],
    'form_err'      => ['من فضلك اكتب اسمك وتفاصيل مشروعك.', 'Please add your name and project details.'],
    'form_limit'    => ['أرسلت رسائل كثيرة، تواصل معي مباشرة على واتساب.', 'Too many messages; please contact me on WhatsApp directly.'],
    'wa_head'       => ['طلب مشروع جديد من الموقع', 'New project request from the website'],
];

function ui(string $key): string
{
    return UI[$key][is_en() ? 1 : 0] ?? $key;
}

// شعار حرف S بلونين (الجزء العلوي فاتح والسفلي غامق)
function logo_mark(string $class = 'mark'): string
{
    return '<svg class="' . e($class) . '" viewBox="-8 -2 82 84" aria-hidden="true" focusable="false">'
        . '<g transform="skewX(-12) translate(10 0)">'
        . '<path class="mark__a" pathLength="1" d="M14 0H62V15H18V31H50L62 43V68L50 80H0V65H44V49H12L0 37V12Z"/>'
        . '<path class="mark__b" d="M50 31 62 43V68L50 80H0V65H44V49H30Z"/>'
        . '</g></svg>';
}

// علم صغير بجانب زر اللغة: فلسطين للعربية، وبريطانيا للإنجليزية
function flag_svg(string $lang): string
{
    if ($lang === 'ar') {
        return '<svg class="flag" viewBox="0 0 30 20" aria-hidden="true" focusable="false">'
            . '<rect width="30" height="20" fill="#007a3d"/><rect width="30" height="13.34" fill="#fff"/>'
            . '<rect width="30" height="6.67" fill="#000"/><path d="M0 0l12 10L0 20z" fill="#ce1126"/></svg>';
    }
    return '<svg class="flag" viewBox="0 0 60 40" aria-hidden="true" focusable="false">'
        . '<clipPath id="uk-c"><rect width="60" height="40"/></clipPath><g clip-path="url(#uk-c)">'
        . '<rect width="60" height="40" fill="#012169"/>'
        . '<path d="M0 0l60 40M60 0L0 40" stroke="#fff" stroke-width="8"/>'
        . '<path d="M0 0l60 40M60 0L0 40" stroke="#c8102e" stroke-width="3"/>'
        . '<path d="M30 0v40M0 20h60" stroke="#fff" stroke-width="12"/>'
        . '<path d="M30 0v40M0 20h60" stroke="#c8102e" stroke-width="7"/></g></svg>';
}

// شريط الكلمات المتحرك (المجموعة مكررة مرتين حتى تدور بلا انقطاع)
function ribbon_html(array $words, string $mod): string
{
    if (!$words) {
        return '';
    }
    $group = '';
    $reps = max(2, (int) ceil(14 / count($words)));
    for ($r = 0; $r < $reps; $r++) {
        foreach ($words as $i => $w) {
            $group .= '<span class="ribbon__item">' . e($w) . '</span>' . icon(($i + $r) % 2 ? 'asterisk' : 'sparkle', 'ribbon__star');
        }
    }
    return '<div class="ribbon ribbon--' . e($mod) . '" aria-hidden="true"><div class="ribbon__track" dir="ltr">'
        . '<div class="ribbon__group">' . $group . '</div><div class="ribbon__group">' . $group . '</div>'
        . '</div></div>';
}
