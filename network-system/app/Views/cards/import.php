<?php
/** @var App\Core\View $v */
$v->title = 'استيراد كروت';
$v->breadcrumbs = [['الكروت', '/cards'], ['استيراد كروت', null]];
?>
<?= $v->partial('cards-nav') ?>
<form method="post" action="<?= e(url('/cards/import')) ?>" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="grid grid-main-side">
    <div class="panel">
      <div class="panel-head"><h2><?= icon('file-up') ?>مصدر الكروت</h2></div>
      <div class="panel-body">
        <div class="field mb-3">
          <label for="file">ملف Excel أو CSV أو نص</label>
          <input class="input" id="file" type="file" name="file" accept=".xlsx,.csv,.txt,.tsv" style="padding-top:7px">
          <?= error_html('file') ?>
        </div>
        <div class="divider-text">أو</div>
        <div class="field">
          <label for="paste">لصق البيانات مباشرة</label>
          <textarea class="input input-ltr" id="paste" name="paste" rows="12" placeholder="123456789012 654321&#10;123456789013 778899"><?= e(old('paste')) ?></textarea>
        </div>
        <div class="form-actions"><button class="btn btn-primary"><?= icon('chevron-left') ?>متابعة إلى المعاينة</button></div>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('info') ?>الصيغ المدعومة</h3></div>
      <div class="panel-body">
        <ul class="list">
          <li class="list-item"><span class="list-icon is-success"><?= icon('file-spreadsheet') ?></span><div class="list-item-main"><div class="list-item-title">Excel (XLSX)</div></div></li>
          <li class="list-item"><span class="list-icon is-info"><?= icon('file-text') ?></span><div class="list-item-main"><div class="list-item-title">CSV / TSV</div></div></li>
          <li class="list-item"><span class="list-icon"><?= icon('type') ?></span><div class="list-item-main"><div class="list-item-title">نص</div></div></li>
        </ul>
      </div>
    </div>
  </div>
</form>
