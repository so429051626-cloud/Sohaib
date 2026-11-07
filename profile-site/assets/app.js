(function () {
  'use strict';

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ───────── الشريط العلوي عند التمرير ───────── */
  var nav = document.querySelector('.nav');
  function onScroll() {
    if (nav) nav.classList.toggle('is-scrolled', window.scrollY > 8);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ───────── الظهور التدريجي ───────── */
  var reveals = document.querySelectorAll('.reveal');
  document.querySelectorAll('.grid').forEach(function (g) {
    Array.prototype.forEach.call(g.children, function (el, i) {
      el.style.setProperty('--d', (i * 0.08).toFixed(2) + 's');
    });
  });
  document.querySelectorAll('.hero .reveal').forEach(function (el, i) {
    el.style.setProperty('--d', (i * 0.07).toFixed(2) + 's');
  });
  if ('IntersectionObserver' in window && !reduce) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) {
          en.target.classList.add('in');
          io.unobserve(en.target);
        }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    reveals.forEach(function (el) { io.observe(el); });
  } else {
    reveals.forEach(function (el) { el.classList.add('in'); });
  }
  window.__revealOk = true;

  /* ───────── الكود الذي يُكتب تلقائياً ───────── */
  var KW = /^(?:const|let|var|function|return|new|await|async|class|this|true|false|null|undefined|import|export|from|if|else|for|while|of|in)\b/;

  function tokenize(line) {
    var out = [];
    var i = 0;
    while (i < line.length) {
      var rest = line.slice(i);
      var m;
      if (rest.indexOf('//') === 0) {
        out.push(['c', rest]);
        break;
      }
      if ((m = rest.match(/^(["'`])(?:\\.|(?!\1)[^\\])*\1?/))) out.push(['s', m[0]]);
      else if ((m = rest.match(KW))) out.push(['k', m[0]]);
      else if ((m = rest.match(/^\d+(?:\.\d+)?/))) out.push(['n', m[0]]);
      else if ((m = rest.match(/^[A-Za-z_$][\w$]*(?=\s*:)/))) out.push(['p', m[0]]);
      else if ((m = rest.match(/^[A-Za-z_$][\w$]*(?=\s*\()/))) out.push(['f', m[0]]);
      else if ((m = rest.match(/^[A-Za-z_$][\w$]*/))) out.push(['', m[0]]);
      else if ((m = rest.match(/^[{}\[\]();,.:=<>+\-*\/!&|?@#%^~]+/))) out.push(['o', m[0]]);
      else if ((m = rest.match(/^\s+/))) out.push(['', m[0]]);
      else out.push(['', String.fromCodePoint(rest.codePointAt(0))]);
      i += out[out.length - 1][1].length;
    }
    return out;
  }

  function esc(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  // سلسلة أحرف ملوّنة → HTML (الأحرف المتتالية بنفس اللون في span واحد)
  function paint(chars) {
    var html = '';
    var cur = null;
    var buf = '';
    for (var i = 0; i <= chars.length; i++) {
      var cls = i < chars.length ? chars[i][0] : null;
      if (i === chars.length || cls !== cur) {
        if (buf) html += cur ? '<span class="t-' + cur + '">' + esc(buf) + '</span>' : esc(buf);
        buf = '';
        cur = cls;
      }
      if (i < chars.length) buf += chars[i][1];
    }
    return html;
  }

  function charsOf(line) {
    var list = [];
    tokenize(line).forEach(function (t) {
      Array.from(t[1]).forEach(function (ch) { list.push([t[0], ch]); });
    });
    return list;
  }

  var codeOut = document.getElementById('code-out');
  var codeSrc = document.getElementById('code-src');
  if (codeOut && codeSrc) {
    var lines = [];
    try { lines = JSON.parse(codeSrc.textContent) || []; } catch (e) { lines = []; }
    while (lines.length && lines[lines.length - 1].trim() === '') lines.pop();
    var prepared = lines.map(charsOf);

    function lineHtml(n, inner) {
      return '<span class="ln" data-n="' + n + '">' + inner + '</span>';
    }

    // نسخة كاملة مخفية تحجز الارتفاع النهائي، فلا تقفز الصفحة أثناء الكتابة
    var ghost = document.createElement('code');
    ghost.className = 'ghost';
    ghost.setAttribute('aria-hidden', 'true');
    ghost.innerHTML = prepared.map(function (c, i) { return lineHtml(i + 1, paint(c)); }).join('');
    codeOut.parentNode.insertBefore(ghost, codeOut);
    codeOut.parentNode.classList.add('is-typing');

    var cursor = '<span class="cursor"></span>';

    if (reduce || !prepared.length) {
      codeOut.innerHTML = prepared.map(function (c, i) {
        return lineHtml(i + 1, paint(c) + (i === prepared.length - 1 ? cursor : ''));
      }).join('');
    } else {
      var li = 0;
      var ci = 0;
      var done = '';
      codeOut.innerHTML = lineHtml(1, cursor);
      var tick = function () {
        if (li >= prepared.length) return;
        var line = prepared[li];
        ci++;
        if (ci > line.length) {
          done += lineHtml(li + 1, paint(line));
          li++;
          ci = 0;
          if (li >= prepared.length) {
            codeOut.innerHTML = done.replace(/<\/span>$/, cursor + '</span>');
            return;
          }
          codeOut.innerHTML = done + lineHtml(li + 1, cursor);
          setTimeout(tick, 260);
          return;
        }
        codeOut.innerHTML = done + lineHtml(li + 1, paint(line.slice(0, ci)) + cursor);
        var ch = line[ci - 1] ? line[ci - 1][1] : '';
        var delay = 26 + Math.random() * 46;
        if (/[,;{(]/.test(ch)) delay += 90;
        if (ch === ' ' && ci < 4) delay = 8;
        setTimeout(tick, delay);
      };
      // تبدأ الكتابة عندما تظهر نافذة الكود على الشاشة (على الجوال تكون أسفل الصفحة)
      var start = function () { setTimeout(tick, 400); };
      if ('IntersectionObserver' in window) {
        var tio = new IntersectionObserver(function (es) {
          if (es[0].isIntersecting) {
            tio.disconnect();
            start();
          }
        }, { threshold: 0.3 });
        tio.observe(codeOut.parentNode);
      } else {
        start();
      }
    }
  }

  /* ───────── خلفية الكود المتساقط ───────── */
  var canvas = document.getElementById('bg-code');
  if (canvas && canvas.getContext) {
    var ctx = canvas.getContext('2d');
    var glyphs = '01{}[]()<>/=;:+*&|!?#$%constletfnreturnifelsePOSAIawaitasync=>'.split('');
    var size = 15;
    var cols = [];
    var w = 0;
    var h = 0;
    var dpr = 1;

    var setup = function () {
      dpr = Math.min(window.devicePixelRatio || 1, 2);
      w = window.innerWidth;
      h = window.innerHeight;
      canvas.width = Math.floor(w * dpr);
      canvas.height = Math.floor(h * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.fillStyle = '#060a13';
      ctx.fillRect(0, 0, w, h);
      var n = Math.ceil(w / (size * 1.6));
      cols = [];
      for (var i = 0; i < n; i++) {
        cols.push({
          x: i * size * 1.6,
          y: Math.random() * -h,
          speed: 0.35 + Math.random() * 0.75,
          active: Math.random() < 0.42
        });
      }
    };

    var pick = function () {
      return glyphs[(Math.random() * glyphs.length) | 0];
    };

    var drawStatic = function () {
      ctx.font = size + 'px "JetBrains Mono", monospace';
      for (var i = 0; i < (w * h) / 5000; i++) {
        ctx.fillStyle = Math.random() < 0.15 ? 'rgba(34,211,238,0.35)' : 'rgba(100,116,139,0.25)';
        ctx.fillText(pick(), Math.random() * w, Math.random() * h);
      }
    };

    var last = 0;
    var running = true;
    var frame = function (t) {
      if (!running) return;
      requestAnimationFrame(frame);
      if (t - last < 50) return;
      last = t;
      ctx.fillStyle = 'rgba(6,10,19,0.16)';
      ctx.fillRect(0, 0, w, h);
      ctx.font = size + 'px "JetBrains Mono", monospace';
      for (var i = 0; i < cols.length; i++) {
        var c = cols[i];
        if (!c.active) continue;
        var head = Math.random() < 0.08;
        ctx.fillStyle = head ? 'rgba(165,243,252,0.9)' : (i % 5 === 0 ? 'rgba(139,92,246,0.55)' : 'rgba(34,211,238,0.42)');
        ctx.fillText(pick(), c.x, c.y);
        c.y += size * c.speed;
        if (c.y > h + size) {
          c.y = -size * (2 + Math.random() * 20);
          c.speed = 0.35 + Math.random() * 0.75;
          c.active = Math.random() < 0.5;
        }
      }
    };

    setup();
    if (reduce) {
      drawStatic();
    } else {
      requestAnimationFrame(frame);
      document.addEventListener('visibilitychange', function () {
        running = !document.hidden;
        if (running) requestAnimationFrame(frame);
      });
    }
    var rt;
    var lastW = window.innerWidth;
    window.addEventListener('resize', function () {
      clearTimeout(rt);
      rt = setTimeout(function () {
        // على الجوال يتغيّر الارتفاع مع شريط العنوان؛ نعيد الرسم فقط عند تغيّر العرض أو فرق كبير
        if (Math.abs(window.innerWidth - lastW) < 2 && Math.abs(window.innerHeight - h) < 140) return;
        lastW = window.innerWidth;
        setup();
        if (reduce) drawStatic();
      }, 200);
    });
  }

  /* ───────── تسجيل النقرات على الروابط ───────── */
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('[data-track]') : null;
    if (!a) return;
    var body = new URLSearchParams({ k: a.getAttribute('data-track') });
    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon('track.php', body);
      } else {
        fetch('track.php', { method: 'POST', body: body, keepalive: true });
      }
    } catch (err) { /* الإحصائيات لا تعطّل الرابط أبداً */ }
  });
})();
