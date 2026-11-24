/* معالج ربط جهاز MikroTik: متابعة حية لخطوات الربط حتى يجهز الجهاز للكروت */
(function (document) {
  'use strict';
  var root = document.querySelector('[data-connect]');
  if (!root || !window.SN) { return; }
  var url = root.getAttribute('data-connect');
  var live = document.querySelector('[data-connect-live]');
  var success = document.querySelector('[data-connect-success]');
  var command = document.querySelector('[data-connect-command]');
  var delay = 3000, stopped = root.hasAttribute('data-connect-done'), timer = null, inflight = false;

  function setStep(key, step) {
    var el = document.querySelector('[data-step="' + key + '"]');
    if (!el || !step) { return; }
    el.className = 'wizard-check is-' + (step.state || 'wait');
    var d = el.querySelector('[data-detail]');
    if (d) { d.textContent = step.detail || ''; }
  }

  function finish() {
    stopped = true;
    if (live) { live.hidden = true; }
    if (success) { success.hidden = false; }
    if (command) { command.hidden = true; }
    document.querySelectorAll('.wizard-steps li').forEach(function (li) {
      li.className = 'is-done';
      var n = li.querySelector('.wizard-num');
      if (n) { n.innerHTML = SN.icon('check'); }
    });
  }

  function schedule() {
    clearTimeout(timer);
    timer = null;
    if (!stopped && !inflight && !document.hidden) { timer = setTimeout(poll, delay); }
  }

  function poll() {
    timer = null;
    if (stopped || inflight) { return; }
    inflight = true;
    SN.api(url, { silent: true }).then(function (d) {
      inflight = false;
      delay = 3000;
      var steps = d.steps || {};
      Object.keys(steps).forEach(function (k) { setStep(k, steps[k]); });
      if (d.done) { finish(); return; }
      schedule();
    }).catch(function () {
      // انقطاع مؤقت: إعادة المحاولة بتباطؤ حتى 15 ثانية
      inflight = false;
      delay = Math.min(15000, delay * 2);
      schedule();
    });
  }

  // التبويب المخفي لا يتصل بالجهاز؛ يستأنف فور العودة إليه
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { clearTimeout(timer); timer = null; } else if (!timer) { poll(); }
  });

  if (!stopped) { poll(); }
})(document);
