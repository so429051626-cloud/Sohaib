(function () {
  'use strict';

  window.__appOk = true;

  var doc = document;
  var root = doc.documentElement;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var data = {};
  try { data = JSON.parse((doc.getElementById('app-data') || {}).textContent || '{}'); } catch (e) { data = {}; }
  var ui = data.ui || {};
  var $ = function (s, el) { return (el || doc).querySelector(s); };
  var $$ = function (s, el) { return Array.prototype.slice.call((el || doc).querySelectorAll(s)); };

  // كل جزء مستقل: لو تعطّل جزء لا يوقف بقية الصفحة
  function safe(fn) {
    try { fn(); } catch (err) { if (window.console) console.error(err); }
  }

  /* ───────── أداة تمرير واحدة لكل التأثيرات (بإطار رسم واحد) ───────── */
  var scrollFns = [];
  var ticking = false;
  function onScroll(fn) { scrollFns.push(fn); }
  function runScroll() {
    ticking = false;
    for (var i = 0; i < scrollFns.length; i++) safe(scrollFns[i]);
  }
  window.addEventListener('scroll', function () {
    if (!ticking) { ticking = true; requestAnimationFrame(runScroll); }
  }, { passive: true });
  window.addEventListener('resize', function () { requestAnimationFrame(runScroll); });

  /* ───────── شاشة التحميل ثم حركة دخول الواجهة ───────── */
  safe(function () {
    var start = window.performance && performance.now ? performance.now() : 0;
    var ready = function () {
      root.classList.add('is-ready');
      root.classList.remove('is-quick');
      try { sessionStorage.setItem('sq-loaded', '1'); } catch (e) { /* خاص */ }
      if (root.classList.contains('is-loading')) {
        setTimeout(function () { root.classList.remove('is-loading'); }, 800);
      }
    };
    if (root.classList.contains('is-loading')) {
      // الشعار يحتاج ~2.2 ثانية ليكتمل رسمه؛ ولا ننتظر أكثر من 3.6 ثانية مهما كان الاتصال بطيئاً
      var minWait = Math.max(0, 2250 - start);
      var done = false;
      var finish = function () { if (!done) { done = true; ready(); } };
      var loaded = doc.readyState === 'complete';
      var go = function () { setTimeout(finish, loaded ? minWait : Math.max(0, 2250 - performance.now())); };
      if (loaded) go(); else window.addEventListener('load', go, { once: true });
      setTimeout(finish, Math.max(minWait, 3600 - start));
    } else {
      requestAnimationFrame(function () { setTimeout(ready, 40); });
    }
  });

  /* ───────── الشريط العلوي: القائمة، المؤشر الأحمر، القسم الحالي ───────── */
  safe(function () {
    var nav = $('.nav');
    if (!nav) return;
    var links = $('.nav__links', nav);
    var ind = $('.nav__ind', nav);
    var burger = $('.nav__burger', nav);
    var anchors = $$('.nav__links a[data-spy]', nav);

    var setOpen = function (open) {
      nav.classList.toggle('is-open', open);
      if (burger) burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    if (burger) burger.addEventListener('click', function (e) { e.stopPropagation(); setOpen(!nav.classList.contains('is-open')); });
    links.addEventListener('click', function (e) { if (e.target.closest('a')) setOpen(false); });
    doc.addEventListener('click', function (e) { if (!nav.contains(e.target)) setOpen(false); });
    doc.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });

    var current = null;
    var moveInd = function () {
      if (!ind || !current) return;
      if (getComputedStyle(ind).display === 'none') return;
      ind.style.left = current.offsetLeft + 'px';
      ind.style.width = current.offsetWidth + 'px';
      links.classList.add('has-ind');
    };
    var activate = function (key) {
      var a = null;
      anchors.forEach(function (x) { if (x.getAttribute('data-spy') === key) a = x; });
      if (!a || a === current) return;
      if (current) { current.classList.remove('is-active'); current.removeAttribute('aria-current'); }
      current = a;
      a.classList.add('is-active');
      a.setAttribute('aria-current', 'true');
      moveInd();
    };
    current = $('.nav__links a.is-active', nav);
    moveInd();
    // الخطوط تُحمَّل بعد ظهور الصفحة فيتغيّر عرض الروابط: نعيد ضبط المؤشر كلما تغيّر أي مقاس
    if ('ResizeObserver' in window) {
      var ro = new ResizeObserver(function () { moveInd(); });
      ro.observe(links);
      anchors.forEach(function (a) { ro.observe(a); });
    }
    if (doc.fonts) {
      if (doc.fonts.ready) doc.fonts.ready.then(moveInd);
      if (doc.fonts.addEventListener) doc.fonts.addEventListener('loadingdone', moveInd);
    }
    window.addEventListener('resize', moveInd);
    window.addEventListener('load', moveInd);

    var sections = $$('[data-section]');
    var spy = function () {
      var line = window.innerHeight * 0.38;
      var key = sections.length ? sections[0].getAttribute('data-section') : 'home';
      sections.forEach(function (s) { if (s.getBoundingClientRect().top <= line) key = s.getAttribute('data-section'); });
      if (window.innerHeight + window.scrollY >= doc.documentElement.scrollHeight - 4) key = 'contact';
      activate(key);
      nav.classList.toggle('is-scrolled', window.scrollY > 10);
    };
    onScroll(spy);
    spy();
  });

  /* ───────── الوضع الليلي ───────── */
  safe(function () {
    var btn = $('.nav__theme');
    if (!btn) return;
    var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    var isDark = function () {
      var t = root.getAttribute('data-theme');
      return t ? t === 'dark' : !!(mq && mq.matches);
    };
    var paintMeta = function () {
      var color = isDark() ? '#120809' : '#f6f0eb';
      $$('meta[name="theme-color"]').forEach(function (m) { m.setAttribute('content', color); });
    };
    paintMeta();
    // لو لم يختر الزائر وضعاً بنفسه، نتبع إعداد جهازه حتى لو غيّره والصفحة مفتوحة
    if (mq && mq.addEventListener) {
      mq.addEventListener('change', function (ev) {
        var saved = null;
        try { saved = localStorage.getItem('sq-theme'); } catch (e) { /* خاص */ }
        if (!saved) { root.setAttribute('data-theme', ev.matches ? 'dark' : 'light'); paintMeta(); }
      });
    }
    var apply = function (mode) {
      root.setAttribute('data-theme', mode);
      try { localStorage.setItem('sq-theme', mode); } catch (e) { /* خاص */ }
      paintMeta();
    };
    btn.addEventListener('click', function () {
      var next = isDark() ? 'light' : 'dark';
      if (!doc.startViewTransition || reduce) { apply(next); return; }
      var r = btn.getBoundingClientRect();
      var x = r.left + r.width / 2;
      var y = r.top + r.height / 2;
      var end = Math.hypot(Math.max(x, innerWidth - x), Math.max(y, innerHeight - y));
      root.classList.add('vt');
      var t = doc.startViewTransition(function () { apply(next); });
      t.ready.then(function () {
        root.animate({ clipPath: ['circle(0px at ' + x + 'px ' + y + 'px)', 'circle(' + end + 'px at ' + x + 'px ' + y + 'px)'] },
          { duration: 650, easing: 'cubic-bezier(.2,.75,.2,1)', pseudoElement: '::view-transition-new(root)' });
      }).catch(function () {});
      t.finished.then(function () { root.classList.remove('vt'); }, function () { root.classList.remove('vt'); });
    });
  });

  /* ───────── الظهور التدريجي عند التمرير ───────── */
  safe(function () {
    var items = $$('.reveal');
    // تتابع بسيط بين العناصر المتجاورة
    var groups = new Map();
    items.forEach(function (el) {
      var p = el.parentNode;
      if (!groups.has(p)) groups.set(p, 0);
      var n = groups.get(p);
      el.style.setProperty('--d', Math.min(n * 0.08, 0.48).toFixed(2) + 's');
      groups.set(p, n + 1);
    });
    if (!('IntersectionObserver' in window) || reduce) {
      items.forEach(function (el) { el.classList.add('in'); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });
    items.forEach(function (el) { io.observe(el); });
  });

  /* ───────── حركة الواجهة مع التمرير (Parallax) ───────── */
  safe(function () {
    var hero = $('.hero');
    var name = $('.hero__name');
    var photo = $('.hero__photo');
    if (!hero || reduce) return;
    onScroll(function () {
      var y = window.scrollY;
      var h = hero.offsetHeight;
      if (y > h) return;
      if (name) name.style.transform = 'translate3d(0,' + (y * 0.32).toFixed(1) + 'px,0)';
      if (photo) photo.style.transform = 'translate3d(0,' + (y * 0.1).toFixed(1) + 'px,0)';
    });
  });

  /* ───────── الشرائط المتحركة تتسارع مع التمرير ───────── */
  safe(function () {
    if (reduce) return;
    var tracks = $$('.ribbon__track, .logos__track');
    if (!tracks.length || !tracks[0].getAnimations) return;
    var lastY = window.scrollY;
    var boost = 0;
    var raf = 0;
    var setRate = function (r) {
      tracks.forEach(function (t) { t.getAnimations().forEach(function (a) { a.playbackRate = r; }); });
    };
    var decay = function () {
      boost *= 0.92;
      if (boost < 0.02) { boost = 0; setRate(1); raf = 0; return; }
      setRate(1 + boost);
      raf = requestAnimationFrame(decay);
    };
    onScroll(function () {
      var y = window.scrollY;
      boost = Math.min(5, boost + Math.abs(y - lastY) / 60);
      lastY = y;
      if (!raf) raf = requestAnimationFrame(decay);
    });
  });

  /* ───────── خط "لماذا أنا" يمتلئ مع التمرير ───────── */
  safe(function () {
    var tl = $('.timeline');
    if (!tl) return;
    var items = $$('.tl', tl);
    var update = function () {
      var r = tl.getBoundingClientRect();
      var line = window.innerHeight * 0.62;
      var p = Math.max(0, Math.min(1, (line - r.top) / r.height));
      tl.style.setProperty('--p', p.toFixed(3));
      items.forEach(function (it) {
        var dot = it.getBoundingClientRect().top + 30;
        it.classList.toggle('is-on', dot < line);
      });
    };
    onScroll(update);
    update();
  });

  /* ───────── عدّاد الأرقام ───────── */
  safe(function () {
    var counts = $$('.count[data-to]');
    if (!counts.length || reduce || !('IntersectionObserver' in window)) return;
    var run = function (el) {
      var to = parseInt(el.getAttribute('data-to'), 10) || 0;
      var t0 = null;
      var dur = 1600;
      var step = function (t) {
        if (t0 === null) t0 = t;
        var k = Math.min(1, (t - t0) / dur);
        var eased = 1 - Math.pow(1 - k, 3);
        el.textContent = String(Math.round(to * eased));
        if (k < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    };
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { io.unobserve(en.target); run(en.target); }
      });
    }, { threshold: 0.6 });
    counts.forEach(function (el) { el.textContent = '0'; io.observe(el); });
  });

  /* ───────── زر "مشاريع أكثر" ───────── */
  safe(function () {
    var btn = $('[data-more]');
    var works = $('.works');
    if (!btn || !works) return;
    var label = $('span', btn);
    var orig = label ? label.textContent : '';
    btn.addEventListener('click', function () {
      var all = works.classList.toggle('is-all');
      btn.setAttribute('aria-expanded', all ? 'true' : 'false');
      if (label) label.textContent = all ? (ui.less || orig) : orig;
      if (all) $$('.work--extra', works).forEach(function (w) { w.classList.add('in'); });
    });
  });

  /* ───────── سلايدر آراء العملاء ───────── */
  safe(function () {
    var box = $('[data-slider]');
    if (!box) return;
    var slides = $$('.review', box);
    if (slides.length < 2) return;
    var i = 0;
    var timer = null;
    var show = function (n) {
      slides[i].hidden = true;
      slides[i].classList.remove('is-on');
      i = (n + slides.length) % slides.length;
      slides[i].hidden = false;
      slides[i].classList.add('is-on');
    };
    var auto = function () { clearInterval(timer); if (!reduce) timer = setInterval(function () { show(i + 1); }, 7000); };
    var prev = $('[data-prev]', box);
    var next = $('[data-next]', box);
    if (prev) prev.addEventListener('click', function () { show(i - 1); auto(); });
    if (next) next.addEventListener('click', function () { show(i + 1); auto(); });
    box.addEventListener('mouseenter', function () { clearInterval(timer); });
    box.addEventListener('mouseleave', auto);
    var sx = null;
    box.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; }, { passive: true });
    box.addEventListener('touchend', function (e) {
      if (sx === null) return;
      var dx = e.changedTouches[0].clientX - sx;
      sx = null;
      if (Math.abs(dx) < 40) return;
      var rtl = root.getAttribute('dir') === 'rtl';
      show(i + ((dx < 0) !== rtl ? 1 : -1));
      auto();
    }, { passive: true });
    auto();
  });

  /* ───────── تسجيل النقرات على الروابط (للإحصائيات) ───────── */
  function beacon(key) {
    var body = new URLSearchParams({ k: key });
    try {
      if (navigator.sendBeacon) navigator.sendBeacon('track.php', body);
      else fetch('track.php', { method: 'POST', body: body, keepalive: true });
    } catch (err) { /* الإحصائيات لا تعطّل الرابط أبداً */ }
  }
  doc.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('[data-track]') : null;
    if (a) beacon(a.getAttribute('data-track'));
  });

  /* ───────── نموذج التواصل: يفتح واتساب برسالة جاهزة ويحفظ نسخة ───────── */
  safe(function () {
    var form = $('form.form');
    if (!form || !data.wa) return;
    var note = $('.form__note', form);
    var setNote = function (text, cls) {
      if (!note) return;
      note.textContent = text;
      note.classList.remove('is-ok', 'is-err');
      if (cls) note.classList.add(cls);
    };
    form.addEventListener('input', function (e) {
      var f = e.target.closest('.field');
      if (f) f.classList.remove('is-bad');
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var get = function (n) { var el = form.elements[n]; return el ? String(el.value || '').trim() : ''; };
      if (get('website')) return;
      var bad = [];
      ['name', 'details'].forEach(function (n) {
        if (!get(n)) { bad.push(n); form.elements[n].closest('.field').classList.add('is-bad'); }
      });
      if (bad.length) {
        setNote(ui.err || '', 'is-err');
        form.elements[bad[0]].focus();
        return;
      }
      var rows = [
        '*' + (ui.head || '') + '*',
        '',
        (ui.name || '') + ': ' + get('name')
      ];
      [['whatsapp', ui.wa], ['email', ui.email], ['company', ui.company], ['service', ui.service], ['budget', ui.budget], ['timeline', ui.time]].forEach(function (p) {
        var v = get(p[0]);
        if (v) rows.push((p[1] || p[0]) + ': ' + v);
      });
      rows.push('', (ui.details || '') + ':', get('details'));
      var url = 'https://wa.me/' + data.wa + '?text=' + encodeURIComponent(rows.join('\n'));

      // فتح واتساب يجب أن يحدث مباشرة مع الضغطة (وإلا يمنعه المتصفح)
      var win = null;
      try { win = window.open(url, '_blank'); } catch (err) { win = null; }
      if (win) { try { win.opener = null; } catch (err) { /* */ } }

      beacon('form');
      var fd = new FormData(form);
      fd.append('ajax', '1');
      var btn = $('button[type=submit]', form);
      if (btn) btn.disabled = true;
      fetch(form.getAttribute('action') || 'contact.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.status; })
        .catch(function () { return 0; })
        .then(function (status) {
          if (btn) btn.disabled = false;
          if (status === 429) setNote(ui.limit || '', 'is-err');
          else { setNote(ui.ok || '', 'is-ok'); form.reset(); }
          if (!win) window.location.href = url;
        });
    });
  });

  /* ───────── زر المحادثة السريعة ───────── */
  safe(function () {
    var chat = $('.chat');
    if (!chat) return;
    var btn = $('.chat__btn', chat);
    var pop = $('.chat__pop', chat);
    var x = $('.chat__x', chat);
    var set = function (open) {
      chat.classList.toggle('is-open', open);
      pop.hidden = !open;
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    btn.addEventListener('click', function (e) { e.stopPropagation(); set(pop.hidden); });
    if (x) x.addEventListener('click', function () { set(false); btn.focus(); });
    doc.addEventListener('click', function (e) { if (!chat.contains(e.target)) set(false); });
    doc.addEventListener('keydown', function (e) { if (e.key === 'Escape') set(false); });
  });

  /* ───────── زر العودة للأعلى مع دائرة تقدّم القراءة ───────── */
  safe(function () {
    var top = $('.totop');
    if (!top) return;
    onScroll(function () {
      var max = doc.documentElement.scrollHeight - window.innerHeight;
      var p = max > 0 ? window.scrollY / max : 0;
      top.style.setProperty('--sp', p.toFixed(3));
      top.classList.toggle('is-on', window.scrollY > 520);
    });
    runScroll();
  });
})();
