/* الاستهلاك الزائد: نافذة تحديد السرعة لمستخدم من القائمة */
(function (document) {
  'use strict';
  var modal = document.getElementById('cap-modal');
  if (!modal || !window.SN) { return; }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-cap-open]');
    if (!b) { return; }
    e.preventDefault();
    modal.querySelector('[data-cap-kind]').value = b.getAttribute('data-kind');
    modal.querySelector('[data-cap-ref]').value = b.getAttribute('data-ref');
    modal.querySelector('[data-cap-label]').textContent = b.getAttribute('data-label') || '';
    SN.openModal(modal);
  });
})(document);
