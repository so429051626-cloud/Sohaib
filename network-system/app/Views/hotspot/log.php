<?php
/** @var App\Core\View $v */
/** @var App\Core\Request $request */
use App\Services\CardRules;

$v->title = 'سجل الدخول';
$v->breadcrumbs = [['ربط الهوتسبوت', can('hotspot.manage') ? '/hotspot' : null], ['سجل الدخول', null]];
$events = ['check' => 'تحقق', 'login' => 'دخول', 'logout' => 'خروج'];
$sources = ['portal' => 'صفحة الدخول', 'router' => 'جهاز MikroTik'];
?>
<div class="panel">
  <form class="filters" method="get">
    <div class="field grow"><label for="q">بحث</label><input class="input input-ltr" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="رقم الكرت، MAC، IP"></div>
    <div class="field">
      <label for="router">جهاز MikroTik</label>
      <select class="input" id="router" name="router"><option value="">الكل</option>
        <?php foreach ($routers as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($filters['router'], (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="result">النتيجة</label>
      <select class="input" id="result" name="result"><option value="">الكل</option>
        <option value="accept"<?= selected($filters['result'], 'accept') ?>>مقبول</option>
        <option value="reject"<?= selected($filters['result'], 'reject') ?>>مرفوض</option>
        <option value="info"<?= selected($filters['result'], 'info') ?>>معلومة</option>
      </select>
    </div>
    <div class="field">
      <label for="reason">السبب</label>
      <select class="input" id="reason" name="reason"><option value="">الكل</option>
        <?php foreach (CardRules::REASONS as $k => $msg): ?><option value="<?= e($k) ?>"<?= selected($filters['reason'], $k) ?>><?= e(rtrim($msg, '.')) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label for="from">من</label><input class="input" type="date" id="from" name="from" value="<?= e($filters['from']) ?>"></div>
    <div class="field"><label for="to">إلى</label><input class="input" type="date" id="to" name="to" value="<?= e($filters['to']) ?>"></div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('filter') ?>تصفية</button></div>
  </form>
  <?php if (!$rows): ?>
    <div class="empty"><?= icon('log-in') ?><div class="empty-title">لا توجد سجلات</div></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>الوقت</th><th>الحساب</th><th>الحدث</th><th>النتيجة</th><th>الجهاز</th><th>جهاز MikroTik</th><th>المصدر</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $l): ?>
        <tr>
          <td class="nowrap text-sm"><?= e(fmt_dt($l['created_at'])) ?></td>
          <td>
            <?php if ($l['card_id']): ?>
              <a class="mono" href="<?= e(url('/check/card/' . $l['card_id'])) ?>"><?= e($l['login']) ?></a>
              <?php if ($l['card_username'] && $l['card_username'] !== $l['login']): ?><div class="cell-sub mono"><?= e($l['card_username']) ?></div><?php endif; ?>
            <?php elseif ($l['subscriber_id']): ?>
              <a href="<?= e(url('/subscribers/' . $l['subscriber_id'])) ?>"><?= e($l['subscriber_name'] ?? $l['login']) ?></a><div class="cell-sub mono"><?= e($l['login']) ?></div>
            <?php else: ?>
              <span class="mono"><?= e($l['login']) ?></span>
            <?php endif; ?>
          </td>
          <td><?= e($events[$l['event']] ?? $l['event']) ?></td>
          <td>
            <?php if ($l['result'] === 'accept'): ?><span class="badge badge-success">مقبول</span>
            <?php elseif ($l['result'] === 'reject'): ?><span class="badge badge-danger">مرفوض</span>
            <?php else: ?><span class="badge badge-outline">معلومة</span><?php endif; ?>
            <?php if ($l['reason']): ?><div class="cell-sub"><?= e(rtrim(CardRules::message((string) $l['reason']), '.')) ?></div><?php endif; ?>
          </td>
          <td>
            <div class="text-sm"><?= e($l['hostname'] ?: '—') ?></div>
            <div class="mono text-sm text-muted"><?= e($l['mac'] ?? '') ?><?= $l['ip'] ? ' · ' . e($l['ip']) : '' ?></div>
          </td>
          <td><?= e($l['router_name'] ?? '—') ?></td>
          <td class="text-sm"><?= e($sources[$l['source']] ?? $l['source']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= $pager->render($request) ?>
  <?php endif; ?>
</div>
