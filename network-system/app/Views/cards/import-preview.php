<?php
/** @var App\Core\View $v */
use App\Services\ShortCodes;

$v->title = 'معاينة الاستيراد';
$v->breadcrumbs = [['الكروت', '/cards'], ['استيراد كروت', '/cards/import'], ['المعاينة', null]];
$cols = (int) $guess['columns'];
$colLabel = static fn (int $i) => 'العمود ' . ($i + 1);
?>
<?= $v->partial('cards-nav') ?>
<form method="post" action="<?= e(url('/cards/import/' . $token)) ?>">
  <?= csrf_field() ?>
  <div class="grid grid-main-side">
    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h2><?= icon('grid-3x3') ?>البيانات المقروءة</h2><span class="sub"><?= num(count($rows)) ?> صف</span></div>
        <div class="table-wrap">
          <table class="table table-compact">
            <thead><tr><th>#</th><?php for ($i = 0; $i < $cols; $i++): ?><th><?= e($colLabel($i)) ?></th><?php endfor; ?></tr></thead>
            <tbody>
            <?php foreach ($preview as $n => $r): ?>
              <tr>
                <td class="text-muted"><?= $n + 1 ?></td>
                <?php for ($i = 0; $i < $cols; $i++): ?><td><span class="mono"><?= e($r[$i] ?? '') ?></span></td><?php endfor; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if (count($rows) > count($preview)): ?><div class="panel-foot text-sm text-muted">يُعرض أول <?= count($preview) ?> صفاً.</div><?php endif; ?>
      </div>
    </div>

    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h3><?= icon('sliders-horizontal') ?>تحديد الأعمدة</h3></div>
        <div class="panel-body stack" style="gap:12px">
          <div class="field">
            <label>اسم المستخدم (رقم الكرت) <span class="req">*</span></label>
            <select class="input" name="username_col"><?php for ($i = 0; $i < $cols; $i++): ?><option value="<?= $i ?>"<?= selected(old('username_col', $guess['username']), $i) ?>><?= e($colLabel($i)) ?></option><?php endfor; ?></select>
          </div>
          <div class="field">
            <label>كلمة المرور</label>
            <select class="input" name="password_col">
              <option value="-1"<?= selected(old('password_col', $guess['password']), -1) ?>>نفس اسم المستخدم</option>
              <?php for ($i = 0; $i < $cols; $i++): ?><option value="<?= $i ?>"<?= selected(old('password_col', $guess['password']), $i) ?>><?= e($colLabel($i)) ?></option><?php endfor; ?>
            </select>
          </div>
          <div class="field">
            <label>الرقم التسلسلي الخارجي</label>
            <select class="input" name="serial_col">
              <option value="-1">بدون</option>
              <?php for ($i = 0; $i < $cols; $i++): ?><option value="<?= $i ?>"<?= selected(old('serial_col', -1), $i) ?>><?= e($colLabel($i)) ?></option><?php endfor; ?>
            </select>
          </div>
          <label class="check"><input type="checkbox" name="skip_header" value="1"<?= checked(old('skip_header', $guess['header'])) ?>>الصف الأول عناوين</label>
        </div>
      </div>
      <div class="panel">
        <div class="panel-head"><h3><?= icon('layers') ?>الباقة والدفعة</h3></div>
        <div class="panel-body stack" style="gap:12px">
          <div class="field">
            <label>الباقة <span class="req">*</span></label>
            <select class="input" name="category_id" required>
              <?php foreach ($categories as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected(old('category_id'), $id) ?>><?= e($name) ?></option><?php endforeach; ?>
            </select>
            <?= error_html('category_id') ?>
          </div>
          <div class="field"><label>اسم الدفعة</label><input class="input" name="batch_name" value="<?= e(old('batch_name')) ?>"></div>
        </div>
      </div>
      <div class="panel">
        <div class="panel-head"><h3><?= icon('link-2') ?>مختصِر الأرقام</h3></div>
        <div class="panel-body stack" style="gap:12px">
          <label class="switch"><input type="checkbox" name="with_short" value="1"<?= checked(old('with_short', '1')) ?>><span class="track"></span><span>إنشاء رقم مختصر لكل كرت</span></label>
          <div class="field" data-show-when="with_short=1">
            <label>طول اسم المستخدم المختصر</label>
            <input class="input" name="short_length" value="<?= e(old('short_length', '6')) ?>" inputmode="numeric">
            <?= error_html('short_length') ?>
          </div>
          <div class="field" data-show-when="with_short=1">
            <label>طول كلمة المرور المختصرة</label>
            <input class="input" name="short_password_length" value="<?= e(old('short_password_length', '0')) ?>" inputmode="numeric">
            <span class="hint">0 = بدون كلمة مرور</span>
            <?= error_html('short_password_length') ?>
          </div>
          <div class="field" data-show-when="with_short=1">
            <label>طريقة التوليد</label>
            <select class="input" name="short_mode">
              <?php foreach (ShortCodes::MODES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected(old('short_mode', 'random'), $k) ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field" data-show-when="with_short=1">
            <label>رقم البداية (للتسلسلي)</label>
            <input class="input" name="short_start" value="<?= e(old('short_start')) ?>" inputmode="numeric">
          </div>
        </div>
      </div>
      <div class="panel"><div class="panel-body">
        <button class="btn btn-primary btn-lg btn-block"><?= icon('download') ?>استيراد <?= num(count($rows)) ?> صف</button>
      </div></div>
    </div>
  </div>
</form>
