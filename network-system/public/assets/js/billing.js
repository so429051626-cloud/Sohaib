/* صفحة الاشتراك: اختيار الباقة يملأ المبلغ ويعرض المدة */
(function (document) {
  'use strict';
  var form = document.querySelector('[data-billing-form]');
  if (!form) { return; }
  var picks = document.querySelectorAll('input[name=plan_pick]');
  var amount = form.elements.amount, periods = form.elements.periods, planId = form.elements.plan_id;
  var summary = form.querySelector('[data-billing-summary]');
  var touched = amount.value !== '';

  function current() {
    for (var i = 0; i < picks.length; i++) { if (picks[i].checked) { return picks[i]; } }
    return picks[0] || null;
  }
  function sync(keepAmount) {
    var p = current();
    if (!p) { return; }
    p.checked = true;
    planId.value = p.value;
    var n = parseInt(periods.value, 10) || 1;
    var total = Math.round(parseFloat(p.getAttribute('data-price')) * n * 100) / 100;
    if (!keepAmount) { amount.value = total; }
    var months = (parseInt(p.getAttribute('data-months'), 10) || 1) * n;
    summary.textContent = months + ' شهر · ' + total;
  }
  picks.forEach(function (p) { p.addEventListener('change', function () { touched = false; sync(false); }); });
  periods.addEventListener('change', function () { sync(touched); });
  amount.addEventListener('input', function () { touched = true; });
  sync(touched);
})(document);
