<?php
/** @var App\Core\View $v */
use App\Services\Provisioner;

$rid = (int) $router['id'];
?>
<div class="panel-body tight">
  <div class="panel-head" style="border-top:0"><h3><?= icon('cable') ?>حسابات PPP <span class="sub">(<?= num(count($rows)) ?>)</span></h3></div>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>الحساب</th><th>الخدمة</th><th>الملف</th><th>العنوان الثابت</th><th>ربط الجهاز</th><th>آخر خروج</th><th>الحالة</th><th>المصدر</th><th class="w-actions"></th></tr></thead>
      <tbody>
      <?php foreach (array_slice($rows, 0, 1000) as $s):
          $tag = Provisioner::parseTag((string) ($s['comment'] ?? ''));
          $disabled = ($s['disabled'] ?? 'false') === 'true'; ?>
        <tr>
          <td><?php if ($tag): ?><a class="mono" href="<?= e(url('/subscribers/' . $tag[1])) ?>"><?= e($s['name']) ?></a><?php else: ?><span class="mono"><?= e($s['name']) ?></span><?php endif; ?></td>
          <td><?= e($s['service'] ?? 'any') ?></td>
          <td><span class="mono text-sm"><?= e($s['profile'] ?? '') ?></span></td>
          <td><span class="mono text-sm"><?= e($s['remote-address'] ?? '—') ?></span></td>
          <td><span class="mono text-sm"><?= e(($s['caller-id'] ?? '') ?: '—') ?></span></td>
          <td class="nowrap text-sm"><?= e($s['last-logged-out'] ?? '—') ?></td>
          <td><?= $disabled ? '<span class="badge badge-danger">موقوف</span>' : '<span class="badge badge-success">فعّال</span>' ?></td>
          <td><?= $tag ? '<span class="badge badge-primary">النظام</span>' : '<span class="badge badge-outline">محلي</span>' ?></td>
          <td class="w-actions">
            <?php if (!$tag): ?>
            <form method="post" action="<?= e(url('/routers/' . $rid . '/action')) ?>">
              <?= csrf_field() ?><input type="hidden" name="tab" value="ppp"><input type="hidden" name="op" value="ppp_toggle"><input type="hidden" name="ref" value="<?= e($s['.id']) ?>"><input type="hidden" name="disable" value="<?= $disabled ? '0' : '1' ?>">
              <button class="btn btn-ghost btn-icon btn-sm" title="<?= $disabled ? 'تفعيل' : 'إيقاف' ?>"><?= icon($disabled ? 'play' : 'pause') ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="9"><div class="empty"><?= icon('cable') ?><div>لا توجد حسابات PPP.</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="panel-head"><h3><?= icon('package') ?>ملفات PPP</h3></div>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>الاسم</th><th>السرعة</th><th>العنوان المحلي</th><th>عناوين المشتركين</th><th>DNS</th><th>جلسة واحدة</th></tr></thead>
      <tbody>
      <?php foreach ($profiles as $p): ?>
        <tr>
          <td><span class="mono"><?= e($p['name'] ?? '') ?></span></td>
          <td><span class="mono text-sm"><?= e(($p['rate-limit'] ?? '') ?: '—') ?></span></td>
          <td><span class="mono text-sm"><?= e(($p['local-address'] ?? '') ?: '—') ?></span></td>
          <td><span class="mono text-sm"><?= e(($p['remote-address'] ?? '') ?: '—') ?></span></td>
          <td><span class="mono text-sm"><?= e(($p['dns-server'] ?? '') ?: '—') ?></span></td>
          <td><?= e($p['only-one'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
