/*
 * محرر نصوص الرسائل التلقائية (الرسائل النصية والبريد):
 *   [data-tpl-text] النص مع عدّاد [data-tpl-count] في الكتلة نفسها
 *   [data-tpl-token="{متغير}" data-tpl-target="معرّف النص"] يدرج المتغير عند المؤشر
 *   [data-tpl-default="النص" data-tpl-target="..."] يعيد النص الافتراضي (ومعه data-tpl-default-subject لحقل العنوان)
 */
(function (document) {
  'use strict';
  function count(ta) {
    var box = ta.closest('details') || ta.parentNode;
    var c = box.querySelector('[data-tpl-count]');
    if (c) { c.textContent = window.SN ? SN.fmtNum(ta.value.length) : String(ta.value.length); }
  }
  document.querySelectorAll('[data-tpl-text]').forEach(function (ta) {
    count(ta);
    ta.addEventListener('input', function () { count(ta); });
  });
  document.addEventListener('click', function (e) {
    var tok = e.target.closest('[data-tpl-token]');
    var def = e.target.closest('[data-tpl-default]');
    var btn = tok || def;
    if (!btn) { return; }
    var ta = document.getElementById(btn.getAttribute('data-tpl-target'));
    if (!ta) { return; }
    if (tok) {
      var s = ta.selectionStart || ta.value.length, t = tok.getAttribute('data-tpl-token');
      ta.value = ta.value.slice(0, s) + t + ta.value.slice(ta.selectionEnd || s);
      ta.focus();
      ta.selectionStart = ta.selectionEnd = s + t.length;
    } else {
      ta.value = def.getAttribute('data-tpl-default');
      var subject = def.getAttribute('data-tpl-default-subject');
      var field = subject !== null ? ta.closest('details').querySelector('input[name$="_subject"]') : null;
      if (field) { field.value = subject; }
    }
    count(ta);
  });
})(document);
