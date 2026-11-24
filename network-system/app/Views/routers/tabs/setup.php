<?php
/** @var App\Core\View $v */
use App\Core\Settings;

$rid = (int) $router['id'];
?>
<div class="panel-body">
  <div class="grid grid-main-side">
    <div class="stack">
      <div class="field">
        <label>سكربت تجهيز جهاز MikroTik</label>
        <div class="copyable"><pre class="code-block" id="setup-script"><?= e($script) ?></pre><button type="button" class="btn btn-sm copy-btn" data-copy-target="#setup-script"><?= icon('copy') ?>نسخ</button></div>
      </div>
    </div>
    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h3><?= icon('key-round') ?>مفتاح ربط جهاز MikroTik</h3></div>
        <div class="panel-body">
          <div class="input-group mb-2">
            <input class="input input-ltr" value="<?= e($router['router_key']) ?>" readonly id="rkey">
            <button type="button" class="btn" data-copy-target="#rkey"><?= icon('copy') ?></button>
          </div>
          <form method="post" action="<?= e(url('/routers/' . $rid . '/key')) ?>" data-confirm="تجديد المفتاح يوقف ربط صفحة الهوتسبوت الحالية حتى تُنشر الملفات مجدداً. متابعة؟">
            <?= csrf_field() ?><button class="btn btn-soft-danger btn-sm"><?= icon('refresh-cw') ?>تجديد المفتاح</button>
          </form>
        </div>
      </div>
      <div class="panel">
        <div class="panel-head"><h3><?= icon('wifi') ?>ملفات ربط الهوتسبوت</h3></div>
        <div class="panel-body stack" style="gap:10px">
          <dl class="kv">
            <dt>رابط النظام</dt><dd><span class="mono text-sm"><?= e(Settings::panelUrl() ?: '—') ?></span></dd>
            <dt>مجلد الصفحات</dt><dd><span class="mono"><?= e($router['hotspot_dir'] ?: '—') ?></span></dd>
          </dl>
          <form method="post" action="<?= e(url('/routers/' . $rid . '/action')) ?>">
            <?= csrf_field() ?><input type="hidden" name="tab" value="setup"><input type="hidden" name="op" value="deploy_sdk">
            <button class="btn btn-primary btn-block"><?= icon('cloud-upload') ?>نشر الملفات على جهاز MikroTik</button>
          </form>
          <a class="btn btn-block" href="<?= e(url('/hotspot/download/' . $rid)) ?>"><?= icon('download') ?>تنزيل الملفات</a>
        </div>
      </div>
    </div>
  </div>
</div>
