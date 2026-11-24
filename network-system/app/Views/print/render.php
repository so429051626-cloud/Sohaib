<?php
/** @var App\Core\View $v */
$v->title = 'طباعة ' . num(count($cards)) . ' كرت';
$config = [
    'template' => [
        'paper_width' => (float) $template['paper_width'], 'paper_height' => (float) $template['paper_height'],
        'cols' => (int) $template['cols'], 'rows' => (int) $template['rows'],
        'margin_top' => (float) $template['margin_top'], 'margin_right' => (float) $template['margin_right'],
        'margin_bottom' => (float) $template['margin_bottom'], 'margin_left' => (float) $template['margin_left'],
        'gap_x' => (float) $template['gap_x'], 'gap_y' => (float) $template['gap_y'],
    ],
    'design' => $design,
    'cards' => $cards,
    'ids' => $ids,
    'mark' => $markPrinted,
    'markUrl' => url('/print/mark'),
    'base' => root_path(),
    'logo' => $logo,
];
?>
<div class="print-pages" id="print-pages"></div>
<script type="application/json" id="print-config"><?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
