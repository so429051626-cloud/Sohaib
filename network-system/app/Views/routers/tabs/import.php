<?php
/** @var App\Core\View $v */
$rid = (int) $router['id'];
?>
<div class="panel-body">
  <div class="grid grid-2">
    <?php if (can('cards.import')): ?>
    <form method="post" action="<?= e(url('/routers/' . $rid . '/import')) ?>" class="panel">
      <?= csrf_field() ?><input type="hidden" name="kind" value="hotspot">
      <div class="panel-head"><h3><?= icon('ticket') ?>مستخدمو الهوتسبوت ← كروت</h3></div>
      <div class="panel-body">
        <?php if (!$hsProfiles): ?>
          <div class="empty"><?= icon('users') ?><div>لا يوجد مستخدمو هوتسبوت محليون غير مُدارين.</div></div>
        <?php elseif (!$categories): ?>
          <div class="notice notice-warning"><?= icon('triangle-alert') ?><div>لا توجد باقات.</div></div>
        <?php else: ?>
          <table class="table table-compact mb-2">
            <thead><tr><th>ملف جهاز MikroTik</th><th class="num">المستخدمون</th><th>الباقة في النظام</th></tr></thead>
            <tbody>
            <?php foreach ($hsProfiles as $profile => $count): ?>
              <tr>
                <td><span class="mono"><?= e($profile) ?></span></td>
                <td class="num"><?= num($count) ?></td>
                <td>
                  <select class="input input-sm" name="map[<?= e($profile) ?>]">
                    <option value="0">عدم الاستيراد</option>
                    <?php foreach ($categories as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <label class="switch mb-2"><input type="checkbox" name="retag" value="1" checked><span class="track"></span><span>نقل المستخدمين إلى إدارة النظام (تعديل التعليق وملف السرعة على جهاز MikroTik)</span></label>
          <div class="mt-2"><button class="btn btn-primary"><?= icon('download') ?>بدء الاستيراد</button></div>
        <?php endif; ?>
      </div>
    </form>
    <?php endif; ?>

    <?php if (can('subscribers.manage')): ?>
    <form method="post" action="<?= e(url('/routers/' . $rid . '/import')) ?>" class="panel">
      <?= csrf_field() ?><input type="hidden" name="kind" value="ppp">
      <div class="panel-head"><h3><?= icon('users') ?>حسابات PPP ← مشتركون</h3></div>
      <div class="panel-body">
        <?php if (!$pppProfiles): ?>
          <div class="empty"><?= icon('cable') ?><div>لا توجد حسابات PPP غير مُدارة.</div></div>
        <?php elseif (!$plans): ?>
          <div class="notice notice-warning"><?= icon('triangle-alert') ?><div>لا توجد باقات اشتراك.</div></div>
        <?php else: ?>
          <table class="table table-compact mb-2">
            <thead><tr><th>ملف PPP</th><th class="num">الحسابات</th><th>الباقة في النظام</th></tr></thead>
            <tbody>
            <?php foreach ($pppProfiles as $profile => $count): ?>
              <tr>
                <td><span class="mono"><?= e($profile) ?></span></td>
                <td class="num"><?= num($count) ?></td>
                <td>
                  <select class="input input-sm" name="map[<?= e($profile) ?>]">
                    <option value="0">عدم الاستيراد</option>
                    <?php foreach ($plans as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <div class="field mb-2" style="max-width:260px">
            <label>تاريخ انتهاء الاشتراكات المستوردة</label>
            <input class="input" type="date" name="expires_at">
          </div>
          <label class="switch mb-2"><input type="checkbox" name="retag" value="1" checked><span class="track"></span><span>نقل الحسابات إلى إدارة النظام</span></label>
          <div class="mt-2"><button class="btn btn-primary"><?= icon('download') ?>بدء الاستيراد</button></div>
        <?php endif; ?>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
