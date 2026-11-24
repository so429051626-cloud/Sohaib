<?php
/** @var App\Core\View $v */
use App\Services\RouterTasks;

$rid = (int) $router['id'];
$status = ['pending' => ['معلّقة', 'badge-warning'], 'done' => ['منفّذة', 'badge-success'], 'failed' => ['فشلت', 'badge-danger']];
?>
<div class="panel-body">
  <div class="flex gap-2 wrap">
    <form method="post" action="<?= e(url('/routers/' . $rid . '/tasks')) ?>"><?= csrf_field() ?><input type="hidden" name="op" value="retry"><button class="btn"><?= icon('rotate-cw') ?>إعادة المحاولة الآن</button></form>
    <form method="post" action="<?= e(url('/routers/' . $rid . '/tasks')) ?>" data-confirm="حذف كل المهام المعلّقة والفاشلة لهذا الجهاز؟"><?= csrf_field() ?><input type="hidden" name="op" value="clear"><button class="btn btn-soft-danger"><?= icon('trash-2') ?>حذف المعلّقة</button></form>
  </div>
</div>
<div class="table-wrap">
  <table class="table table-compact">
    <thead><tr><th>#</th><th>العملية</th><th>الهدف</th><th>الحالة</th><th class="num">المحاولات</th><th>آخر خطأ</th><th>الإنشاء</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $t):
        $s = $status[$t['status']] ?? [$t['status'], 'badge']; ?>
      <tr>
        <td class="mono text-sm"><?= (int) $t['id'] ?></td>
        <td><?= e(RouterTasks::LABELS[$t['action']] ?? $t['action']) ?></td>
        <td><span class="mono text-sm"><?= e($t['target'] ?? '—') ?></span></td>
        <td><span class="badge <?= $s[1] ?>"><?= e($s[0]) ?></span></td>
        <td class="num"><?= (int) $t['attempts'] ?></td>
        <td class="text-sm"><?= e($t['last_error'] ?? '') ?></td>
        <td class="nowrap text-sm"><?= e(fmt_dt($t['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7"><div class="empty"><?= icon('list-checks') ?><div>لا توجد مهام.</div></div></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
