<?php
/**
 * المشتركون المرتبطون ببوت الزبائن.
 * @var App\Core\View $v
 * @var App\Core\Request $request
 */
use App\Services\SubscriberRules;

$v->title = 'المشتركون المرتبطون';
$v->breadcrumbs = [['بوت تيليجرام', '/telegram'], ['المشتركون المرتبطون', null]];
?>
<div class="panel">
  <form class="filters" method="get">
    <div class="field grow"><label for="q">بحث</label><input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="الاسم أو اسم المستخدم أو الهاتف أو معرّف المحادثة"></div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('search') ?>بحث</button></div>
  </form>
  <?php if (!$rows): ?>
    <div class="empty"><?= icon('link') ?><div class="empty-title">لا يوجد مشتركون مرتبطون</div></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>المشترك</th><th>الباقة</th><th>الحالة</th><th>ينتهي</th><th>معرّف المحادثة</th><th>تاريخ الربط</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><a class="cell-title" href="<?= e(url('/subscribers/' . $r['subscriber_id'])) ?>"><?= e($r['full_name']) ?></a><div class="cell-sub mono"><?= e($r['username']) ?><?= $r['phone'] ? ' · ' . e($r['phone']) : '' ?></div></td>
          <td class="text-sm"><?= e($r['plan']) ?></td>
          <td><?= SubscriberRules::badge($r) ?></td>
          <td class="nowrap text-sm"><?= e(fmt_dt($r['expires_at'])) ?></td>
          <td class="mono text-sm"><?= e($r['chat_id']) ?></td>
          <td class="nowrap text-sm"><?= e(fmt_dt($r['linked_at'])) ?></td>
          <td class="text-end">
            <form method="post" action="<?= e(url('/telegram/links/' . $r['id'] . '/delete')) ?>" data-confirm="إلغاء ربط <?= e($r['full_name']) ?> بالبوت؟">
              <?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm text-danger" title="إلغاء الربط"><?= icon('x') ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= $pager->render($request) ?>
  <?php endif; ?>
</div>
