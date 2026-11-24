<?php
/**
 * @var App\Core\View $v @var array $t @var string $phase @var ?int $phaseEnds @var ?int $daysLeft @var ?array $plan
 * @var array $plans @var array $banks @var array $requests @var array $payments @var string $currency
 */
use App\Core\Platform;
use App\Platform\Billing;

$v->title = 'الاشتراك';
$v->script('js/billing.js');
$price = static fn ($x) => number_format((float) $x, abs((float) $x - round((float) $x)) < 0.005 ? 0 : 2, '.', ',');
$end = $t['status'] === 'trial' ? $t['trial_ends_at'] : $t['paid_until'];
$phaseInfo = [
    'ok' => $t['status'] === 'trial' ? ['تجربة مجانية', 'badge-info'] : ['سارٍ', 'badge-success'],
    'grace' => ['فترة سماح', 'badge-warning'], 'readonly' => ['قراءة فقط', 'badge-danger'], 'blocked' => ['موقوف', 'badge-danger'],
][$phase];
$pending = array_filter($requests, static fn ($r) => $r['status'] === 'pending');
$selected = (int) old('plan_id', $plan['id'] ?? ($plans[0]['id'] ?? 0));
// التواصل عبر واتساب لتفعيل الاشتراك أو تجديده (رقم المنصة من إعدادات المسؤول)
$waNumber = trim(Platform::setting('platform.whatsapp'));
$waIntl = (string) preg_replace('/\D/', '', latin_digits(Platform::setting('platform.whatsapp_intl')));
$waLink = $waIntl !== '' ? Platform::whatsappLink('مرحباً، أريد تفعيل اشتراك حساب ' . $t['name'] . ' (' . $t['slug'] . ')' . ($plan ? ' — الباقة: ' . $plan['name'] : '')) : '';
?>
<div class="sub-hero panel is-<?= e($phase) ?>">
  <div class="sub-hero-main">
    <span class="sub-hero-icon"><?= icon($phase === 'ok' ? 'badge-check' : 'calendar-clock') ?></span>
    <div>
      <div class="sub-hero-title"><?= e($plan['name'] ?? ($t['status'] === 'trial' ? 'الفترة التجريبية' : 'اشتراك مفعّل')) ?> <span class="badge <?= $phaseInfo[1] ?>"><?= $phaseInfo[0] ?></span></div>
      <div class="sub-hero-sub">
        <?php if ($end === null): ?>بدون تاريخ انتهاء
        <?php elseif ($phase === 'ok'): ?>ينتهي في <?= ltr(e(substr((string) $end, 0, 10))) ?> · متبقي <b><?= $daysLeft === 0 ? 'أقل من يوم' : num((int) $daysLeft) . ' يوم' ?></b>
        <?php elseif ($phase === 'grace'): ?>انتهى في <?= ltr(e(substr((string) $end, 0, 10))) ?> · يعمل النظام كاملاً حتى <?= ltr(e(date('Y-m-d', (int) $phaseEnds))) ?>
        <?php else: ?>انتهى في <?= ltr(e(substr((string) $end, 0, 10))) ?> · العرض فقط حتى <?= ltr(e(date('Y-m-d', (int) $phaseEnds))) ?><?php endif; ?>
      </div>
    </div>
  </div>
  <?php if ($pending): ?><span class="badge badge-warning"><?= icon('hourglass', 'icon-sm') ?>طلب بانتظار الموافقة</span><?php endif; ?>
</div>

<?php if ($waLink !== ''): ?>
<div class="panel billing-contact">
  <div class="billing-contact-main">
    <span class="billing-contact-icon"><?= icon('message-circle') ?></span>
    <div>
      <div class="billing-contact-title">فعّل خطك عبر واتساب</div>
      <div class="billing-contact-sub">تواصل معنا على واتساب<?php if ($waNumber !== ''): ?> <b><?= ltr(e($waNumber)) ?></b><?php endif; ?> لتفعيل اشتراكك أو تجديده مباشرة</div>
    </div>
  </div>
  <a class="btn btn-whatsapp" href="<?= e($waLink) ?>" target="_blank" rel="noopener" data-no-loading><?= icon('message-circle') ?>تواصل عبر واتساب</a>
</div>
<?php endif; ?>

<?php if ($plans): ?>
<div class="plan-grid mb-3">
  <?php foreach ($plans as $p): ?>
  <label class="panel plan-card plan-pick<?= (int) $p['is_featured'] ? ' is-featured' : '' ?>">
    <input type="radio" name="plan_pick" value="<?= (int) $p['id'] ?>" data-price="<?= e((string) (float) $p['price']) ?>" data-months="<?= (int) $p['months'] ?>"<?= checked($selected === (int) $p['id']) ?>>
    <div class="plan-card-head"><div><div class="plan-card-name"><?= e($p['name']) ?></div><?php if ($p['tagline']): ?><div class="text-muted text-sm"><?= e($p['tagline']) ?></div><?php endif; ?></div>
      <?php if (($plan['id'] ?? 0) == $p['id']): ?><span class="badge badge-success">باقتك الحالية</span><?php elseif ((int) $p['is_featured']): ?><span class="badge badge-info">الأكثر طلباً</span><?php endif; ?></div>
    <div class="plan-card-price"><b><?= ltr(e($price($p['price']))) ?></b><span><?= e($currency) ?> / <?= e(Billing::monthsLabel((int) $p['months'])) ?></span></div>
    <ul class="plan-card-list">
      <?php foreach (array_merge(Billing::planLimits($p), Billing::planFeatures($p)) as $line): ?><li><?= icon('check', 'icon-sm') ?><span><?= e($line) ?></span></li><?php endforeach; ?>
    </ul>
    <span class="plan-pick-mark"><?= icon('circle-check') ?>مختارة</span>
  </label>
  <?php endforeach; ?>
</div>

<div class="grid grid-main-side">
  <form method="post" action="<?= e(url('/billing')) ?>" enctype="multipart/form-data" class="panel" data-billing-form>
    <?= csrf_field() ?><input type="hidden" name="plan_id" value="<?= $selected ?>">
    <div class="panel-head"><h3><?= icon('upload') ?>طلب تجديد أو ترقية بتحويل بنكي</h3></div>
    <div class="panel-body">
      <div class="form-grid">
        <div class="field col-6"><label>عدد المدد</label><select class="input" name="periods"><?php for ($i = 1; $i <= 12; $i++): ?><option value="<?= $i ?>"<?= selected((int) old('periods', 1), $i) ?>><?= $i ?></option><?php endfor; ?></select></div>
        <div class="field col-6"><label>المبلغ المحوَّل</label><div class="input-group"><input class="input input-ltr" name="amount" value="<?= e(old('amount')) ?>" inputmode="decimal" required><span class="input-addon"><?= e($currency) ?></span></div></div>
        <?php if ($banks): ?>
        <div class="field col-12"><label>حوِّل إلى</label>
          <div class="bank-list">
            <?php foreach ($banks as $i => $b): ?>
            <label class="bank-item"><input type="radio" name="bank_account_id" value="<?= (int) $b['id'] ?>"<?= checked((int) old('bank_account_id', $banks[0]['id']) === (int) $b['id']) ?>>
              <span class="bank-item-body"><b><?= e($b['label']) ?></b><span><?= e($b['holder']) ?></span><span class="mono"><?= ltr(e($b['number'])) ?></span><?php if ($b['details']): ?><small><?= e($b['details']) ?></small><?php endif; ?></span>
              <button type="button" class="btn btn-ghost btn-icon btn-sm" data-copy="<?= e($b['number']) ?>" title="نسخ الرقم"><?= icon('copy') ?></button>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <div class="field col-6"><label>رقم العملية</label><input class="input input-ltr" name="reference" value="<?= e(old('reference')) ?>" maxlength="80"></div>
        <div class="field col-6"><label>اسم المحوِّل</label><input class="input" name="sender_name" value="<?= e(old('sender_name')) ?>" maxlength="120"></div>
        <div class="field col-12"><label>صورة التحويل</label><input class="input" type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" required><?= error_html('receipt') ?></div>
        <div class="field col-12"><label>ملاحظة</label><input class="input" name="note" value="<?= e(old('note')) ?>" maxlength="255"></div>
      </div>
      <div class="form-actions"><button class="btn btn-primary"><?= icon('send') ?>إرسال الطلب</button><span class="text-muted text-sm" data-billing-summary></span></div>
    </div>
  </form>

  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('history') ?>طلباتي</h3></div>
      <ul class="list">
        <?php foreach ($requests as $r): [$sl, $sc] = Billing::TRANSFER_STATUS[$r['status']]; ?>
        <li class="list-item">
          <span class="list-icon"><?= icon('receipt') ?></span>
          <div class="list-item-main"><div class="list-item-title"><?= ltr(e($price($r['amount']) . ' ' . $r['currency'])) ?> · <?= e($r['plan_name'] ?? '') ?></div>
            <div class="list-item-sub"><?= e(fmt_dt($r['created_at'])) ?><?= $r['status'] === 'rejected' && $r['admin_note'] ? ' · ' . e($r['admin_note']) : '' ?></div></div>
          <div class="list-item-end"><span class="badge <?= $sc ?>"><?= $sl ?></span><?php if (Billing::receiptPath($r['file']) !== null): ?><a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/billing/receipt/' . $r['id'])) ?>" target="_blank" rel="noopener" title="صورة التحويل"><?= icon('image') ?></a><?php endif; ?></div>
        </li>
        <?php endforeach; ?>
        <?php if (!$requests): ?><li class="list-item text-muted">لا توجد طلبات.</li><?php endif; ?>
      </ul>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('receipt-text') ?>الفواتير</h3></div>
      <ul class="list">
        <?php foreach ($payments as $p): ?>
        <li class="list-item">
          <span class="list-icon is-success"><?= icon('banknote') ?></span>
          <div class="list-item-main"><div class="list-item-title"><?= ltr(e($price($p['amount']) . ' ' . $p['currency'])) ?></div><div class="list-item-sub"><?= e(substr((string) $p['created_at'], 0, 10)) ?><?= $p['paid_until'] ? ' · حتى ' . e(substr((string) $p['paid_until'], 0, 10)) : '' ?></div></div>
          <div class="list-item-end"><a class="btn btn-sm" href="<?= e(url('/billing/invoice/' . $p['id'])) ?>" data-no-loading><?= icon('file-down') ?>الفاتورة</a></div>
        </li>
        <?php endforeach; ?>
        <?php if (!$payments): ?><li class="list-item text-muted">لا توجد فواتير.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>
<?php else: ?>
<div class="panel"><div class="empty"><?= icon('package') ?><div class="empty-title">لا توجد باقات معروضة حالياً</div></div></div>
<?php endif; ?>

<form class="panel billing-email mt-3" method="post" action="<?= e(url('/billing/email')) ?>">
  <?= csrf_field() ?>
  <div class="panel-head"><h3><?= icon('mail') ?>بريد الفواتير والتنبيهات</h3><?php if (!empty($t['email'])): ?><span class="badge badge-success badge-dot">مضبوط</span><?php endif; ?></div>
  <div class="panel-body">
    <p class="text-sm text-muted" style="margin:0 0 10px">تصل إليه الفاتورة عند كل دفعة، وتذكير قبل انتهاء الاشتراك، وتأكيد التحويل البنكي، وردود الدعم الفني. اتركه فارغاً لإيقاف البريد.</p>
    <div class="input-group"><input class="input input-ltr" type="email" name="email" maxlength="120" value="<?= e(old('email', (string) ($t['email'] ?? ''))) ?>" placeholder="you@example.com" aria-label="البريد الإلكتروني"><button class="btn btn-primary"><?= icon('save') ?>حفظ</button></div>
    <?= error_html('email') ?>
  </div>
</form>
