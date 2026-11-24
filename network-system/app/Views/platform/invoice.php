<?php
/**
 * فاتورة دفعة اشتراك (A4، تُحفظ PDF من نافذة الطباعة).
 * @var App\Core\View $v @var array $p @var string $back
 */
use App\Core\Brand;
use App\Core\Platform;
use App\Platform\Billing;

$v->title = 'فاتورة ' . $p['invoice_no'];
$amount = number_format((float) $p['amount'], 2, '.', ',');
$note = trim(Platform::setting('billing.invoice_note'));
$wa = trim(Platform::setting('platform.whatsapp'));
?>
<div class="doc-toolbar no-print">
  <button type="button" class="btn btn-primary" data-doc-print><?= icon('printer') ?>طباعة أو حفظ PDF</button>
  <a class="btn" href="<?= e($back) ?>"><?= icon('chevron-right') ?>رجوع</a>
</div>
<div class="doc-stage">
  <article class="doc-sheet inv-sheet" data-orient="portrait">
    <header class="doc-head">
      <div class="doc-brand">
        <?= Brand::mark(48, 'doc-logo') ?>
        <div>
          <div class="doc-network"><?= e(Platform::setting('platform.name')) ?></div>
          <div class="doc-system"><?= e(Brand::TAGLINE) ?></div>
        </div>
      </div>
      <div class="doc-titlebox">
        <h1>فاتورة</h1>
        <div class="doc-meta"><span>الرقم</span><b><?= ltr(e($p['invoice_no'])) ?></b></div>
        <div class="doc-meta"><span>التاريخ</span><b><?= ltr(e(substr((string) $p['created_at'], 0, 10))) ?></b></div>
      </div>
    </header>

    <section class="inv-parties">
      <div class="inv-party">
        <div class="inv-label">فاتورة إلى</div>
        <div class="inv-name"><?= e($p['tenant_name']) ?></div>
        <?php if (!empty($p['owner_name'])): ?><div><?= e($p['owner_name']) ?></div><?php endif; ?>
        <?php if (!empty($p['phone'])): ?><div><?= ltr(e($p['phone'])) ?></div><?php endif; ?>
        <?php if (!empty($p['slug'])): ?><div class="inv-muted"><?= ltr('/' . e($p['slug'])) ?></div><?php endif; ?>
      </div>
      <div class="inv-party">
        <div class="inv-label">الحالة</div>
        <div class="inv-paid"><?= icon('circle-check') ?>مدفوعة</div>
        <?php if (!empty($p['method'])): ?><div><?= e($p['method']) ?></div><?php endif; ?>
        <?php if (!empty($p['note'])): ?><div class="inv-muted"><?= e($p['note']) ?></div><?php endif; ?>
      </div>
    </section>

    <section class="doc-section">
      <table class="doc-table inv-table">
        <thead><tr><th>البيان</th><th>المدة</th><th>سارٍ حتى</th><th class="num">المبلغ</th></tr></thead>
        <tbody>
          <tr>
            <td>اشتراك <?= e(Platform::setting('platform.name')) ?><?= !empty($p['plan_name']) ? ' — ' . e($p['plan_name']) : '' ?></td>
            <td><?= $p['months'] ? e(Billing::monthsLabel((int) $p['months'])) : '—' ?></td>
            <td><?= $p['paid_until'] ? ltr(e(substr((string) $p['paid_until'], 0, 10))) : 'بدون تاريخ انتهاء' ?></td>
            <td class="num"><?= ltr(e($amount . ' ' . $p['currency'])) ?></td>
          </tr>
        </tbody>
        <tfoot><tr><td colspan="3">الإجمالي المدفوع</td><td class="num"><?= ltr(e($amount . ' ' . $p['currency'])) ?></td></tr></tfoot>
      </table>
    </section>

    <?php if ($note !== ''): ?><section class="inv-note"><?= nl2br(e($note)) ?></section><?php endif; ?>

    <footer class="doc-foot">
      <span><?= e(Platform::setting('platform.name')) ?><?= $wa !== '' ? ' · ' . ltr(e($wa)) : '' ?></span>
      <span class="doc-powered" dir="ltr">Powered by <?= Brand::mark(12) ?><b>Madix</b></span>
    </footer>
  </article>
</div>
