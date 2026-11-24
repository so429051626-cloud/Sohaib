<?php
/** @var App\Core\View $v */
use App\Core\Request;
use App\Services\JobRunner;

$v->title = 'العمليات الجارية';
?>
<div class="panel">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>#</th><th>العملية</th><th>النوع</th><th>الحالة</th><th>التقدم</th><th class="num">الأخطاء</th><th>بواسطة</th><th>البداية</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $j):
          [$label, $cls] = JobRunner::STATUS[$j['status']] ?? [$j['status'], 'badge'];
          $pct = $j['total'] > 0 ? (int) round($j['progress'] / $j['total'] * 100) : ($j['status'] === 'done' ? 100 : 0); ?>
        <tr>
          <td class="mono text-sm"><?= (int) $j['id'] ?></td>
          <td><a class="cell-title" href="<?= e(url('/jobs/' . $j['id'])) ?>"><?= e($j['title']) ?></a><?php if ($j['message']): ?><div class="cell-sub"><?= e($j['message']) ?></div><?php endif; ?></td>
          <td class="text-sm"><?= e(JobRunner::TYPES[$j['type']] ?? $j['type']) ?></td>
          <td><span class="badge <?= $cls ?>"><?= e($label) ?></span></td>
          <td style="min-width:140px"><div class="meter-row"><span><?= num($j['progress']) ?> / <?= num($j['total']) ?></span><strong><?= $pct ?>%</strong></div><div class="meter"><span style="width:<?= $pct ?>%"></span></div></td>
          <td class="num"><?= num($j['errors']) ?></td>
          <td><?= e($j['employee_name'] ?? 'النظام') ?></td>
          <td class="nowrap text-sm"><?= e(fmt_dt($j['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8"><div class="empty"><?= icon('hourglass') ?><div class="empty-title">لا توجد عمليات</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= $pager->render(Request::current()) ?>
</div>
