<?php
/** @var callable $s @var callable $name @var callable $switch */
?>
      <div class="field col-3">
        <label>طول اسم المستخدم الافتراضي</label>
        <input class="input" name="<?= e($name('cards.username_length')) ?>" value="<?= e($s('cards.username_length')) ?>" inputmode="numeric">
        <?= error_html($name('cards.username_length')) ?>
      </div>
      <div class="field col-3">
        <label>كلمة المرور</label>
        <select class="input" name="<?= e($name('cards.password_mode')) ?>">
          <option value="same"<?= selected($s('cards.password_mode'), 'same') ?>>مطابقة لاسم المستخدم</option>
          <option value="separate"<?= selected($s('cards.password_mode'), 'separate') ?>>مستقلة</option>
        </select>
      </div>
      <div class="field col-3">
        <label>طول كلمة المرور</label>
        <input class="input" name="<?= e($name('cards.password_length')) ?>" value="<?= e($s('cards.password_length')) ?>" inputmode="numeric">
      </div>
      <div class="field col-3">
        <label>نوع الرموز</label>
        <select class="input" name="<?= e($name('cards.charset')) ?>">
          <?php foreach (\App\Services\CardGenerator::CHARSETS as $k => $c): ?><option value="<?= e($k) ?>"<?= selected($s('cards.charset'), $k) ?>><?= e($c['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field col-12"><?= $switch('cards.remove_ended', 'حذف الكروت المنتهية والمستنفدة من أجهزة MikroTik تلقائياً') ?></div>
