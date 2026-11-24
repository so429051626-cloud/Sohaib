/* صفحة الهبوط: تحسينات فقط. الصفحة تعمل كاملة بدونه ولا يُخفى أي محتوى. */
(function (window, document) {
  'use strict';
  var root = document.documentElement;
  root.classList.add('lp-js');
  var rtl = root.dir === 'rtl';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fine = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

  // الشريط العلوي، شريط تقدّم القراءة، وزر العودة للأعلى: قراءة واحدة للتمرير في كل إطار
  var nav = document.querySelector('[data-lp-nav]');
  var bar = document.querySelector('[data-lp-progress]');
  var top = document.querySelector('[data-lp-top]');
  var ticking = false;
  function onScroll() {
    ticking = false;
    var y = window.scrollY;
    if (nav) { nav.classList.toggle('is-scrolled', y > 12); }
    if (top) { top.classList.toggle('is-on', y > 700); }
    if (bar) {
      var max = document.documentElement.scrollHeight - window.innerHeight;
      bar.style.transform = 'scaleX(' + (max > 0 ? Math.min(1, y / max) : 0).toFixed(4) + ')';
    }
  }
  window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(onScroll); } }, { passive: true });
  window.addEventListener('resize', onScroll, { passive: true });
  onScroll();

  // قائمة الجوال: تُغلق بعد اختيار قسم أو النقر خارجها أو بمفتاح Escape
  var menu = document.querySelector('[data-lp-menu]');
  if (menu) {
    menu.addEventListener('click', function (e) { if (e.target.closest('a')) { menu.removeAttribute('open'); } });
    document.addEventListener('click', function (e) { if (menu.hasAttribute('open') && !menu.contains(e.target)) { menu.removeAttribute('open'); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { menu.removeAttribute('open'); } });
  }

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

  // لقطة النظام تميل قليلاً مع المؤشر (الحاسوب فقط، وبلا حركة لمن يفضّل تقليلها)
  var stage = document.querySelector('[data-lp-tilt]');
  if (stage && fine && !reduce) {
    var raf = 0, rx = 0, ry = 0;
    var apply = function () { raf = 0; stage.style.setProperty('--rx', rx.toFixed(2) + 'deg'); stage.style.setProperty('--ry', ry.toFixed(2) + 'deg'); };
    stage.addEventListener('pointermove', function (e) {
      var r = stage.getBoundingClientRect();
      var x = (e.clientX - r.left) / r.width - 0.5, y = (e.clientY - r.top) / r.height - 0.5;
      rx = 4 - y * 6;
      ry = (rtl ? -7 : 7) + x * 8;
      if (!raf) { raf = requestAnimationFrame(apply); }
    });
    stage.addEventListener('pointerleave', function () { stage.style.removeProperty('--rx'); stage.style.removeProperty('--ry'); });
  }

  // جولة في النظام: تبويبات بلوحة المفاتيح (الأسهم وHome وEnd) حسب اتجاه الصفحة
  document.querySelectorAll('[data-lp-tour]').forEach(function (tour) {
    var tabs = Array.prototype.slice.call(tour.querySelectorAll('[role="tab"]'));
    function select(tab, focus) {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        var panel = document.getElementById(t.getAttribute('aria-controls'));
        if (!panel) { return; }
        panel.hidden = !on;
        panel.classList.toggle('is-in', on && !reduce);
      });
      if (focus) { tab.focus(); }
    }
    // الصفحة تصل بكل اللقطات ظاهرة؛ هنا تبقى المختارة وحدها
    tabs.forEach(function (t) {
      var panel = document.getElementById(t.getAttribute('aria-controls'));
      if (panel) { panel.hidden = t.getAttribute('aria-selected') !== 'true'; }
    });
    tour.classList.add('is-ready');
    tabs.forEach(function (tab, i) {
      tab.addEventListener('click', function () { select(tab, false); });
      tab.addEventListener('keydown', function (e) {
        var next = null;
        if (e.key === 'ArrowLeft') { next = tabs[(i + (rtl ? 1 : -1) + tabs.length) % tabs.length]; }
        if (e.key === 'ArrowRight') { next = tabs[(i + (rtl ? -1 : 1) + tabs.length) % tabs.length]; }
        if (e.key === 'Home') { next = tabs[0]; }
        if (e.key === 'End') { next = tabs[tabs.length - 1]; }
        if (next) { e.preventDefault(); select(next, true); }
      });
    });
  });

  // فيديو العرض: يُحمَّل من YouTube فقط عند فتحه ويُزال عند الإغلاق (بدون JavaScript يفتح الرابط في YouTube)
  var dlg = document.querySelector('[data-lp-video-dialog]');
  if (dlg && typeof dlg.showModal === 'function') {
    var frame = dlg.querySelector('[data-lp-video-frame]');
    var close = function () { if (dlg.open) { dlg.close(); } };
    document.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('[data-lp-video]');
      if (!btn) { return; }
      e.preventDefault();
      var id = btn.getAttribute('data-lp-video');
      if (!/^[A-Za-z0-9_-]{11}$/.test(id)) { return; }
      var f = document.createElement('iframe');
      f.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0&playsinline=1';
      f.title = btn.textContent.trim();
      f.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
      f.allowFullscreen = true;
      frame.replaceChildren(f);
      dlg.showModal();
      // التركيز على زر الإغلاق في الصفحة نفسها حتى يصلها Escape
      dlg.querySelector('[data-lp-video-close]').focus();
    });
    dlg.querySelector('[data-lp-video-close]').addEventListener('click', close);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) { close(); } });
    // الفيديو يتوقف فور طلب الإغلاق (Escape) ثم عند الإغلاق بأي طريقة
    dlg.addEventListener('cancel', function () { frame.replaceChildren(); });
    dlg.addEventListener('close', function () { frame.replaceChildren(); });
  }

  // القسم الظاهر يُبرز رابطه في الشريط العلوي
  var links = document.querySelectorAll('[data-lp-link]');
  if (links.length && 'IntersectionObserver' in window) {
    var byId = {}, seen = {};
    links.forEach(function (a) { byId[a.getAttribute('href').slice(1)] = a; });
    // القسم الذي يعبر منتصف الشاشة؛ ولا شيء فوق الأقسام (الواجهة) أو بينها
    var spy = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { seen[en.target.id] = en.isIntersecting; });
      var cur = Object.keys(byId).filter(function (id) { return seen[id]; })[0];
      links.forEach(function (l) { l.classList.toggle('is-active', !!cur && l === byId[cur]); });
    }, { rootMargin: '-45% 0px -50% 0px', threshold: 0 });
    Object.keys(byId).forEach(function (id) { var s = document.getElementById(id); if (s) { spy.observe(s); } });
  }

  if (reduce || !('IntersectionObserver' in window)) { return; }

  // الأرقام تعدّ من الصفر حين تصل إليها (ما فيه رقم واحد في أوله فقط، مثل «14 يوماً» أو «100%»)
  function count(el) {
    var m = /^(\d{1,6})(\D*)$/.exec(el.textContent.trim());
    if (!m) { return; }
    var end = parseInt(m[1], 10), rest = m[2], t0 = 0;
    var step = function (t) {
      if (!t0) { t0 = t; }
      var k = Math.min(1, (t - t0) / 1200);
      el.textContent = Math.round(end * (1 - Math.pow(1 - k, 3))) + rest;
      if (k < 1) { requestAnimationFrame(step); }
    };
    requestAnimationFrame(step);
  }

  // حركة دخول قصيرة عند اقتراب العنصر من الشاشة (العنصر ظاهر أصلاً قبلها وبعدها)
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      if (!en.isIntersecting) { return; }
      en.target.classList.add('is-in');
      var num = en.target.querySelector('[data-lp-count]');
      if (num) { count(num); }
      io.unobserve(en.target);
    });
  }, { rootMargin: '0px 0px 14% 0px', threshold: 0 });
  document.querySelectorAll('[data-lp-anim]').forEach(function (el) {
    // ما هو ظاهر عند التحميل يبقى كما هو بلا حركة
    if (el.getBoundingClientRect().top < window.innerHeight) { return; }
    io.observe(el);
  });
})(window, document);
