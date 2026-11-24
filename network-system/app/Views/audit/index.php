<?php
/** @var App\Core\View $v */
use App\Core\Audit;
use App\Core\Request;

$v->title = 'سجل العمليات';
$v->subtitle = 'كل ما ينفّذه الموظفون في النظام';
$req = Request::current();
?>
<div class="panel">
  <form class="filters" method="get">
    <div class="field grow">
      <label for="q">بحث</label>
      <input class="input" id="q" name="q" value="<?= e($req->str('q')) ?>" placeholder="نص العملية أو العنوان">
    </div>
    <div class="field">
      <label for="employee">الموظف</label>
      <select class="input" id="employee" name="employee">
        <option value="">الكل</option>
        <?php foreach ($employees as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($req->int('employee'), $id) ?>><?= e($name) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="action">نوع العملية</label>
      <select class="input" id="action" name="action">
        <option value="">الكل</option>
        <?php foreach (Audit::ACTIONS as $k => $label): ?><option value="<?= e($k) ?>"<?= selected($req->str('action'), $k) ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="from">من</label>
      <input class="input" type="date" id="from" name="from" value="<?= e($req->str('from')) ?>">
    </div>
    <div class="field">
      <label for="to">إلى</label>
      <input class="input" type="date" id="to" name="to" value="<?= e($req->str('to')) ?>">
    </div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('filter') ?>تصفية</button></div>
  </form>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>الوقت</th><th>الموظف</th><th>العملية</th><th>التفاصيل</th><th>العنوان</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap"><?= e(fmt_dt($r['created_at'], true)) ?></td>
          <td><?= e($r['employee_name'] ?? 'النظام') ?></td>
          <td class="nowrap"><span class="badge <?= str_contains($r['action'], 'delete') || $r['action'] === 'auth.failed' ? 'badge-danger' : (str_contains($r['action'], 'create') || str_contains($r['action'], 'generate') ? 'badge-success' : 'badge-primary') ?>"><?= e(Audit::label($r['action'])) ?></span></td>
          <td>
            <?= e($r['summary']) ?>
            <?php if ($r['details']): ?>
              <details class="text-sm text-muted"><summary style="cursor:pointer">عرض البيانات</summary><pre class="code-block mt-1" style="max-height:200px"><?= e(json_encode(json_decode($r['details'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details>
            <?php endif; ?>
          </td>
          <td><span class="mono text-sm"><?= e($r['ip']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="5"><div class="empty"><?= icon('clipboard-list') ?><div class="empty-title">لا توجد عمليات مطابقة</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= $pager->render($req) ?>
</div>
