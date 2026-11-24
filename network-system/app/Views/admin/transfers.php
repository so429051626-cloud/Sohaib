<?php
/** @var App\Core\View $v @var array $rows @var App\Core\Paginator $pager @var App\Core\Request $request @var string $status @var array $counts */
use App\Platform\Billing;

$v->title = 'تحويلات الزبائن';
$tabs = ['pending' => 'بانتظار الموافقة', 'approved' => 'تمت الموافقة', 'rejected' => 'مرفوضة', 'all' => 'الكل'];
$invoice = $request->int('invoice');
?>
<?php if ($invoice > 0): ?>
<div class="announce-bar is-success mb-3"><?= icon('receipt-text') ?><span>صدرت فاتورة الدفعة.</span><a class="btn btn-sm" href="<?= e(url('/admin/payments/' . $invoice . '/invoice')) ?>" data-no-loading>فتح الفاتورة</a></div>
<?php endif; ?>
<div class="panel">
  <div class="tabs">
    <?php foreach ($tabs as $k => $l): ?>
      <a class="tab<?= $status === $k ? ' is-active' : '' ?>" href="<?= e(url('/admin/transfers', ['status' => $k])) ?>"><?= e($l) ?><?php if ($k !== 'all'): ?><span class="tab-count"><?= num((int) ($counts[$k] ?? 0)) ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
  <?php if (!$rows): ?>
    <div class="empty"><?= icon('banknote') ?><div class="empty-title">لا توجد طلبات</div></div>
  <?php else: ?>
  <div class="transfer-list">
    <?php foreach ($rows as $r): [$sl, $sc] = Billing::TRANSFER_STATUS[$r['status']]; ?>
    <div class="transfer-item">
      <?php if (Billing::receiptPath($r['file']) === null): ?>
      <span class="transfer-thumb is-missing" title="الملف غير موجود"><?= icon('image') ?></span>
      <?php else: ?>
      <a class="transfer-thumb" href="<?= e(url('/admin/transfers/' . $r['id'] . '/file')) ?>" target="_blank" rel="noopener">
        <?php if (!str_ends_with((string) $r['file'], '.pdf')): ?><img src="<?= e(url('/admin/transfers/' . $r['id'] . '/file')) ?>" alt="صورة التحويل" loading="lazy"><?php else: ?><?= icon('file-text') ?><?php endif; ?>
      </a>
      <?php endif; ?>
      <div class="transfer-main">
        <div class="transfer-top">
          <a class="cell-title" href="<?= e(url('/admin/tenants/' . $r['tenant_id'])) ?>"><?= e($r['tenant_name']) ?></a>
          <span class="tenant-link">/<?= e($r['slug']) ?></span>
          <span class="badge <?= $sc ?>"><?= $sl ?></span>
          <?php if ($r['duplicates']): ?><span class="badge badge-danger" title="<?= e(implode('، ', array_map(static fn ($d) => $d['tenant_name'] . ' #' . $d['id'], $r['duplicates']))) ?>"><?= icon('triangle-alert', 'icon-sm') ?>رقم العملية مكرر</span><?php endif; ?>
        </div>
        <dl class="transfer-facts">
          <div><dt>المبلغ</dt><dd><strong><?= ltr(e(number_format((float) $r['amount'], 2) . ' ' . $r['currency'])) ?></strong></dd></div>
          <div><dt>الباقة</dt><dd><?= e($r['plan_name'] ?? '—') ?> · <?= e(Billing::monthsLabel((int) $r['months'])) ?></dd></div>
          <div><dt>الحساب المحوَّل إليه</dt><dd><?= e($r['bank_label'] ?? '—') ?></dd></div>
          <div><dt>رقم العملية</dt><dd class="mono"><?= $r['reference'] ? ltr(e($r['reference'])) : '—' ?></dd></div>
          <div><dt>اسم المحوِّل</dt><dd><?= e($r['sender_name'] ?? '—') ?></dd></div>
          <div><dt>التاريخ</dt><dd><?= e(fmt_dt($r['created_at'])) ?></dd></div>
        </dl>
        <?php if ($r['note']): ?><div class="text-muted text-sm"><?= e($r['note']) ?></div><?php endif; ?>
        <?php if ($r['status'] !== 'pending'): ?>
          <div class="text-sm text-muted"><?= e($r['admin_name'] ?? '') ?><?= $r['reviewed_at'] ? ' · ' . e(fmt_dt($r['reviewed_at'])) : '' ?><?= $r['admin_note'] ? ' · ' . e($r['admin_note']) : '' ?>
            <?php if ($r['payment_id']): ?> · <a href="<?= e(url('/admin/payments/' . $r['payment_id'] . '/invoice')) ?>" data-no-loading>الفاتورة</a><?php endif; ?></div>
        <?php endif; ?>
      </div>
      <?php if ($r['status'] === 'pending'): ?>
      <div class="transfer-actions">
        <form method="post" action="<?= e(url('/admin/transfers/' . $r['id'] . '/approve')) ?>" data-confirm="الموافقة على التحويل وتمديد اشتراك <?= e($r['tenant_name']) ?>؟" data-confirm-type="primary"><?= csrf_field() ?><button class="btn btn-primary btn-sm"><?= icon('check') ?>موافقة</button></form>
        <button type="button" class="btn btn-sm" data-modal-open="m-reject" data-action="<?= e(url('/admin/transfers/' . $r['id'] . '/reject')) ?>"><?= icon('x') ?>رفض</button>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?= $pager->render($request) ?>
  <?php endif; ?>
</div>

<dialog class="modal modal-sm" id="m-reject">
  <form method="post" action="">
    <?= csrf_field() ?>
    <div class="modal-head"><h3><?= icon('x') ?>رفض التحويل</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body"><div class="field"><label>السبب</label><input class="input" name="reason" maxlength="255" required></div></div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-danger">رفض</button></div>
  </form>
</dialog>
<script>
document.getElementById('m-reject').addEventListener('modal:open', function (e) { this.querySelector('form').action = e.detail.trigger.getAttribute('data-action'); });
</script>
