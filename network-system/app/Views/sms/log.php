<?php
/** @var App\Core\View $v @var array $rows @var array $stats @var App\Core\Request $request @var string $q */
$v->title = 'سجل الرسائل';
if (can('sms.send')) {
    $v->actions = '<a class="btn btn-primary" href="' . e(url('/sms')) . '">' . icon('send') . 'إرسال رسالة</a>';
}
$kinds = ['' => 'الكل', 'manual' => 'يدوية', 'auto' => 'تلقائية', 'test' => 'تجريبية'];
$status = ['queued' => ['في الانتظار', 'badge-outline'], 'sending' => ['جارٍ الإرسال', 'badge-info'], 'done' => ['تم', 'badge-success'], 'failed' => ['فشل', 'badge-danger']];
?>
<div class="stats">
  <div class="stat"><div class="stat-icon is-success"><?= icon('circle-check') ?></div><div class="stat-body"><div class="stat-label">أُرسلت هذا الشهر</div><div class="stat-value"><?= num((int) $stats['sent']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('message-circle') ?></div><div class="stat-body"><div class="stat-label">مقاطع مستهلكة</div><div class="stat-value"><?= num((int) $stats['units']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-danger"><?= icon('circle-x') ?></div><div class="stat-body"><div class="stat-label">لم تُرسل</div><div class="stat-value"><?= num((int) $stats['failed']) ?></div></div></div>
</div>
<div class="panel">
  <form class="filters" method="get">
    <div class="field grow"><label for="q">بحث</label><input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="رقم الهاتف أو الاسم أو النص"></div>
    <div class="field"><label for="kind">النوع</label><select class="input" id="kind" name="kind"><?php foreach ($kinds as $k => $l): ?><option value="<?= $k ?>"<?= selected($request->str('kind'), $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('search') ?>بحث</button></div>
  </form>
  <?php if (!$rows): ?>
    <div class="empty"><?= icon('message-circle') ?><div class="empty-title">لا توجد رسائل</div></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>الرسالة</th><th>المستلمون</th><th class="num">أُرسلت</th><th class="num">فشلت</th><th>الحالة</th><th>بواسطة</th><th>التاريخ</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $m): [$sl, $sc] = $status[$m['status']]; ?>
        <tr>
          <td><a class="cell-title" href="<?= e(url('/sms/log/' . $m['id'])) ?>"><?= e(mb_strimwidth($m['body'], 0, 70, '…')) ?></a><div class="cell-sub"><?= num((int) $m['segments']) ?> مقطع<?= $m['kind'] !== 'manual' ? ' · ' . $kinds[$m['kind']] : '' ?></div></td>
          <td class="text-sm"><?= e($m['audience'] ?: '—') ?><div class="cell-sub"><?= num((int) $m['recipients']) ?> مستلم</div></td>
          <td class="num"><?= num((int) $m['sent']) ?></td>
          <td class="num<?= (int) $m['failed'] ? ' text-danger' : '' ?>"><?= num((int) $m['failed']) ?></td>
          <td><span class="badge <?= $sc ?>"><?= $sl ?></span><?= $m['scheduled_at'] ? '<div class="cell-sub">' . icon('clock', 'icon-sm') . e(fmt_dt($m['scheduled_at'])) . '</div>' : '' ?></td>
          <td class="text-sm"><?= e($m['employee_name'] ?? ($m['kind'] === 'auto' ? 'النظام' : '—')) ?></td>
          <td class="nowrap text-sm"><?= e(fmt_dt($m['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= $pager->render($request) ?>
  <?php endif; ?>
</div>
