<?php
/** @var App\Core\View $v */
?>
<div class="table-wrap">
  <table class="table table-compact">
    <thead><tr><th>الهوية</th><th>الواجهة</th><th>IP</th><th>MAC</th><th>المنصة</th><th>اللوحة</th><th>الإصدار</th><th>مدة التشغيل</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $n): ?>
      <tr>
        <td class="cell-title"><?= e($n['identity'] ?? '—') ?></td>
        <td><span class="mono text-sm"><?= e($n['interface'] ?? '') ?></span></td>
        <td><span class="mono text-sm"><?= e($n['address'] ?? '—') ?></span></td>
        <td><span class="mono text-sm"><?= e($n['mac-address'] ?? '') ?></span></td>
        <td><?= e($n['platform'] ?? '') ?></td>
        <td><?= e($n['board'] ?? '') ?></td>
        <td><span class="mono text-sm"><?= e($n['version'] ?? '') ?></span></td>
        <td><?= e($n['uptime'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8"><div class="empty"><?= icon('radio-tower') ?><div>لم تُكتشف أجهزة مجاورة.</div></div></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
