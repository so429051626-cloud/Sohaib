<?php
/**
 * @var App\Core\View $v @var array $routers @var int $selected @var array $history @var array $stats @var int $lastScan @var bool $canDeep @var array $links
 */
use App\Core\Settings;
use App\Services\CableDoctor;

$v->title = 'فحص الكابلات';
$v->script('js/cables.js');
$levelBadge = ['ok' => 'badge-success', 'warn' => 'badge-warning', 'bad' => 'badge-danger'];
$sources = ['manual' => ['يدوي', 'badge-outline'], 'deep' => ['عميق', 'badge-info'], 'auto' => ['دوري', 'badge-outline']];
$interval = Settings::int('cable.interval_hours', 6);
?>
<div class="stats">
  <div class="stat"><div class="stat-icon <?= $stats['bad'] ? 'is-danger' : '' ?>"><?= icon('circle-x') ?></div><div class="stat-body"><div class="stat-label">منافذ فيها خلل</div><div class="stat-value"><?= num($stats['bad']) ?></div></div></div>
  <div class="stat"><div class="stat-icon <?= $stats['warn'] ? 'is-warning' : '' ?>"><?= icon('triangle-alert') ?></div><div class="stat-body"><div class="stat-label">تحتاج انتباهاً</div><div class="stat-value"><?= num($stats['warn']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-success"><?= icon('circle-check') ?></div><div class="stat-body"><div class="stat-label">سليمة في آخر فحص</div><div class="stat-value"><?= num($stats['ok']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('scan-line') ?></div><div class="stat-body"><div class="stat-label">فحوص يدوية خلال 30 يوماً</div><div class="stat-value"><?= num($stats['checks']) ?></div></div></div>
</div>

<div class="panel cb" data-cb
     data-ports="<?= e(url('/cables/ports')) ?>" data-test="<?= e(url('/cables/test')) ?>" data-deep="<?= e(url('/cables/deep')) ?>"
     data-check="<?= e(url('/cables/checks/0')) ?>" data-can-deep="<?= $canDeep ? '1' : '0' ?>">
  <div class="panel-head cb-head-bar">
    <h3><?= icon('ethernet-port') ?>منافذ الجهاز</h3>
    <?php if ($routers): ?>
    <div class="cb-pick">
      <label class="sr-only" for="cb-router">الجهاز</label>
      <select class="input" id="cb-router" data-cb-router>
        <?php foreach ($routers as $r): ?>
        <option value="<?= (int) $r['id'] ?>"<?= selected($selected, (int) $r['id']) ?>><?= e($r['name']) ?><?= $r['status'] !== 'online' ? ' — غير متصل' : '' ?><?= !(int) $r['is_active'] ? ' — موقوف' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="btn btn-icon" data-cb-reload title="تحديث المنافذ" aria-label="تحديث المنافذ"><?= icon('refresh-cw') ?></button>
    </div>
    <?php endif; ?>
  </div>
  <div class="panel-body">
    <?php if (!$routers): ?>
      <div class="empty"><?= icon('router') ?><div class="empty-title">لا توجد أجهزة MikroTik</div><div class="empty-text">أضف جهازاً أولاً ثم افحص كابلات منافذه من هنا.</div></div>
    <?php else: ?>
      <p class="cb-lead"><?= icon('info', 'icon-sm') ?><span>اضغط على منفذ لفحصه. الفحص قراءة فقط: حالة الرابط وسرعته، تغذية PoE، عدّادات الأخطاء ومرات الانقطاع، ويقيس الأزواج ومكان القطع أو التماس بالمتر متى كان الرابط مفصولاً.</span></p>
      <div class="cb-ports" data-cb-ports aria-live="polite">
        <?php for ($i = 0; $i < 5; $i++): ?><div class="cb-port is-skeleton" aria-hidden="true"><span class="cb-jack"></span><span class="sk-line"></span><span class="sk-line is-short"></span></div><?php endfor; ?>
      </div>
      <div class="notice notice-danger" data-cb-ports-error hidden><?= icon('triangle-alert') ?><div data-cb-ports-msg></div></div>
    <?php endif; ?>
  </div>
</div>

<div class="panel cl-panel">
  <div class="panel-head"><h3><?= icon('waypoints') ?>خريطة الكابلات بين الأجهزة</h3><span class="text-muted text-sm"><?= $links ? num(count($links)) . ' كابل' : '' ?></span></div>
  <?php if (!$links): ?>
    <div class="empty"><?= icon('cable') ?><div class="empty-title">لم تُكتشف كابلات بين الأجهزة بعد</div><div class="empty-text">افحص منفذاً موصولاً بجهاز آخر، أو فعّل الفحص الدوري أدناه، فيظهر هنا كل كابل: من أي جهاز ومنفذ إلى أي جهاز ومنفذ، وطوله وحالة أزواجه.</div></div>
  <?php else: ?>
  <div class="cl-list">
    <?php foreach ($links as $l): ?>
    <button type="button" class="cl-row is-<?= e($l['level']) ?>" data-cb-open="<?= (int) $l['check'] ?>" title="عرض تفاصيل الأزواج والأسلاك">
      <span class="cl-end">
        <span class="cl-ic"><?= icon('router') ?></span>
        <span class="cl-txt"><b><?= e($l['from']['name']) ?></b><small>المنفذ <bdi class="cb-mono"><?= e($l['from']['port']) ?></bdi></small></span>
      </span>
      <span class="cl-wire" aria-hidden="true">
        <span class="cl-line"></span>
        <span class="cl-len"><?= icon('ruler', 'icon-sm') ?><?= $l['length'] !== null ? e(num($l['length'])) . ' م' : 'الطول غير مقاس' ?></span>
      </span>
      <span class="cl-end is-far">
        <span class="cl-ic <?= $l['to']['id'] ? 'is-system' : '' ?>"><?= icon($l['to']['kind'] === 'host' ? 'monitor-smartphone' : 'router') ?></span>
        <span class="cl-txt"><b><?= e($l['to']['name'] !== '' ? $l['to']['name'] : 'جهاز غير معروف') ?></b><small><?= $l['to']['port'] !== '' ? 'المنفذ <bdi class="cb-mono">' . e($l['to']['port']) . '</bdi>' : e($l['to']['model'] !== '' ? $l['to']['model'] : ($l['to']['address'] !== '' ? $l['to']['address'] : '—')) ?><?= !$l['to']['id'] && $l['to']['kind'] === 'neighbor' ? ' · خارج النظام' : '' ?></small></span>
      </span>
      <span class="cl-state">
        <span class="badge <?= e($levelBadge[$l['level']] ?? 'badge-outline') ?>"><?= e(CableDoctor::LEVELS[$l['level']] ?? $l['level']) ?></span>
        <?php if ($l['pairs']): ?>
        <span class="cl-pairs">
          <?php foreach ($l['pairs'] as $pr): ?>
          <span class="cl-pair is-<?= e($pr['cls']) ?>" title="<?= e('الزوج ' . $pr['no'] . ' (' . $pr['color'] . '): ' . $pr['text'] . ($pr['m'] !== null && $pr['cls'] !== 'ok' ? ' على بعد ' . $pr['m'] . ' م' : '')) ?>"><i style="background:<?= e($pr['hex']) ?>"></i><?= (int) $pr['no'] ?><?= $pr['cls'] === 'ok' ? '' : ' · ' . e($pr['state'] === 'short' ? 'تماس' : ($pr['state'] === 'open' ? 'قطع' : $pr['text'])) . ($pr['m'] !== null && in_array($pr['state'], ['short', 'open'], true) ? ' ' . (int) $pr['m'] . 'م' : '') ?></span>
          <?php endforeach; ?>
        </span>
        <?php endif; ?>
        <small class="cl-sum"><?= e($l['level'] === 'ok' ? 'آخر فحص ' . time_ago($l['at']) : $l['summary']) ?></small>
      </span>
    </button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="cb-grid">
  <div class="panel">
    <div class="panel-head"><h3><?= icon('history') ?>سجل الفحوص</h3><span class="text-muted text-sm">اليدوية والعميقة، والدورية التي وجدت شيئاً</span></div>
    <div class="cb-hist">
      <?php foreach ($history as $h): [$srcLabel, $srcBadge] = $sources[$h['source']] ?? ['', 'badge-outline']; ?>
      <button type="button" class="cb-hist-row is-<?= e($h['level']) ?>" data-cb-open="<?= (int) $h['id'] ?>">
        <span class="cb-hist-dot" aria-hidden="true"></span>
        <span class="cb-hist-main">
          <span class="cb-hist-title"><bdi class="cb-mono"><?= e($h['port']) ?></bdi> · <?= e($h['router_name']) ?></span>
          <span class="cb-hist-sum"><?= e($h['summary']) ?></span>
        </span>
        <span class="cb-hist-end">
          <span class="badge <?= $levelBadge[$h['level']] ?? '' ?>"><?= e(CableDoctor::LEVELS[$h['level']] ?? $h['level']) ?></span>
          <span class="badge <?= $srcBadge ?>"><?= e($srcLabel) ?></span>
          <?php if ($h['length_m'] !== null): ?><span class="cb-len-chip"><?= icon('ruler', 'icon-sm') ?><?= num((int) $h['length_m']) ?> م</span><?php endif; ?>
          <span class="text-muted text-sm" title="<?= e(fmt_dt($h['created_at'])) ?>"><?= e(time_ago($h['created_at'])) ?></span>
        </span>
      </button>
      <?php endforeach; ?>
      <?php if (!$history): ?><div class="empty"><?= icon('cable') ?><div class="empty-title">لا توجد فحوص بعد</div><div class="empty-text">نتائج الفحص تُحفظ هنا لتقارن بها الفحوص التالية.</div></div><?php endif; ?>
    </div>
  </div>

  <form class="panel" method="post" action="<?= e(url('/cables/settings')) ?>">
    <?= csrf_field() ?>
    <div class="panel-head"><h3><?= icon('timer') ?>الفحص الدوري</h3><span class="badge <?= Settings::bool('cable.auto') ? 'badge-success' : 'badge-outline' ?>"><?= Settings::bool('cable.auto') ? 'يعمل' : 'مطفأ' ?></span></div>
    <div class="panel-body stack" style="gap:14px">
      <label class="switch"><input type="checkbox" name="auto" value="1"<?= checked(Settings::bool('cable.auto')) ?>><span class="track"></span><span>فحص منافذ كل الأجهزة المتصلة تلقائياً، وتنبيه على تيليجرام عند خلل</span></label>
      <div class="form-grid" data-show-when="auto=1">
        <div class="field col-12"><label for="cb-int">كل</label>
          <select class="input" id="cb-int" name="interval_hours">
            <?php foreach ([1 => 'ساعة', 2 => 'ساعتين', 3 => '3 ساعات', 6 => '6 ساعات', 12 => '12 ساعة', 24 => 'يوم'] as $h => $label): ?><option value="<?= $h ?>"<?= selected($interval, $h) ?>><?= e($label) ?></option><?php endforeach; ?>
            <?php if (!in_array($interval, [1, 2, 3, 6, 12, 24], true)): ?><option value="<?= $interval ?>" selected><?= $interval ?> ساعة</option><?php endif; ?>
          </select>
        </div>
        <div class="field col-6"><label for="cb-err">خلل إذا زادت الأخطاء</label><div class="input-group"><input class="input input-ltr" id="cb-err" name="errors_min" inputmode="numeric" value="<?= e(old('errors_min', (string) Settings::int('cable.errors_min', 20))) ?>"><span class="input-addon">خطأ</span></div><?= error_html('errors_min') ?></div>
        <div class="field col-6"><label for="cb-dn">ملاحظة إذا انقطع منذ التشغيل</label><div class="input-group"><input class="input input-ltr" id="cb-dn" name="downs_min" inputmode="numeric" value="<?= e(old('downs_min', (string) Settings::int('cable.downs_min', 5))) ?>"><span class="input-addon">مرات</span></div><?= error_html('downs_min') ?></div>
      </div>
      <ul class="cb-how">
        <li>يقرأ كل منفذ يعمل: السرعة والازدواج وتغذية PoE وعدّادات الأخطاء ومرات الانقطاع، ويقارنها بآخر فحص.</li>
        <li>منفذ كان يعمل وانقطع رابطه: يقيس أزواجه ليحدد مكان القطع أو التماس بالمتر.</li>
        <li>لا يفصل أي منفذ ولا يغيّر شيئاً على الجهاز. التنبيه مرة في اليوم لكل منفذ وسبب<?= Settings::bool('notify.telegram_enabled') ? '' : '، ويصل إلى تيليجرام بعد تفعيل إشعاراته من الإعدادات' ?>.</li>
      </ul>
      <?php if ($lastScan > 0): ?><div class="text-muted text-sm"><?= icon('history', 'icon-sm') ?> آخر فحص دوري: <?= e(time_ago(date('Y-m-d H:i:s', $lastScan))) ?></div><?php endif; ?>
    </div>
    <div class="panel-foot"><button class="btn btn-primary"><?= icon('save') ?>حفظ</button></div>
  </form>
</div>

<dialog class="modal modal-lg cb-modal" id="m-cable" aria-labelledby="m-cable-title">
  <div class="modal-head"><h3 id="m-cable-title"><?= icon('cable') ?><span data-cb-title>فحص المنفذ</span></h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close aria-label="إغلاق"><?= icon('x') ?></button></div>
  <div class="modal-body">
    <div class="cb-loading" data-cb-loading>
      <div class="cb-pulse" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
      <b data-cb-loading-title>يقرأ حالة المنفذ</b>
      <span class="text-muted text-sm" data-cb-loading-note>حالة الرابط والسرعة وعدّادات الأخطاء ومرات الانقطاع · قراءة فقط</span>
    </div>
    <div class="notice notice-danger" data-cb-error hidden><?= icon('triangle-alert') ?><div data-cb-error-msg></div></div>
    <div class="cb-result" data-cb-result hidden></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-modal-close>إغلاق</button><button type="button" class="btn btn-primary" data-cb-again hidden><?= icon('refresh-cw') ?>فحص من جديد</button></div>
</dialog>
