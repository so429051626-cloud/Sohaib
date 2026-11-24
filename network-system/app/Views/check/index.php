<?php
/** @var App\Core\View $v */
use App\Services\CardRules;

$v->title = 'فحص الكرت';
?>
<div class="panel mb-3">
  <div class="panel-body">
    <form method="get" class="flex gap-2 wrap">
      <input class="input input-ltr" name="q" value="<?= e($q) ?>" placeholder="رقم الكرت، الرقم المختصر، الرقم التسلسلي، MAC" style="flex:1;min-width:220px;height:48px;font-size:17px" autofocus inputmode="text">
      <button class="btn btn-primary btn-lg"><?= icon('scan-line') ?>فحص</button>
    </form>
  </div>
</div>

<?php if ($q !== '' && !$matches): ?>
  <div class="panel"><div class="empty"><?= icon('circle-help') ?><div class="empty-title">لا يوجد كرت مطابق</div><div class="mono"><?= e($q) ?></div></div></div>
<?php elseif ($matches): ?>
  <div class="panel mb-3">
    <div class="panel-head"><h3><?= icon('list') ?>نتائج مطابقة (<?= num(count($matches)) ?>)</h3></div>
    <ul class="list">
      <?php foreach ($matches as $m): ?>
        <li class="list-item">
          <span class="list-icon is-primary"><?= icon('ticket') ?></span>
          <div class="list-item-main">
            <a class="list-item-title mono" href="<?= e(url('/check/card/' . $m['id'])) ?>"><?= e($m['username']) ?></a>
            <div class="list-item-sub"><?= e($m['category_name']) ?><?= $m['short_code'] ? ' · ' . e($m['short_code']) : '' ?></div>
          </div>
          <div class="list-item-end"><?= CardRules::statusBadge($m) ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head"><h3><?= icon('history') ?>آخر محاولات الدخول من صفحة الهوتسبوت</h3></div>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>الرقم</th><th>النتيجة</th><th>السبب</th><th>جهاز MikroTik</th><th>الوقت</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td><?= $r['card_id'] ? '<a class="mono" href="' . e(url('/check/card/' . $r['card_id'])) . '">' . e($r['login']) . '</a>' : '<span class="mono">' . e($r['login']) . '</span>' ?></td>
          <td><?= $r['result'] === 'accept' ? '<span class="badge badge-success">مقبول</span>' : '<span class="badge badge-danger">مرفوض</span>' ?></td>
          <td class="text-sm"><?= $r['result'] === 'reject' ? e(CardRules::message((string) $r['reason'])) : ($r['reason'] === 'short' ? 'بالرقم المختصر' : '—') ?></td>
          <td><?= e($r['router_name'] ?? '—') ?></td>
          <td class="nowrap text-sm"><?= e(time_ago($r['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recent): ?><tr><td colspan="5"><div class="empty"><?= icon('history') ?><div>لا توجد محاولات بعد.</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
