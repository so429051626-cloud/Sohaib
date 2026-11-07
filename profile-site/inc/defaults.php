<?php
// المحتوى الافتراضي للصفحة. كل نص هنا قابل للتعديل من لوحة التحكم (admin.php)،
// والتعديلات تُحفظ في data/content.json.php ولا تمسّ هذا الملف.

function default_content(): array
{
    return [
        'seo' => [
            'title'       => 'صهيب قديح | Sohaib Qudeih – مطوّر أنظمة وتطبيقات ومؤسس MADIX',
            'description' => 'المطوّر صهيب قديح (Sohaib Qudeih)، المؤسس والمطوّر لأنظمة MADIX. مطوّر أنظمة إدارية وتطبيقات ومتاجر إلكترونية وأنظمة كاشير متعددة الفروع ومنصات ذكاء اصطناعي.',
            'keywords'    => 'صهيب قديح, المطور صهيب قديح, م. صهيب قديح, المهندس صهيب قديح, Sohaib Qudeih, Sohaib Qudaih, Suhaib Qudeih, MADIX, نظام كاشير, نظام كاشير متعدد الفروع, متجر إلكتروني, مطور تطبيقات, منصة ذكاء اصطناعي, Fynex, نظام SaaS',
            'alt_names'   => "Sohaib Qudeih\nSohaib Qudaih\nSuhaib Qudeih\nSuhaib Qudaih\nSohaib Qdeih\nSoahib Qudeih\nالمطور صهيب قديح\nالمهندس صهيب قديح\nم. صهيب قديح",
            'site_url'    => '',
            'google_verification' => '',
        ],
        'nav' => [
            'about'    => 'نبذة',
            'services' => 'خدماتي',
            'projects' => 'أعمالي',
            'contact'  => 'تواصل',
            'cta'      => 'راسلني',
        ],
        'hero' => [
            'hello'     => 'مرحباً، أنا',
            'name'      => 'صهيب قديح',
            'name_en'   => 'Sohaib Qudeih',
            'badge'     => 'المؤسس والمطوّر لأنظمة MADIX',
            'headline'  => 'مطوّر أنظمة وتطبيقات ومتاجر إلكترونية',
            'subline'   => 'وأنظمة الكاشير المتعددة ومنصات الذكاء الاصطناعي. أحوّل الأفكار إلى منتجات رقمية احترافية تعمل بكفاءة وتنمو مع عملك.',
            'status'    => 'متاح لمشاريع جديدة',
            'btn_primary'   => 'تواصل معي عبر واتساب',
            'btn_secondary' => 'استعرض أعمالي',
        ],
        'code' => [
            'filename' => 'sohaib.js',
            'lines'    => "const developer = {\n  name: \"Sohaib Qudeih\",\n  role: \"Founder & Developer @ MADIX\",\n  builds: [\"POS\", \"E-Stores\", \"SaaS\", \"AI\"],\n};\ndeveloper.launch(); // 🚀 Ready to build your idea",
        ],
        'about' => [
            'kicker' => 'نبذة عني',
            'title'  => 'من هو المطوّر صهيب قديح؟',
            'lead'   => 'صهيب قديح | مؤسس ومطوّر أنظمة MADIX',
            'text'   => 'مؤسس ومطوّر تقني أمتلك رؤية لبناء حلول برمجية مبتكرة وأنظمة رقمية متقدمة. أركّز على تصميم وتطوير الأنظمة الإدارية والتجارية، وتحويل الأفكار إلى منتجات تقنية احترافية تجمع بين الأداء والكفاءة وسهولة الاستخدام، برؤية تهدف إلى دعم التحوّل الرقمي وصناعة حلول تواكب تطلعات المستقبل.',
            'chips'  => "مؤسس MADIX\nأنظمة إدارية وتجارية\nأنظمة الكاشير المتعددة\nحلول الذكاء الاصطناعي\nالتحوّل الرقمي",
        ],
        'services' => [
            'kicker' => 'ماذا أقدّم',
            'title'  => 'حلول برمجية متكاملة لعملك',
            'items'  => [
                ['icon' => 'scan-barcode', 'title' => 'أنظمة الكاشير المتعددة', 'text' => 'أنظمة نقاط بيع (POS) سريعة وموثوقة تدعم تعدد الفروع والمستخدمين، مع تقارير مبيعات ومخزون لحظية.'],
                ['icon' => 'layout-dashboard', 'title' => 'الأنظمة الإدارية والتجارية', 'text' => 'أنظمة مخصّصة لإدارة العملاء والموظفين والحسابات والمخازن، مصمّمة على مقاس عملك.'],
                ['icon' => 'store', 'title' => 'المتاجر الإلكترونية', 'text' => 'متاجر احترافية بتجربة شراء سلسة، وإدارة سهلة للمنتجات والطلبات وطرق الدفع.'],
                ['icon' => 'monitor-smartphone', 'title' => 'تطبيقات الجوال والويب', 'text' => 'تطبيقات أندرويد وويب حديثة وسريعة، بواجهات أنيقة وأداء عالٍ على كل الأجهزة.'],
                ['icon' => 'brain-circuit', 'title' => 'منصات الذكاء الاصطناعي', 'text' => 'حلول ذكية تعتمد على الذكاء الاصطناعي لأتمتة المهام وتحليل البيانات وتقديم تجارب مبتكرة.'],
                ['icon' => 'cloud', 'title' => 'أنظمة SaaS لإدارة الأعمال', 'text' => 'منصات سحابية بنظام الاشتراك تتيح للشركات إدارة أعمالها من أي مكان وفي أي وقت.'],
            ],
        ],
        'projects' => [
            'kicker' => 'أعمالي',
            'title'  => 'منصات من تطويري',
            'cta'    => 'زيارة الموقع',
            'items'  => [
                ['icon' => 'scan-barcode', 'name' => 'MADIX', 'tag' => 'نظام الكاشير', 'text' => 'نظام نقاط بيع متكامل ومتعدد الفروع لإدارة المبيعات والمخزون والفواتير بسرعة ودقة.', 'url' => 'https://madix.kozow.com'],
                ['icon' => 'sparkles', 'name' => 'Fynex', 'tag' => 'منصة الذكاء الاصطناعي', 'text' => 'منصة ذكاء اصطناعي تقدّم أدوات ذكية تساعدك على الإنجاز والإبداع بشكل أسرع.', 'url' => 'https://fynex.xubi.org/'],
                ['icon' => 'cloud', 'name' => 'نظام إدارة الأعمال', 'tag' => 'SaaS', 'text' => 'نظام سحابي لإدارة الأعمال يجمع العمليات والبيانات والتقارير في لوحة تحكم واحدة.', 'url' => 'https://sohaib.ct.ws'],
            ],
        ],
        'contact' => [
            'kicker'   => 'تواصل',
            'title'    => 'لنحوّل فكرتك إلى نظام حقيقي',
            'text'     => 'سواء كنت تحتاج نظام كاشير، متجراً إلكترونياً، تطبيقاً أو منصة ذكاء اصطناعي، راسلني وابدأ مشروعك اليوم.',
            'whatsapp' => '970595679770',
            'whatsapp_text'  => 'مرحباً م. صهيب، أريد الاستفسار عن مشروع',
            'whatsapp_label' => 'واتساب',
            'facebook'       => 'https://www.facebook.com/share/1KWUyXA1kr/',
            'facebook_label' => 'فيسبوك',
            'tiktok'         => 'https://www.tiktok.com/@sohaib_2026',
            'tiktok_label'   => 'تيك توك',
        ],
        'footer' => [
            'name'    => 'م. صهيب قديح',
            'rights'  => 'جميع الحقوق محفوظة',
            'en_line' => 'Sohaib Qudeih · Founder & Developer of MADIX Systems · POS, E-commerce, SaaS & AI Platforms',
        ],
        'avatar' => '',
    ];
}

// الأيقونات المتاحة للاختيار في لوحة التحكم لبطاقات الخدمات والمشاريع
function selectable_icons(): array
{
    return [
        'scan-barcode' => 'كاشير / باركود',
        'layout-dashboard' => 'لوحة تحكم',
        'store' => 'متجر',
        'monitor-smartphone' => 'تطبيقات',
        'smartphone' => 'جوال',
        'brain-circuit' => 'ذكاء اصطناعي',
        'sparkles' => 'لمعة',
        'cloud' => 'سحابة',
        'globe' => 'موقع',
        'code-xml' => 'برمجة',
        'layers' => 'طبقات',
        'zap' => 'سرعة',
        'shield-check' => 'حماية',
        'trending-up' => 'نمو',
    ];
}
