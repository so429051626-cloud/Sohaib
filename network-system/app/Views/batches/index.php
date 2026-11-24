<?php
/** @var App\Core\View $v */
use App\Core\Request;

$v->title = 'دفعات الكروت';
$req = Request::current();
$sources = ['generated' => ['مولّدة', 'badge-primary'], 'imported' => ['مستوردة', 'badge-violet'], 'router' => ['من جهاز MikroTik', 'badge-info']];
?>
<div class="panel">
  <form class="filters" method="get">
    <div class="field">
      <label for="category">الباقة</label>
      <select class="input" id="category" name="category" data-autosubmit>
        <option value="">الكل</option>
        <?php foreach ($categories as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($req->int('category'), $id) ?>><?= e($name) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="source">المصدر</label>
      <select class="input" id="source" name="source" data-autosubmit>
        <option value="">الكل</option>
        <?php foreach ($sources as $k => [$l]): ?><option value="<?= $k ?>"<?= selected($req->str('source'), $k) ?>><?= e($l) ?></option><?php endforeach; ?>
      </select>
    </div>
  </form>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>#</th><th>الدفعة</th><th>الباقة</th><th>المصدر</th><th class="num">الكروت</th><th class="num">المتاحة</th><th class="num">المستخدمة</th><th class="num">المطبوعة</th><th class="num">المختصرة</th><th>الإنشاء</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $b): [$sl, $sc] = $sources[$b['source']] ?? [$b['source'], 'badge']; ?>
        <tr>
          <td class="mono text-sm text-muted"><?= (int) $b['id'] ?></td>
          <td><a class="cell-title nowrap" href="<?= e(url('/batches/' . $b['id'])) ?>"><?= e($b['name']) ?></a><?= $b['notes'] ? '<div class="cell-sub">' . e($b['notes']) . '</div>' : '' ?></td>
          <td class="nowrap"><span class="swatch" style="background:<?= e($b['color']) ?>"></span> <?= e($b['category_name']) ?></td>
          <td><span class="badge <?= $sc ?>"><?= e($sl) ?></span></td>
          <td class="num"><?= num($b['cards']) ?></td>
          <td class="num"><?= num($b['available']) ?></td>
          <td class="num"><?= num($b['used']) ?></td>
          <td class="num"><?= num($b['printed']) ?></td>
          <td class="num"><?= num($b['short_codes']) ?></td>
          <td class="nowrap text-sm"><?= e(fmt_dt($b['created_at'])) ?><div class="cell-sub"><?= e($b['employee_name'] ?? '') ?></div></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="10"><div class="empty"><?= icon('boxes') ?><div class="empty-title">لا توجد دفعات</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= $pager->render($req) ?>
</div>
