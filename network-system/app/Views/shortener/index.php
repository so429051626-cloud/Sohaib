<?php
/** @var App\Core\View $v */
use App\Services\ShortCodes;

$v->title = 'اختصار الأرقام';
$coverage = (int) $stats['total'] > 0 ? (int) round((int) $stats['with_short'] / (int) $stats['total'] * 100) : 0;
?>
<div class="stats">
  <div class="stat"><div class="stat-icon"><?= icon('ticket') ?></div><div class="stat-body"><div class="stat-label">كل الكروت</div><div class="stat-value"><?= num($stats['total']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-success"><?= icon('link-2') ?></div><div class="stat-body"><div class="stat-label">لها رقم مختصر</div><div class="stat-value"><?= num($stats['with_short']) ?></div><div class="stat-meta"><?= $coverage ?>%</div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('hash') ?></div><div class="stat-body"><div class="stat-label">الأطوال المستخدمة</div><div class="stat-value" style="font-size:15px;white-space:normal;display:flex;flex-wrap:wrap;gap:4px;margin-top:4px"><?php if ($lengths): foreach ($lengths as $l => $n): ?><span class="chip"><?= (int) $l ?> أرقام: <?= num($n) ?></span> <?php endforeach; else: ?>—<?php endif; ?></div></div></div>
</div>

<div class="grid grid-main-side">
  <form method="post" action="<?= e(url('/shortener')) ?>" class="panel" id="short-form">
    <?= csrf_field() ?>
    <div class="panel-head"><h2><?= icon('link-2') ?>تخصيص الأرقام المختصرة</h2></div>
    <div class="panel-body">
      <div class="form-grid">
        <div class="field col-12">
          <label>نطاق الكروت</label>
          <div class="segmented">
            <label><input type="radio" name="source" value="batch"<?= checked($selectedBatch > 0 || old('source', 'batch') === 'batch') ?>><span>دفعة</span></label>
            <label><input type="radio" name="source" value="category"<?= checked(old('source') === 'category') ?>><span>باقة</span></label>
            <label><input type="radio" name="source" value="all"<?= checked(old('source') === 'all') ?>><span>كل الكروت</span></label>
          </div>
          <?= error_html('source') ?>
        </div>
        <div class="field col-12" data-show-when="source=batch">
          <label>الدفعة</label>
          <select class="input" name="batch_id">
            <?php foreach ($batches as $b): ?>
              <option value="<?= (int) $b['id'] ?>"<?= selected($selectedBatch, $b['id']) ?>>#<?= (int) $b['id'] ?> — <?= e($b['name']) ?> (<?= num($b['with_short']) ?>/<?= num($b['total']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field col-12" data-show-when="source=category">
          <label>الباقة</label>
          <select class="input" name="category_id"><?php foreach ($categories as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-4">
          <label for="short_length">طول اسم المستخدم المختصر</label>
          <input class="input" id="short_length" name="short_length" value="<?= e(old('short_length', '6')) ?>" inputmode="numeric">
          <?= error_html('short_length') ?>
        </div>
        <div class="field col-4">
          <label for="short_password_length">طول كلمة المرور المختصرة</label>
          <input class="input" id="short_password_length" name="short_password_length" value="<?= e(old('short_password_length', '0')) ?>" inputmode="numeric">
          <span class="hint">0 = بدون كلمة مرور</span>
          <?= error_html('short_password_length') ?>
        </div>
        <div class="field col-4">
          <label for="short_mode">طريقة التوليد</label>
          <select class="input" id="short_mode" name="short_mode">
            <?php foreach (ShortCodes::MODES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected(old('short_mode', 'random'), $k) ?>><?= e($l) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field col-4" data-show-when="short_mode=sequence">
          <label for="short_start">رقم البداية</label>
          <input class="input" id="short_start" name="short_start" value="<?= e(old('short_start')) ?>" inputmode="numeric">
        </div>
        <div class="field col-6"><label class="check"><input type="checkbox" name="overwrite" value="1">استبدال الأرقام المختصرة الحالية</label></div>
        <div class="field col-6"><label class="check"><input type="checkbox" name="only_available" value="1" checked>الكروت غير المستخدمة فقط</label></div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" name="op" value="assign"><?= icon('link-2') ?>تخصيص</button>
        <button class="btn btn-soft-danger" name="op" value="clear" data-confirm="إزالة الأرقام المختصرة من الكروت ضمن النطاق؟"><?= icon('link-2-off') ?>إزالة</button>
      </div>
    </div>
  </form>

  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('search') ?>البحث برقم مختصر</h3></div>
      <div class="panel-body">
        <form method="get" class="flex gap-2 mb-2">
          <input class="input input-ltr" name="lookup" value="<?= e($lookupQuery) ?>" placeholder="الرقم المختصر أو الأصلي">
          <button class="btn"><?= icon('search') ?></button>
        </form>
        <?php if ($lookup === false): ?>
          <div class="notice notice-warning"><?= icon('circle-help') ?><div>لا يوجد كرت مطابق.</div></div>
        <?php elseif ($lookup): ?>
          <dl class="kv">
            <dt>الرقم المختصر</dt><dd><span class="mono" style="font-size:16px"><?= e($lookup['short_code'] ?? '—') ?></span></dd>
            <?php if (!empty($lookup['short_password'])): ?><dt>كلمة المرور المختصرة</dt><dd><span class="mono" style="font-size:16px"><?= can('cards.passwords') ? e($lookup['short_password']) : '••••' ?></span></dd><?php endif; ?>
            <dt>رقم الكرت</dt><dd><a class="mono" href="<?= e(url('/check/card/' . $lookup['id'])) ?>"><?= e($lookup['username']) ?></a></dd>
            <dt>الباقة</dt><dd><?= e($lookup['category_name']) ?></dd>
          </dl>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
