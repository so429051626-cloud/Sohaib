<?php
/**
 * معالج ربط جهاز MikroTik — الخطوتان 2 و3: الأمر الواحد والمتابعة الحية.
 * @var App\Core\View $v @var array $router @var array $state @var ?string $command @var ?string $script @var ?string $scriptError @var bool $tunnel
 */
$rid = (int) $router['id'];
$ready = !empty($state['ready_at']);
$v->title = 'ربط ' . $router['name'];
$v->breadcrumbs = [['أجهزة MikroTik', '/routers'], [$router['name'], '/routers/' . $rid], ['الربط', null]];
$v->script('js/connect.js');
$steps = array_filter([
    'fetched' => ['استلام الجهاز لإعدادات الربط', 'cloud-download'],
    'tunnel'  => $tunnel ? ['النفق الآمن متصل', 'waypoints'] : null,
    'api'     => ['الجهاز متصل بالنظام', 'router'],
    'cards'   => ['الكروت جاهزة (الهوتسبوت وملفات السرعة)', 'ticket'],
]);
?>
<ol class="wizard-steps" aria-label="خطوات الربط">
  <li class="is-done"><span class="wizard-num"><?= icon('check') ?></span><span>اسم الجهاز وطريقة الاتصال</span></li>
  <li class="<?= $ready ? 'is-done' : 'is-current' ?>"><span class="wizard-num"><?= $ready ? icon('check') : '2' ?></span><span>أمر واحد في Terminal</span></li>
  <li class="<?= $ready ? 'is-done' : 'is-current' ?>"><span class="wizard-num"><?= $ready ? icon('check') : '3' ?></span><span>متابعة حية حتى يجهز</span></li>
</ol>

<div class="grid grid-main-side" data-connect="<?= e(url('/routers/connect/' . $rid . '/status')) ?>"<?= $ready ? ' data-connect-done' : '' ?>>
  <div class="stack">
    <div class="panel wizard-card"<?= $ready ? ' hidden' : '' ?> data-connect-command>
      <div class="panel-head"><h2><span class="wizard-badge">2</span>الصق هذا الأمر في Terminal جهاز MikroTik</h2></div>
      <div class="panel-body stack" style="gap:14px">
        <?php if ($command !== null): ?>
          <ol class="wizard-howto">
            <li>افتح <b>Winbox</b> واتصل بالجهاز</li>
            <li>اضغط <b>New Terminal</b></li>
            <li>الصق الأمر واضغط <b>Enter</b></li>
          </ol>
          <div class="copyable"><pre class="code-block is-wrap wizard-command" id="connect-command" dir="ltr"><?= e($command) ?></pre><button type="button" class="btn btn-sm btn-primary copy-btn" data-copy-target="#connect-command"><?= icon('copy') ?>نسخ الأمر</button></div>
          <div class="text-sm text-muted"><?= icon('clock', 'icon-sm') ?> الأمر صالح حتى <?= ltr(e(date('Y-m-d H:i', (int) ($state['expires'] ?? time())))) ?></div>
        <?php else: ?>
          <div class="notice notice-warning" style="margin:0"><?= icon('clock') ?><div><div class="notice-title">انتهت صلاحية أمر الربط</div></div></div>
          <form method="post" action="<?= e(url('/routers/connect/' . $rid . '/token')) ?>"><?= csrf_field() ?><button class="btn btn-primary"><?= icon('refresh-cw') ?>إنشاء أمر جديد</button></form>
        <?php endif; ?>
        <?php if ($script !== null): ?>
        <details class="wizard-more">
          <summary>طرق أخرى: السكربت الكامل أو تنزيله ملفاً</summary>
          <div class="stack mt-2" style="gap:10px">
            <div class="copyable"><pre class="code-block is-wrap" id="connect-script" dir="ltr"><?= e($script) ?></pre><button type="button" class="btn btn-sm copy-btn" data-copy-target="#connect-script"><?= icon('copy') ?>نسخ</button></div>
            <a class="btn" href="<?= e(url('/routers/connect/' . $rid . '/script')) ?>" data-no-loading><?= icon('download') ?>تنزيل <?= e(\App\Services\RouterConnect::FILE) ?></a>
          </div>
        </details>
        <?php elseif ($scriptError !== null): ?>
          <div class="notice notice-danger" style="margin:0"><?= icon('circle-x') ?><div><?= e($scriptError) ?></div></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel wizard-done" data-connect-success<?= $ready ? '' : ' hidden' ?>>
      <div class="panel-body">
        <span class="wizard-done-icon"><?= icon('circle-check') ?></span>
        <div class="wizard-done-title">تم ربط <?= e($router['name']) ?> وأصبح جاهزاً للكروت</div>
        <div class="wizard-done-actions">
          <a class="btn btn-primary" href="<?= e(url('/routers/' . $rid)) ?>"><?= icon('router') ?>فتح الجهاز</a>
          <?php if (can('cards.generate')): ?><a class="btn" href="<?= e(url('/cards/generate')) ?>"><?= icon('wand-sparkles') ?>توليد كروت</a><?php endif; ?>
          <?php if (can('cards.accounts')): ?><a class="btn" href="<?= e(url('/cards/accounts/create')) ?>"><?= icon('user-plus') ?>حساب خاص</a><?php endif; ?>
          <?php if (can('hotspot.manage')): ?><a class="btn" href="<?= e(url('/hotspot')) ?>"><?= icon('wifi') ?>ربط صفحة الهوتسبوت</a><?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="panel wizard-card">
      <div class="panel-head"><h3><span class="wizard-badge">3</span>المتابعة الحية</h3><span class="spinner wizard-live" data-connect-live<?= $ready ? ' hidden' : '' ?> aria-hidden="true"></span></div>
      <ul class="wizard-checks" aria-live="polite">
        <?php foreach ($steps as $key => [$label, $ic]): ?>
        <li class="wizard-check is-<?= $ready ? 'done' : 'wait' ?>" data-step="<?= e($key) ?>">
          <span class="wizard-check-state">
            <?= icon($ic, 'wizard-i-wait') ?><span class="spinner wizard-i-work"></span><?= icon('circle-check', 'wizard-i-done') ?><?= icon('triangle-alert', 'wizard-i-error') ?>
          </span>
          <span class="wizard-check-body"><b><?= e($label) ?></b><span class="wizard-check-detail" data-detail></span></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <div class="panel-body wizard-meta">
        <dl class="kv">
          <dt>الطريقة</dt><dd><?= $tunnel ? 'نفق آمن' : 'عنوان عام' ?></dd>
          <dt>العنوان</dt><dd class="mono"><?= e($router['host']) ?>:<?= (int) $router['api_port'] ?></dd>
          <dt>مستخدم API</dt><dd class="mono"><?= e($router['api_username']) ?></dd>
        </dl>
      </div>
    </div>
  </div>
</div>
