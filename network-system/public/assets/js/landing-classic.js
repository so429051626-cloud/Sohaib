/* صفحة الهبوط (التصميم الكلاسيكي): تحسينات فقط. الصفحة تعمل كاملة بدونه ولا يُخفى أي محتوى. */
(function (window, document) {
  'use strict';
  var root = document.documentElement;
  root.classList.add('lp-js');

  var nav = document.querySelector('[data-lp-nav]');
  function onScroll() { if (nav) { nav.classList.toggle('is-scrolled', window.scrollY > 12); } }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  // قائمة الجوال: تُغلق بعد اختيار قسم أو النقر خارجها أو بمفتاح Escape
  var menu = document.querySelector('[data-lp-menu]');
  if (menu) {
    menu.addEventListener('click', function (e) { if (e.target.closest('a')) { menu.removeAttribute('open'); } });
    document.addEventListener('click', function (e) { if (menu.hasAttribute('open') && !menu.contains(e.target)) { menu.removeAttribute('open'); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { menu.removeAttribute('open'); } });
  }

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fine = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

  // إضاءة تتبع المؤشر فوق البطاقات (الحاسوب فقط)
  if (fine) {
    document.addEventListener('pointermove', function (e) {
      var card = e.target.closest && e.target.closest('.lp-spot');
      if (!card) { return; }
      var r = card.getBoundingClientRect();
      card.style.setProperty('--mx', (e.clientX - r.left) + 'px');
      card.style.setProperty('--my', (e.clientY - r.top) + 'px');
    }, { passive: true });
  }

  // لوحة التحكم المعروضة تميل قليلاً مع المؤشر (الحاسوب فقط، وبلا حركة لمن يفضّل تقليلها)
  var stage = document.querySelector('[data-lp-tilt]');
  if (stage && fine && !reduce) {
    var raf = 0, rx = 0, ry = 0;
    var rtl = document.documentElement.dir === 'rtl';
    var apply = function () { raf = 0; stage.style.setProperty('--rx', rx.toFixed(2) + 'deg'); stage.style.setProperty('--ry', ry.toFixed(2) + 'deg'); };
    stage.addEventListener('pointermove', function (e) {
      var r = stage.getBoundingClientRect();
      var x = (e.clientX - r.left) / r.width - 0.5, y = (e.clientY - r.top) / r.height - 0.5;
      rx = 6 - y * 8;
      ry = (rtl ? -6 : 6) + x * 10;
      if (!raf) { raf = requestAnimationFrame(apply); }
    });
    stage.addEventListener('pointerleave', function () { stage.style.removeProperty('--rx'); stage.style.removeProperty('--ry'); });
  }

  // حركة دخول قصيرة عند اقتراب العنصر من الشاشة (العنصر ظاهر أصلاً قبلها وبعدها)
  if (reduce || !('IntersectionObserver' in window)) { return; }
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); }
    });
  }, { rootMargin: '0px 0px 14% 0px', threshold: 0 });
  document.querySelectorAll('[data-lp-anim]').forEach(function (el) {
    // ما هو ظاهر عند التحميل يبقى كما هو بلا حركة
    if (el.getBoundingClientRect().top < window.innerHeight) { return; }
    io.observe(el);
  });
})(window, document);
