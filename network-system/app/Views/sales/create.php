<?php
/** @var App\Core\View $v */
/** @var App\Core\Request $request */
$v->title = 'عملية بيع';
$v->breadcrumbs = [['المبيعات', '/sales'], ['عملية بيع', null]];
$fromSelection = $request->str('source') === 'selection' && $selection;
$type = (string) old('type', $request->int('distributor') ? 'distributor' : 'direct');
$catId = (int) old('category_id', $selCategory ?? ($request->int('category') ?: ($categories[0]['id'] ?? 0)));
?>
<?php if (!$categories): ?>
  <div class="panel"><div class="empty"><?= icon('layers') ?><div class="empty-title">لا توجد باقات فعّالة</div></div></div>
<?php elseif ($request->str('source') === 'selection' && count($selection) !== 1): ?>
  <div class="panel"><div class="empty"><?= icon('circle-alert') ?>
    <div class="empty-title"><?= $selection ? 'الكروت المحددة من أكثر من باقة' : 'لا توجد كروت متاحة للبيع ضمن التحديد' ?></div>
    <?php if ($selection): ?><div class="text-muted text-sm"><?php foreach ($selection as $s): ?><?= e($s['name']) ?>: <?= num($s['n']) ?> · <?php endforeach; ?></div><?php endif; ?>
    <a class="btn" href="<?= e(url('/cards')) ?>"><?= icon('chevron-right') ?>الكروت</a>
  </div></div>
<?php else: ?>
<form method="post" action="<?= e(url('/sales')) ?>" id="sale-form">
  <?= csrf_field() ?>
  <?php if ($fromSelection): ?><input type="hidden" name="source" value="selection"><input type="hidden" name="category_id" value="<?= (int) $selCategory ?>"><?php endif; ?>
  <div class="grid grid-main-side">
    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h2><?= icon('shopping-cart') ?>تفاصيل البيع</h2></div>
        <div class="panel-body">
          <div class="form-grid">
            <div class="field col-12">
              <label>نوع البيع</label>
              <div class="segmented">
                <label><input type="radio" name="type" value="direct"<?= checked($type === 'direct') ?>><span>بيع مباشر</span></label>
                <label><input type="radio" name="type" value="distributor"<?= checked($type === 'distributor') ?>><span>لموزع</span></label>
              </div>
            </div>
            <div class="field col-6" data-show-when="type=distributor">
              <label for="distributor_id">الموزع <span class="req">*</span></label>
              <select class="input" id="distributor_id" name="distributor_id">
                <option value="">—</option>
                <?php foreach ($distributors as $d): ?>
                  <option value="<?= (int) $d['id'] ?>" data-discount="<?= e((string) (float) $d['discount_percent']) ?>"<?= selected((string) old('distributor_id', $request->int('distributor') ?: ''), (string) $d['id']) ?>><?= e($d['name']) ?><?= (float) $d['balance'] > 0 ? ' · عليه ' . e(money($d['balance'])) : '' ?></option>
                <?php endforeach; ?>
              </select>
              <?= error_html('distributor_id') ?>
            </div>
            <div class="field col-6" data-show-when="type=direct">
              <label for="customer_name">اسم الزبون</label>
              <input class="input" id="customer_name" name="customer_name" value="<?= e(old('customer_name')) ?>" placeholder="اختياري">
            </div>

            <?php if ($fromSelection): ?>
              <div class="field col-12">
                <div class="notice" style="margin:0"><?= icon('list-checks') ?><div><strong><?= num($selection[0]['n']) ?></strong> كرت محدد من باقة «<?= e($selection[0]['name']) ?>»</div></div>
              </div>
            <?php else: ?>
              <div class="field col-12">
                <label>الباقة <span class="req">*</span></label>
                <div class="pkg-grid">
                  <?php foreach ($categories as $c): ?>
                    <label class="pkg<?= (int) $c['available'] === 0 ? ' is-empty' : '' ?>">
                      <input type="radio" name="category_id" value="<?= (int) $c['id'] ?>" data-price="<?= e((string) (float) $c['price']) ?>" data-available="<?= (int) $c['available'] ?>"<?= checked($catId === (int) $c['id']) ?><?= (int) $c['available'] === 0 ? ' disabled' : '' ?>>
                      <span class="pkg-card">
                        <span class="pkg-head"><span class="swatch" style="background:<?= e($c['color']) ?>"></span><strong class="pkg-name"><?= e($c['name']) ?></strong><span class="pkg-price"><?= e(money($c['price'])) ?></span></span>
                        <span class="pkg-meta"><span class="<?= (int) $c['available'] === 0 ? 'is-danger' : '' ?>">المتاح: <?= num($c['available']) ?></span></span>
                      </span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <?= error_html('category_id') ?>
              </div>
              <div class="field col-4">
                <label for="quantity">الكمية <span class="req">*</span></label>
                <input class="input" id="quantity" name="quantity" value="<?= e(old('quantity', '10')) ?>" inputmode="numeric">
                <?= error_html('quantity') ?>
              </div>
            <?php endif; ?>
            <div class="field col-4">
              <label for="unit_price">سعر الوحدة</label>
              <input class="input" id="unit_price" name="unit_price" value="<?= e(old('unit_price')) ?>" inputmode="decimal" placeholder="سعر الباقة">
              <?= error_html('unit_price') ?>
            </div>
            <div class="field col-4">
              <label for="discount">الخصم %</label>
              <input class="input" id="discount" name="discount" value="<?= e(old('discount', '0')) ?>" inputmode="decimal">
              <?= error_html('discount') ?>
            </div>
            <div class="field col-4" data-show-when="type=distributor">
              <label for="paid">المدفوع الآن</label>
              <input class="input" id="paid" name="paid" value="<?= e(old('paid', '0')) ?>" inputmode="decimal">
            </div>
            <div class="field col-12">
              <label for="notes">ملاحظات</label>
              <input class="input" id="notes" name="notes" value="<?= e(old('notes')) ?>">
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h3><?= icon('receipt') ?>الملخص</h3></div>
        <div class="panel-body">
          <dl class="kv">
            <dt>الكمية</dt><dd data-sum="qty">—</dd>
            <dt>سعر الوحدة</dt><dd data-sum="price">—</dd>
            <dt>بعد الخصم</dt><dd data-sum="net">—</dd>
            <dt>الإجمالي</dt><dd><strong data-sum="total" style="font-size:17px">—</strong></dd>
          </dl>
        </div>
      </div>
      <div class="panel"><div class="panel-body flex gap-2">
        <button class="btn btn-primary"><?= icon('check') ?>تأكيد البيع</button>
        <a class="btn" href="<?= e(url('/sales')) ?>">إلغاء</a>
      </div></div>
    </div>
  </div>
</form>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('sale-form');
  var fixedQty = <?= $fromSelection ? (int) $selection[0]['n'] : 'null' ?>;
  var fixedPrice = <?= $fromSelection ? json_encode((float) (array_values(array_filter($categories, static fn ($c) => (int) $c['id'] === (int) $selCategory))[0]['price'] ?? 0)) : 'null' ?>;
  function num(v) { var n = parseFloat(String(v || '').replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); })); return isFinite(n) ? n : null; }
  var currency = <?= json_encode((string) \App\Core\Settings::get('general.currency', '₪'), JSON_UNESCAPED_UNICODE) ?>;
  function money(n) {
    var r = Math.round(n * 100) / 100;
    return '⁦' + r.toLocaleString('en-US', { minimumFractionDigits: r % 1 ? 2 : 0, maximumFractionDigits: 2 }) + (currency ? ' ' + currency : '') + '⁩';
  }
  var touchedDiscount = false;
  form.discount.addEventListener('input', function () { touchedDiscount = true; });
  function update() {
    var cat = form.querySelector('input[name="category_id"]:checked');
    var qty = fixedQty !== null ? fixedQty : num(form.quantity && form.quantity.value);
    var base = fixedPrice !== null ? fixedPrice : (cat ? num(cat.getAttribute('data-price')) : null);
    var price = num(form.unit_price.value);
    if (price === null) { price = base; }
    var type = form.querySelector('input[name="type"]:checked').value;
    if (type === 'distributor' && !touchedDiscount && form.unit_price.value === '') {
      var opt = form.distributor_id.options[form.distributor_id.selectedIndex];
      form.discount.value = opt && opt.getAttribute('data-discount') ? opt.getAttribute('data-discount') : '0';
    }
    var disc = Math.max(0, Math.min(100, num(form.discount.value) || 0));
    var net = price !== null ? price * (1 - disc / 100) : null;
    var set = function (k, v) { form.querySelector('[data-sum="' + k + '"]').textContent = v; };
    set('qty', qty !== null ? SN.fmtNum(qty) : '—');
    set('price', price !== null ? money(price) : '—');
    set('net', net !== null ? money(net) : '—');
    set('total', net !== null && qty !== null ? money(net * qty) : '—');
  }
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  update();
});
</script>
<?php endif; ?>
