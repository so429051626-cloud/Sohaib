<?php /* يُنفَّذ فور رسم القائمة وقبل ظهور الصفحة: يستعيد الأقسام المطوية وموضع التمرير، فلا تقفز القائمة عند كل تنقل */ ?>
<script>
(function () {
  try {
    var side = document.querySelector('.sidebar[data-nav]'), nav = side && side.querySelector('[data-nav-scroll]');
    if (!nav) { return; }
    var key = 'sn-nav-' + side.getAttribute('data-nav');
    var closed = JSON.parse(localStorage.getItem(key) || '[]');
    if (Array.isArray(closed) && closed.length) {
      side.querySelectorAll('.nav-group[data-nav-group]').forEach(function (g) {
        if (closed.indexOf(g.getAttribute('data-nav-group')) !== -1 && !g.querySelector('.nav-link.is-active')) {
          g.classList.add('is-collapsed');
          var t = g.querySelector('[data-nav-toggle]');
          if (t) { t.setAttribute('aria-expanded', 'false'); }
        }
      });
    }
    var y = sessionStorage.getItem(key + '-y');
    if (y !== null) { nav.scrollTop = parseInt(y, 10) || 0; }
  } catch (e) { /* التخزين غير متاح: تبقى القائمة بحالتها الافتراضية */ }
})();
</script>
