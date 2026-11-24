<?php
/** @var App\Core\View $v */
?>
<div class="table-wrap" style="max-height:640px;overflow:auto">
  <table class="table table-compact">
    <thead><tr><th style="width:110px">الوقت</th><th style="width:200px">الموضوع</th><th>الرسالة</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $l):
        $topics = (string) ($l['topics'] ?? '');
        $cls = str_contains($topics, 'error') || str_contains($topics, 'critical') ? 'badge-danger' : (str_contains($topics, 'warning') ? 'badge-warning' : 'badge-outline'); ?>
      <tr>
        <td class="nowrap"><span class="mono text-sm"><?= e($l['time'] ?? '') ?></span></td>
        <td><span class="badge <?= $cls ?>"><?= e($topics) ?></span></td>
        <td><span class="mono text-sm" style="white-space:pre-wrap"><?= e($l['message'] ?? '') ?></span></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="3"><div class="empty"><?= icon('list') ?><div>السجل فارغ.</div></div></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
