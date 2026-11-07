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

  /* ───────── الشاشة الافتتاحية (3 ثوانٍ) ثم انسحابها للأعلى وحركة دخول الواجهة ───────── */
  safe(function () {
    var now = function () { return window.performance && performance.now ? performance.now() : 0; };
    var start = now();
    var SHOW = 3000;   // مدة ظهور الشاشة الافتتاحية
    var SLIDE = 1100;  // مدة انسحابها للأعلى
    var ready = function () {
      root.classList.add('is-ready');
      root.classList.remove('is-quick');
      try { sessionStorage.setItem('sq-loaded', '1'); } catch (e) { /* خاص */ }
      if (root.classList.contains('is-loading')) {
        setTimeout(function () { root.classList.remove('is-loading'); }, SLIDE);
      }
    };
    if (!root.classList.contains('is-loading')) {
      requestAnimationFrame(function () { setTimeout(ready, 40); });
      return;
    }
    // العدّاد 0 → 100%
    var count = $('.loader__count');
    if (count) {
      var tick = function () {
        var k = Math.min(1, (now() - start) / (SHOW - 200));
        var eased = 1 - Math.pow(1 - k, 2.2);
        count.textContent = String(Math.round(eased * 100));
        if (k < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    }
    var done = false;
    var finish = function () { if (!done) { done = true; ready(); } };
    var wait = function () { setTimeout(finish, Math.max(0, SHOW - now())); };
    if (doc.readyState === 'complete') wait(); else window.addEventListener('load', wait, { once: true });
    // لا ننتظر أكثر من 4.2 ثانية مهما كان الاتصال بطيئاً
    setTimeout(finish, Math.max(SHOW, 4200 - start));
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
    // الوضع الفاتح هو الافتراضي دائماً، والليلي فقط عند اختيار الزائر له
    var isDark = function () { return root.getAttribute('data-theme') === 'dark'; };
    var paintMeta = function () {
      var color = getComputedStyle(root).getPropertyValue('--bg').trim() || (isDark() ? '#120809' : '#f6f0eb');
      $$('meta[name="theme-color"]').forEach(function (m) { m.setAttribute('content', color); });
    };
    paintMeta();
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
  /* ═════════════ ميزات إضافية (تُشغَّل وتُطفأ من لوحة التحكم ← الإعدادات) ═════════════ */

  var FX = data.fx || {};
  var finePointer = !!(window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches);
  var clamp = function (v, a, b) { return Math.max(a, Math.min(b, v)); };
  var nowMs = function () { return window.performance && performance.now ? performance.now() : Date.now(); };

  // تنفيذ دالة بعد انتهاء الشاشة الافتتاحية (أو فوراً لو انتهت)
  function whenReady(fn) {
    if (root.classList.contains('is-ready')) { fn(); return; }
    if (!('MutationObserver' in window)) { setTimeout(fn, 3200); return; }
    var mo = new MutationObserver(function () {
      if (root.classList.contains('is-ready')) { mo.disconnect(); fn(); }
    });
    mo.observe(root, { attributes: true, attributeFilter: ['class'] });
  }

  /* ───────── 5) صورة الواجهة ثلاثية الأبعاد: الماوس على الكمبيوتر، ميلان الجوال على الهاتف ───────── */
  safe(function () {
    if (!FX.tilt || reduce) return;
    var hero = $('.hero');
    var glare = $('.hero__glare');
    if (!hero) return;
    var target = { x: 0, y: 0 };
    var cur = { x: 0, y: 0 };
    var mode = 'idle';
    var lastInput = 0;
    var visible = true;
    var started = false;
    var raf = 0;
    var skip = false;

    var tiltEl = $('.hero__tilt');
    var halo = $('.hero__halo');
    var word = $('.hero__word');
    var paint = function () {
      var tx = cur.x * 14;
      var ty = cur.y * 9;
      if (tiltEl) tiltEl.style.transform = 'rotateX(' + (-cur.y * 6).toFixed(2) + 'deg) rotateY(' + (cur.x * 9).toFixed(2) + 'deg) translate3d(' + tx.toFixed(1) + 'px,' + ty.toFixed(1) + 'px,0)';
      if (halo) halo.style.transform = 'translate3d(' + (tx * -1.6).toFixed(1) + 'px,' + (ty * -1.3).toFixed(1) + 'px,0)';
      if (word) word.style.transform = 'translate3d(' + (tx * -0.45).toFixed(1) + 'px,' + (ty * -0.3).toFixed(1) + 'px,0)';
      if (glare) {
        glare.style.setProperty('--gx', (50 + cur.x * 40).toFixed(1) + '%');
        glare.style.setProperty('--gy', (28 + cur.y * 26).toFixed(1) + '%');
      }
    };
    var setMode = function (m) {
      if (m === mode) return;
      mode = m;
      if (glare) glare.style.setProperty('--go', m === 'idle' ? '0.35' : '0.9');
    };
    var loop = function (t) {
      raf = 0;
      if (!visible || doc.hidden) return;
      if (mode !== 'idle' && t - lastInput > 2600) setMode('idle');
      if (mode === 'idle') {
        // حركة "تنفّس" هادئة عند عدم وجود ماوس أو حساس
        target.x = Math.sin(t / 2600) * 0.3;
        target.y = Math.cos(t / 3400) * 0.2;
      }
      cur.x += (target.x - cur.x) * 0.075;
      cur.y += (target.y - cur.y) * 0.075;
      // في السكون نرسم إطاراً ونترك إطاراً (حركة هادئة أصلاً) لتوفير البطارية
      skip = mode === 'idle' ? !skip : false;
      if (!skip) paint();
      raf = requestAnimationFrame(loop);
    };
    var start = function () {
      if (started && !raf && visible && !doc.hidden) raf = requestAnimationFrame(loop);
    };
    var input = function (x, y, m) {
      target.x = clamp(x, -1, 1);
      target.y = clamp(y, -1, 1);
      lastInput = nowMs();
      setMode(m);
      start();
    };

    // الكمبيوتر: الماوس فوق الواجهة
    hero.addEventListener('pointermove', function (e) {
      if (e.pointerType !== 'mouse') return;
      var r = hero.getBoundingClientRect();
      input(((e.clientX - r.left) / r.width - 0.5) * 2, ((e.clientY - r.top) / r.height - 0.5) * 2, 'pointer');
    }, { passive: true });
    hero.addEventListener('pointerleave', function (e) {
      if (e.pointerType === 'mouse') input(0, 0, 'pointer');
    });

    // الجوال: ميلان الجهاز
    var base = null;
    var onOrient = function (e) {
      if (e.gamma == null || e.beta == null) return;
      var ang = (screen.orientation && typeof screen.orientation.angle === 'number') ? screen.orientation.angle : (window.orientation || 0);
      var lr = e.gamma;
      var fb = e.beta;
      if (ang === 90 || ang === -270) { lr = e.beta; fb = -e.gamma; }
      else if (ang === -90 || ang === 270) { lr = -e.beta; fb = e.gamma; }
      if (base === null) base = fb;
      base += (fb - base) * 0.015; // نقطة الصفر تتبع طريقة مسك الجوال ببطء
      input(lr / 22, (fb - base) / 16, 'gyro');
    };
    if (!finePointer && typeof DeviceOrientationEvent !== 'undefined') {
      if (typeof DeviceOrientationEvent.requestPermission === 'function') {
        // آيفون: يطلب الإذن مرة واحدة عند لمس الواجهة (وإلا تبقى حركة التنفّس)
        var ask = function (ev) {
          if (ev.target.closest && ev.target.closest('a, button')) return;
          hero.removeEventListener('click', ask);
          DeviceOrientationEvent.requestPermission().then(function (state) {
            if (state === 'granted') window.addEventListener('deviceorientation', onOrient, { passive: true });
          }).catch(function () {});
        };
        hero.addEventListener('click', ask);
      } else {
        window.addEventListener('deviceorientation', onOrient, { passive: true });
      }
    }

    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (en) {
        visible = en[0].isIntersecting;
        start();
      }, { threshold: 0 }).observe(hero);
    }
    doc.addEventListener('visibilitychange', start);
    whenReady(function () { started = true; start(); });
  });

  safe(function () {
    var ph = $('.about__photo');
    if (!ph || !('IntersectionObserver' in window)) return;
    new IntersectionObserver(function (en) {
      ph.classList.toggle('is-paused', !en[0].isIntersecting);
    }, { rootMargin: '120px 0px' }).observe(ph);
  });

  /* ───────── 6) مؤشر ماوس مخصص + أزرار مغناطيسية (الكمبيوتر فقط) ───────── */
  safe(function () {
    if (!FX.cursor || !finePointer) return;
    root.classList.add('has-cursor');
    var wrap = doc.createElement('div');
    wrap.className = 'cursor is-hidden';
    wrap.setAttribute('aria-hidden', 'true');
    wrap.innerHTML = '<span class="cursor__dot"></span><span class="cursor__ring"><b class="cursor__label"></b></span>';
    doc.body.appendChild(wrap);
    var dot = wrap.firstChild;
    var ring = wrap.lastChild;
    var label = ring.firstChild;
    var mx = -100;
    var my = -100;
    var rx = -100;
    var ry = -100;
    var running = false;
    var first = true;

    var tick = function () {
      rx += (mx - rx) * 0.2;
      ry += (my - ry) * 0.2;
      ring.style.transform = 'translate3d(' + rx.toFixed(1) + 'px,' + ry.toFixed(1) + 'px,0)';
      if (Math.abs(mx - rx) + Math.abs(my - ry) > 0.15) requestAnimationFrame(tick);
      else running = false;
    };
    doc.addEventListener('pointermove', function (e) {
      if (e.pointerType !== 'mouse') return;
      mx = e.clientX;
      my = e.clientY;
      if (first) { rx = mx; ry = my; first = false; }
      dot.style.transform = 'translate3d(' + mx + 'px,' + my + 'px,0)';
      wrap.classList.remove('is-hidden');
      if (!running) { running = true; requestAnimationFrame(tick); }
    }, { passive: true });
    doc.addEventListener('pointerdown', function () { wrap.classList.add('is-down'); });
    doc.addEventListener('pointerup', function () { wrap.classList.remove('is-down'); });
    doc.addEventListener('mouseout', function (e) { if (!e.relatedTarget) wrap.classList.add('is-hidden'); });
    window.addEventListener('blur', function () { wrap.classList.add('is-hidden'); });

    var LINK = 'a, button, label, summary, [role="button"], .svc, .tool, .logo-item, .chips li, .hero__ch, .stat';
    doc.addEventListener('pointerover', function (e) {
      var t = e.target;
      if (!t || !t.closest) return;
      var text = t.closest('input, textarea, select, [contenteditable]');
      var view = !text && t.closest('[data-cursor]');
      var link = !text && !view && t.closest(LINK);
      wrap.classList.toggle('is-text', !!text);
      wrap.classList.toggle('is-view', !!view);
      wrap.classList.toggle('is-link', !!link);
      label.textContent = view ? view.getAttribute('data-cursor') : '';
    });

    // الأزرار المغناطيسية: تنجذب نحو الماوس داخل حدودها ثم ترجع بنعومة
    var MAG = '.btn, .nav__theme, .nav__lang, .nav__cta, .chat__btn, .totop, .soc a, .rail a, .svc__more i, .reviews__nav button';
    $$(MAG).forEach(function (el) {
      el.addEventListener('pointermove', function (e) {
        if (e.pointerType !== 'mouse') return;
        var r = el.getBoundingClientRect();
        var nx = (e.clientX - (r.left + r.width / 2)) / (r.width / 2);
        var ny = (e.clientY - (r.top + r.height / 2)) / (r.height / 2);
        var maxX = Math.min(12, r.width * 0.14);
        var maxY = Math.min(9, r.height * 0.28);
        el.style.translate = (clamp(nx, -1, 1) * maxX).toFixed(1) + 'px ' + (clamp(ny, -1, 1) * maxY).toFixed(1) + 'px';
      });
      el.addEventListener('pointerleave', function () {
        var from = el.style.translate;
        el.style.translate = '';
        if (from && el.animate) {
          try { el.animate([{ translate: from }, { translate: '0px 0px' }], { duration: 600, easing: 'cubic-bezier(.2,.75,.2,1)' }); } catch (err) { /* */ }
        }
      });
    });
  });

  /* ───────── 7) عناوين بتأثير فك التشفير ───────── */
  safe(function () {
    if (!FX.scramble || reduce) return;
    var SETS = {
      up: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
      low: 'abcdefghijklmnopqrstuvwxyz',
      dig: '0123456789',
      sym: '#%&*+=<>/?$',
      ar: 'ابتثجحخدذرزسشصضطظعغفقكلمنهوي'
    };
    var pick = function (s) { return s.charAt((Math.random() * s.length) | 0); };
    var glyph = function (ch) {
      if (/[A-Z]/.test(ch)) return Math.random() < 0.18 ? pick(SETS.sym) : pick(SETS.up);
      if (/[a-z]/.test(ch)) return Math.random() < 0.18 ? pick(SETS.sym) : pick(SETS.low);
      if (/[0-9]/.test(ch)) return pick(SETS.dig);
      if (/[ء-ي]/.test(ch)) return pick(SETS.ar);
      return null; // المسافات وعلامات الترقيم تبقى كما هي
    };
    var run = function (el) {
      if (el.__scrambled) return;
      el.__scrambled = true;
      var walker = doc.createTreeWalker(el, NodeFilter.SHOW_TEXT, null);
      var nodes = [];
      var n;
      while ((n = walker.nextNode())) {
        if (n.nodeValue.trim()) nodes.push({ node: n, text: n.nodeValue, chars: Array.from(n.nodeValue) });
      }
      if (!nodes.length) return;
      var total = 0;
      nodes.forEach(function (x) { total += x.chars.length; });
      el.setAttribute('aria-label', el.textContent.replace(/\s+/g, ' ').trim());
      el.style.minHeight = el.offsetHeight + 'px';
      var dur = clamp(450 + total * 24, 700, 1500);
      var t0 = null;
      var last = 0;
      var frame = function (t) {
        if (t0 === null) t0 = t;
        var p = (t - t0) / dur;
        if (p >= 1) {
          nodes.forEach(function (x) { x.node.nodeValue = x.text; });
          el.style.minHeight = '';
          el.removeAttribute('aria-label');
          return;
        }
        if (t - last > 48) {
          last = t;
          var idx = 0;
          nodes.forEach(function (x) {
            var out = '';
            x.chars.forEach(function (ch) {
              var settle = 0.12 + (idx / total) * 0.82;
              idx++;
              if (p >= settle) { out += ch; return; }
              var g = glyph(ch);
              out += g === null ? ch : g;
            });
            x.node.nodeValue = out;
          });
        }
        requestAnimationFrame(frame);
      };
      requestAnimationFrame(frame);
    };

    var heads = $$('.title, .subtitle, .about__name, .foot__title');
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) { io.unobserve(en.target); run(en.target); }
        });
      }, { threshold: 0.6 });
      heads.forEach(function (el) { io.observe(el); });
    }
    // عناوين الواجهة: بعد الشاشة الافتتاحية مباشرة
    whenReady(function () {
      setTimeout(function () {
        $$('.hero__title').forEach(function (el) { if (el.offsetParent !== null) run(el); });
      }, 450);
    });
  });

  /* ───────── 8) نافذة الكود التي تكتب نفسها ───────── */
  safe(function () {
    var out = doc.getElementById('code-out');
    var src = doc.getElementById('code-src');
    var win = out ? out.closest('.code-win') : null;
    if (!FX.code || !out || !src || !win) return;

    var KW = /^(?:const|let|var|function|return|new|await|async|class|this|true|false|null|undefined|import|export|from|if|else|for|while|of|in)\b/;
    var tokenize = function (line) {
      var res = [];
      var i = 0;
      while (i < line.length) {
        var rest = line.slice(i);
        var m;
        if (rest.indexOf('//') === 0) { res.push(['c', rest]); break; }
        if ((m = rest.match(/^(["'`])(?:\\.|(?!\1)[^\\])*\1?/))) res.push(['s', m[0]]);
        else if ((m = rest.match(KW))) res.push(['k', m[0]]);
        else if ((m = rest.match(/^\d+(?:\.\d+)?/))) res.push(['n', m[0]]);
        else if ((m = rest.match(/^[A-Za-z_$][\w$]*(?=\s*:)/))) res.push(['p', m[0]]);
        else if ((m = rest.match(/^[A-Za-z_$][\w$]*(?=\s*\()/))) res.push(['f', m[0]]);
        else if ((m = rest.match(/^[A-Za-z_$][\w$]*/))) res.push(['', m[0]]);
        else if ((m = rest.match(/^[{}\[\]();,.:=<>+\-*\/!&|?@#%^~]+/))) res.push(['o', m[0]]);
        else if ((m = rest.match(/^\s+/))) res.push(['', m[0]]);
        else res.push(['', String.fromCodePoint(rest.codePointAt(0))]);
        i += res[res.length - 1][1].length;
      }
      return res;
    };
    var esc = function (s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
    var paintChars = function (chars) {
      var html = '';
      var curCls = null;
      var buf = '';
      for (var i = 0; i <= chars.length; i++) {
        var cls = i < chars.length ? chars[i][0] : null;
        if (i === chars.length || cls !== curCls) {
          if (buf) html += curCls ? '<span class="t-' + curCls + '">' + esc(buf) + '</span>' : esc(buf);
          buf = '';
          curCls = cls;
        }
        if (i < chars.length) buf += chars[i][1];
      }
      return html;
    };
    var charsOf = function (line) {
      var list = [];
      tokenize(line).forEach(function (t) { Array.from(t[1]).forEach(function (ch) { list.push([t[0], ch]); }); });
      return list;
    };

    var lines = [];
    try { lines = JSON.parse(src.textContent) || []; } catch (e) { lines = []; }
    while (lines.length && String(lines[lines.length - 1]).trim() === '') lines.pop();
    var prepared = lines.map(function (l) { return charsOf(String(l)); });
    var row = function (inner) { return '<span class="ln">' + inner + '</span>'; };
    var caret = '<span class="caret"></span>';
    var finish = function () { win.classList.add('is-done'); };

    // نسخة مخفية كاملة تحجز الارتفاع النهائي فلا تقفز الصفحة أثناء الكتابة
    var ghost = doc.createElement('code');
    ghost.className = 'ghost';
    ghost.setAttribute('aria-hidden', 'true');
    ghost.innerHTML = prepared.map(function (c) { return row(paintChars(c)); }).join('');
    out.parentNode.insertBefore(ghost, out);

    if (reduce || !prepared.length) {
      out.innerHTML = prepared.map(function (c, i) { return row(paintChars(c) + (i === prepared.length - 1 ? caret : '')); }).join('');
      finish();
      return;
    }

    var li = 0;
    var ci = 0;
    var done = '';
    out.innerHTML = row(caret);
    var tick = function () {
      var line = prepared[li];
      ci++;
      if (ci > line.length) {
        done += row(paintChars(line));
        li++;
        ci = 0;
        if (li >= prepared.length) {
          out.innerHTML = done.replace(/<\/span>$/, caret + '</span>');
          setTimeout(finish, 350);
          return;
        }
        out.innerHTML = done + row(caret);
        setTimeout(tick, line.length ? 200 : 90);
        return;
      }
      out.innerHTML = done + row(paintChars(line.slice(0, ci)) + caret);
      var ch = line[ci - 1] ? line[ci - 1][1] : '';
      var delay = 18 + Math.random() * 34;
      if (/[,;{(]/.test(ch)) delay += 80;
      if (ch === ' ' && ci < 6) delay = 6;
      setTimeout(tick, delay);
    };
    var go = function () { setTimeout(tick, 350); };
    if ('IntersectionObserver' in window) {
      var cio = new IntersectionObserver(function (es) {
        if (es[0].isIntersecting) { cio.disconnect(); go(); }
      }, { threshold: 0.35 });
      cio.observe(win);
    } else {
      go();
    }
  });
  /* ═════════════ الدفعة الثالثة: التمرير الناعم، التقدّم، المشاركة، الإحصائيات ═════════════ */

  /* ───────── 10) تمرير ناعم فاخر (عجلة الماوس على الكمبيوتر فقط) ───────── */
  var lenis = null;
  safe(function () {
    if (!FX.smooth || reduce || !finePointer || typeof window.Lenis !== 'function') return;
    lenis = new window.Lenis({
      lerp: 0.1,
      smoothWheel: true,
      syncTouch: false,
      wheelMultiplier: 1,
      prevent: function (node) {
        return !!(node && node.closest && node.closest('.code-win__body, textarea, select, .chat__pop, .share, .nav__links, [data-lenis-prevent]'));
      }
    });
    var raf = function (t) { lenis.raf(t); requestAnimationFrame(raf); };
    requestAnimationFrame(raf);
    if (root.classList.contains('is-loading')) {
      lenis.stop();
      whenReady(function () { lenis.start(); });
    }
    // روابط الأقسام: انتقال ناعم مع مراعاة ارتفاع الشريط العلوي
    doc.addEventListener('click', function (e) {
      var a = e.target.closest ? e.target.closest('a[href^="#"]') : null;
      if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
      var id = a.getAttribute('href');
      if (id === '#main') return; // رابط التخطي يبقى طبيعياً
      var target = id === '#' || id === '#home' || id === '#top' ? 0 : doc.querySelector(id);
      if (target === null) return;
      e.preventDefault();
      lenis.scrollTo(target, { offset: target === 0 ? 0 : -86, duration: 1.3 });
    });
  });

  /* ───────── 8) شريط تقدّم القراءة ونقاط التنقل ───────── */
  safe(function () {
    if (!FX.progress) return;
    var bar = $('.readbar i');
    var dots = $$('.dots a[data-dot]');
    var sections = $$('[data-section]');
    var last = '';
    var update = function () {
      var max = doc.documentElement.scrollHeight - window.innerHeight;
      if (bar) bar.style.setProperty('--rp', (max > 0 ? Math.min(1, window.scrollY / max) : 0).toFixed(4));
      if (!dots.length) return;
      var line = window.innerHeight * 0.38;
      var key = sections.length ? sections[0].getAttribute('data-section') : 'home';
      sections.forEach(function (s) { if (s.getBoundingClientRect().top <= line) key = s.getAttribute('data-section'); });
      if (window.innerHeight + window.scrollY >= doc.documentElement.scrollHeight - 4) key = 'contact';
      if (key === last) return;
      last = key;
      dots.forEach(function (d) { d.classList.toggle('is-on', d.getAttribute('data-dot') === key); });
    };
    onScroll(update);
    update();
  });

  /* ───────── 12) النص العملاق: يتسارع مع التمرير مثل الشرائط ───────── */
  safe(function () {
    if (!FX.bigtext || reduce) return;
    var tracks = $$('.mega__track');
    if (!tracks.length || !tracks[0].getAnimations) return;
    var lastY = window.scrollY;
    var boost = 0;
    var raf = 0;
    var setRate = function (r) {
      tracks.forEach(function (t) { t.getAnimations().forEach(function (a) { a.playbackRate = r; }); });
    };
    var decay = function () {
      boost *= 0.93;
      if (boost < 0.02) { boost = 0; setRate(1); raf = 0; return; }
      setRate(1 + boost);
      raf = requestAnimationFrame(decay);
    };
    onScroll(function () {
      var y = window.scrollY;
      boost = Math.min(7, boost + Math.abs(y - lastY) / 40);
      lastY = y;
      if (!raf) raf = requestAnimationFrame(decay);
    });
  });

  /* ───────── 18) زر المشاركة مع QR ───────── */
  safe(function () {
    var box = doc.getElementById('share');
    var cfg = data.share || {};
    if (!FX.share || !box || !cfg.url) return;
    var opener = null;
    var qrBox = doc.getElementById('share-qr');
    var qrReady = false;
    var text = (cfg.title || '') + '\n' + cfg.url;
    var nets = {
      whatsapp: 'https://wa.me/?text=' + encodeURIComponent(text),
      facebook: 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(cfg.url),
      telegram: 'https://t.me/share/url?url=' + encodeURIComponent(cfg.url) + '&text=' + encodeURIComponent(cfg.title || ''),
      x: 'https://twitter.com/intent/tweet?url=' + encodeURIComponent(cfg.url) + '&text=' + encodeURIComponent(cfg.title || '')
    };
    $$('[data-net]', box).forEach(function (a) {
      a.href = nets[a.getAttribute('data-net')] || '#';
      a.setAttribute('data-track', 'share');
    });
    var native = $('[data-native]', box);
    if (native && navigator.share) {
      native.hidden = false;
      native.addEventListener('click', function () {
        navigator.share({ title: cfg.title, url: cfg.url }).catch(function () {});
      });
    }

    var drawQR = function () {
      if (qrReady || !window.qrcode || !qrBox) return;
      try {
        var q = window.qrcode(0, 'H'); // تصحيح أخطاء عالٍ حتى يبقى مقروءاً مع الشعار في الوسط
        q.addData(cfg.url);
        q.make();
        var n = q.getModuleCount();
        var path = '';
        for (var r = 0; r < n; r++) {
          for (var c = 0; c < n; c++) {
            if (q.isDark(r, c)) path += 'M' + c + ' ' + r + 'h1v1h-1z';
          }
        }
        var ink = getComputedStyle(root).getPropertyValue('--btn').trim() || '#220f11';
        if (root.getAttribute('data-theme') === 'dark') ink = getComputedStyle(root).getPropertyValue('--btn-fg').trim() || '#220f11';
        qrBox.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + n + ' ' + n + '" shape-rendering="crispEdges"><path fill="' + ink + '" d="' + path + '"/></svg>';
        qrReady = true;
      } catch (err) { /* */ }
    };
    var loadQR = function () {
      if (window.qrcode) { drawQR(); return; }
      if (doc.getElementById('qr-lib')) return;
      var sc = doc.createElement('script');
      sc.id = 'qr-lib';
      sc.src = cfg.qr;
      sc.onload = drawQR;
      doc.head.appendChild(sc);
    };

    var open = function (from) {
      opener = from || null;
      box.hidden = false;
      root.classList.add('share-open');
      if (lenis) lenis.stop();
      else doc.body.style.overflow = 'hidden';
      loadQR();
      var x = $('.share__x', box);
      if (x) x.focus();
    };
    var close = function () {
      if (box.hidden) return;
      box.hidden = true;
      root.classList.remove('share-open');
      if (lenis) lenis.start();
      else doc.body.style.overflow = '';
      if (opener && opener.focus) opener.focus();
    };
    $$('[data-share]').forEach(function (b) {
      b.addEventListener('click', function () { open(b); });
      b.setAttribute('data-track', 'share');
    });
    $$('[data-share-close]', box).forEach(function (b) { b.addEventListener('click', close); });
    doc.addEventListener('keydown', function (e) {
      if (box.hidden) return;
      if (e.key === 'Escape') close();
      if (e.key === 'Tab') {
        // إبقاء التنقل بلوحة المفاتيح داخل النافذة
        var f = $$('button:not([hidden]), a[href]', box);
        if (!f.length) return;
        var first = f[0];
        var lastEl = f[f.length - 1];
        if (e.shiftKey && doc.activeElement === first) { e.preventDefault(); lastEl.focus(); }
        else if (!e.shiftKey && doc.activeElement === lastEl) { e.preventDefault(); first.focus(); }
      }
    });

    // نسخ الرابط
    var copyBtn = $('[data-copy]', box);
    if (copyBtn) {
      var label = $('b', copyBtn);
      copyBtn.addEventListener('click', function () {
        var ok = function () {
          copyBtn.classList.add('is-done');
          if (label) label.textContent = (data.ui && data.ui.copied) || '✓';
          setTimeout(function () {
            copyBtn.classList.remove('is-done');
            if (label) label.textContent = (data.ui && data.ui.copy) || 'Copy';
          }, 1800);
        };
        var fallback = function () {
          var t = doc.createElement('textarea');
          t.value = cfg.url;
          t.setAttribute('readonly', '');
          t.style.position = 'fixed';
          t.style.opacity = '0';
          doc.body.appendChild(t);
          t.select();
          try { doc.execCommand('copy'); ok(); } catch (err) { /* */ }
          t.remove();
        };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(cfg.url).then(ok, fallback);
        else fallback();
      });
    }

    // تحميل كود QR كصورة PNG بخلفية بيضاء والشعار في الوسط
    var dl = $('[data-qr-download]', box);
    if (dl) {
      dl.addEventListener('click', function () {
        var svg = qrBox && qrBox.querySelector('svg');
        if (!svg) return;
        var size = 1024;
        var pad = 72;
        var canvas = doc.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, size, size);
        var img = new Image();
        img.onload = function () {
          ctx.imageSmoothingEnabled = false;
          ctx.drawImage(img, pad, pad, size - pad * 2, size - pad * 2);
          var logo = $('.share__logo svg', box);
          var finish = function () {
            canvas.toBlob(function (blob) {
              if (!blob) return;
              var a = doc.createElement('a');
              a.href = URL.createObjectURL(blob);
              a.download = cfg.file || 'qr.png';
              doc.body.appendChild(a);
              a.click();
              setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
            }, 'image/png');
          };
          if (!logo) { finish(); return; }
          var cs = getComputedStyle(root);
          var clone = logo.cloneNode(true);
          clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
          $$('.mark__a', clone).forEach(function (el) { el.setAttribute('fill', cs.getPropertyValue('--red').trim() || '#c8202b'); });
          $$('.mark__b', clone).forEach(function (el) { el.setAttribute('fill', cs.getPropertyValue('--red-2').trim() || '#a3141e'); });
          var limg = new Image();
          limg.onload = function () {
            var b = 190;
            ctx.imageSmoothingEnabled = true;
            ctx.fillStyle = '#ffffff';
            ctx.fillRect((size - b) / 2, (size - b) / 2, b, b);
            ctx.drawImage(limg, (size - 120) / 2, (size - 120) / 2, 120, 120);
            finish();
          };
          limg.onerror = finish;
          limg.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(clone));
        };
        img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(svg));
      });
    }
  });

  /* ───────── الإحصائيات المتقدمة: ملخص واحد لكل زيارة يُرسل عند مغادرة الصفحة ───────── */
  safe(function () {
    if (!FX.analytics || !navigator.sendBeacon) return;
    var visit = { s: {}, d: 0, t: 0, l: data.lang || 'en', f: { v: 0, s: 0, ok: 0, last: '' } };
    var tz = '';
    try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { tz = ''; }
    var shownAt = doc.hidden ? 0 : nowMs();
    var activeMs = 0;
    var sent = false;

    // الأقسام المقروءة: ظاهرة 40% على الأقل لأكثر من ثانية
    if ('IntersectionObserver' in window) {
      var timers = {};
      var rio = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          var key = en.target.getAttribute('data-read');
          // الأقسام الأطول من الشاشة: يكفي أن تملأ جزءاً كبيراً منها
          var big = en.intersectionRect.height >= window.innerHeight * 0.45;
          if (en.isIntersecting && (en.intersectionRatio >= 0.4 || big)) {
            if (!timers[key] && !visit.s[key]) timers[key] = setTimeout(function () { visit.s[key] = 1; }, 1100);
          } else if (timers[key]) {
            clearTimeout(timers[key]);
            timers[key] = 0;
          }
        });
      }, { threshold: [0, 0.1, 0.25, 0.4, 0.6, 0.8, 1] });
      $$('[data-read]').forEach(function (el) { rio.observe(el); });

      // النموذج: شاهده؟
      var form = $('form.form');
      if (form) {
        new IntersectionObserver(function (es) {
          if (es[0].isIntersecting) visit.f.v = 1;
        }, { threshold: 0.5 }).observe(form);
      }
    }

    // عمق التمرير
    onScroll(function () {
      var max = doc.documentElement.scrollHeight;
      var d = Math.round(((window.scrollY + window.innerHeight) / max) * 100);
      if (d > visit.d) visit.d = Math.min(100, d);
    });

    // النموذج: بدأ الكتابة؟ وأي خانة كانت الأخيرة؟
    var f2 = $('form.form');
    if (f2) {
      f2.addEventListener('focusin', function (e) {
        var n = e.target && e.target.name;
        if (n && n !== 'website' && n !== 'lang') visit.f.last = n;
      });
      f2.addEventListener('input', function (e) {
        var n = e.target && e.target.name;
        if (n && n !== 'website') { visit.f.s = 1; visit.f.last = n; }
      });
      f2.addEventListener('submit', function () {
        // إرسال صحيح = الاسم والتفاصيل مكتوبان (نفس شرط النموذج). يُعلَّم فوراً لأن واتساب يفتح في نافذة جديدة
        var nm = f2.elements.namedItem('name');
        var dt = f2.elements.namedItem('details');
        if (nm && dt && String(nm.value).trim() && String(dt.value).trim()) visit.f.ok = 1;
      });
    }

    var send = function () {
      if (sent) return;
      if (shownAt) { activeMs += nowMs() - shownAt; shownAt = 0; }
      sent = true;
      var payload = {
        tz: tz,
        s: Object.keys(visit.s),
        d: visit.d || Math.round(((window.scrollY + window.innerHeight) / doc.documentElement.scrollHeight) * 100),
        t: Math.round(activeMs),
        l: visit.l,
        f: visit.f
      };
      try { navigator.sendBeacon('track.php', new URLSearchParams({ e: 'visit', d: JSON.stringify(payload) })); } catch (err) { /* */ }
    };
    doc.addEventListener('visibilitychange', function () {
      if (doc.hidden) {
        if (shownAt) { activeMs += nowMs() - shownAt; shownAt = 0; }
        send();
      } else if (!sent) {
        shownAt = nowMs();
      }
    });
    window.addEventListener('pagehide', send);
  });
})();
