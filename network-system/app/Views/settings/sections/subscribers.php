<?php
/** @var callable $s @var callable $name @var callable $switch */
?>
      <div class="field col-6">
        <label>التنبيه قبل انتهاء الاشتراك بـ</label>
        <div class="input-group"><input class="input" name="<?= e($name('subscribers.expiry_alert_days')) ?>" value="<?= e($s('subscribers.expiry_alert_days')) ?>" inputmode="numeric"><span class="input-addon">يوم</span></div>
      </div>
      <div class="field col-6">
        <label>فترة السماح بعد الانتهاء</label>
        <div class="input-group"><input class="input" name="<?= e($name('subscribers.grace_hours')) ?>" value="<?= e($s('subscribers.grace_hours')) ?>" inputmode="numeric"><span class="input-addon">ساعة</span></div>
      </div>
