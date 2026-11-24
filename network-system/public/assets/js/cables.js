/*
 * فحص الكابلات: منافذ الجهاز، فحص منفذ، الفحص العميق، وعرض النتيجة:
 * رسم للكابل بأزواجه الأربعة وطوله ومكان القطع أو التماس بالمتر، والنتائج، وحالة الرابط وPoE، والعدّادات والانقطاعات.
 */
(function (window, document) {
  'use strict';
  var box = document.querySelector('[data-cb]');
  var modal = document.getElementById('m-cable');
  if (!box || !modal || !window.SN) { return; }
  var esc = SN.esc, icon = SN.icon, num = SN.fmtNum;
  var portsBox = box.querySelector('[data-cb-ports]');
  var pick = box.querySelector('[data-cb-router]');
  var canDeep = box.getAttribute('data-can-deep') === '1';
  var current = { router: pick ? +pick.value : 0, routerName: '', port: '', mode: 'test' };
  var busy = false;
  var LEVEL = { ok: ['سليم', 'circle-check'], warn: ['يحتاج انتباهاً', 'triangle-alert'], bad: ['خلل', 'circle-x'], info: ['معلومة', 'info'] };
  var metres = function (m) { return num(m) + ' م'; };

  // ---------------- المنافذ ----------------
  function portTile(p) {
    var state = p.disabled ? 'is-off' : (p.running ? 'is-up' : 'is-down');
    var label = p.disabled ? 'معطّل' : (p.running ? 'متصل' : 'لا رابط');
    var tags = (p.wan ? '<span class="badge badge-info">WAN</span>' : '') + (p.sfp ? '<span class="badge badge-outline">SFP</span>' : '');
    var meta = [];
    if (p.errors > 0) { meta.push('<span class="cb-port-err" title="أخطاء مسجّلة منذ تشغيل الجهاز">' + icon('triangle-alert', 'icon-sm') + num(p.errors) + ' خطأ</span>'); }
    if (p.link_downs > 0) { meta.push('<span title="مرات انقطاع الرابط منذ تشغيل الجهاز">' + icon('unplug', 'icon-sm') + num(p.link_downs) + ' انقطاع</span>'); }
    var last = p.last ? '<span class="cb-port-last is-' + esc(p.last.level) + '"><i></i>' + esc(LEVEL[p.last.level][0]) + ' · ' + esc(p.last.ago) + '</span>'
      : '<span class="cb-port-last is-none"><i></i>لم يُفحص بعد</span>';
    return '<button type="button" class="cb-port ' + state + '" data-port="' + esc(p.name) + '" title="' + esc(p.last ? p.last.summary : 'فحص ' + p.name) + '">'
      + '<span class="cb-jack" aria-hidden="true"><i></i></span>'
      + '<span class="cb-port-top"><bdi class="cb-port-name">' + esc(p.name) + '</bdi>' + tags + '</span>'
      + (p.comment ? '<span class="cb-port-note">' + esc(p.comment) + '</span>' : '')
      + peerLine(p.peer)
      + '<span class="cb-port-state"><i></i>' + label + '</span>'
      + (meta.length ? '<span class="cb-port-meta">' + meta.join('') + '</span>' : '')
      + last + '</button>';
  }
  // سطر «إلى أين يذهب الكابل» في بطاقة المنفذ
  function peerLine(peer) {
    if (!peer) { return ''; }
    if (peer.kind === 'many') { return '<span class="cb-port-peer is-many">' + icon('users', 'icon-sm') + '<span>خلفه ' + num(peer.macs) + ' جهازاً (مبدّل أو شبكة)</span></span>'; }
    return '<span class="cb-port-peer' + (peer.remembered ? ' is-old' : '') + '" title="' + esc(peer.remembered ? 'آخر جهاز عُرف على هذا المنفذ' : 'الجهاز على الطرف الآخر') + '">'
      + icon(peer.system ? 'router' : 'arrow-left-right', 'icon-sm') + '<span><b>' + esc(peer.name) + '</b>' + (peer.remote_port ? ' · <bdi class="cb-mono">' + esc(peer.remote_port) + '</bdi>' : '') + '</span></span>';
  }
  function loadPorts() {
    if (!pick) { return; }
    current.router = +pick.value;
    var err = box.querySelector('[data-cb-ports-error]');
    err.hidden = true;
    portsBox.classList.add('is-loading');
    SN.api(box.getAttribute('data-ports') + '?router=' + current.router, { silent: true })
      .then(function (r) {
        current.routerName = r.router.name;
        portsBox.innerHTML = r.ports.length ? r.ports.map(portTile).join('')
          : '<div class="empty">' + icon('ethernet-port') + '<div class="empty-title">لا توجد منافذ Ethernet على هذا الجهاز</div></div>';
      })
      .catch(function (e) {
        portsBox.innerHTML = '';
        box.querySelector('[data-cb-ports-msg]').textContent = e.message;
        err.hidden = false;
      })
      .then(function () { portsBox.classList.remove('is-loading'); });
    try { history.replaceState(null, '', location.pathname + '?router=' + current.router); } catch (e) { /* تجاهل */ }
  }
  if (pick) {
    pick.addEventListener('change', loadPorts);
    box.querySelector('[data-cb-reload]').addEventListener('click', loadPorts);
    loadPorts();
  }
  portsBox && portsBox.addEventListener('click', function (e) {
    var t = e.target.closest('.cb-port[data-port]');
    if (t) { run(t.getAttribute('data-port'), 'test'); }
  });

  // ---------------- النافذة ----------------
  function state(s, mode) {
    modal.querySelector('[data-cb-loading]').hidden = s !== 'loading';
    modal.querySelector('[data-cb-error]').hidden = s !== 'error';
    modal.querySelector('[data-cb-result]').hidden = s !== 'done';
    modal.querySelector('[data-cb-again]').hidden = s === 'loading' || !current.port || current.saved;
    if (s === 'loading') {
      modal.querySelector('[data-cb-loading-title]').textContent = mode === 'deep' ? 'فحص عميق: يقيس الأزواج' : 'يقرأ حالة المنفذ';
      modal.querySelector('[data-cb-loading-note]').textContent = mode === 'deep'
        ? 'يفصل المنفذ ثانيتين ليقيس طول الكابل ومكان القطع أو التماس، ثم يعيده فوراً'
        : 'حالة الرابط والسرعة وتغذية PoE وعدّادات الأخطاء ومرات الانقطاع · قراءة فقط';
      modal.querySelector('.cb-loading').classList.toggle('is-deep', mode === 'deep');
    }
  }
  function title(port, extra) {
    modal.querySelector('[data-cb-title]').innerHTML = 'فحص المنفذ <bdi class="cb-mono">' + esc(port) + '</bdi>' + (extra ? ' · ' + esc(extra) : '');
  }
  function run(port, mode) {
    if (busy) { return; }
    busy = true;
    current.port = port; current.mode = mode; current.saved = false;
    title(port, current.routerName);
    state('loading', mode);
    if (!modal.open) { SN.openModal(modal); }
    var url = box.getAttribute(mode === 'deep' ? 'data-deep' : 'data-test');
    SN.api(url, { method: 'POST', data: { router_id: current.router, port: port, confirm: mode === 'deep' ? 1 : 0 }, silent: true })
      .then(function (r) { render(r.result); state('done'); })
      .catch(function (e) { modal.querySelector('[data-cb-error-msg]').textContent = e.message; state('error'); })
      .then(function () { busy = false; refreshTile(); });
  }
  // بعد الفحص تتحدّث بطاقة آخر فحص دون إعادة تحميل الصفحة
  function refreshTile() {
    if (!pick || !current.port) { return; }
    SN.api(box.getAttribute('data-ports') + '?router=' + current.router, { silent: true }).then(function (r) {
      portsBox.innerHTML = r.ports.map(portTile).join('');
    }).catch(function () {});
  }
  modal.querySelector('[data-cb-again]').addEventListener('click', function () { if (current.port) { run(current.port, 'test'); } });
  document.addEventListener('click', function (e) {
    var h = e.target.closest('[data-cb-open]');
    if (!h) { return; }
    var id = h.getAttribute('data-cb-open');
    current.saved = true; current.port = '';
    title('…');
    state('loading');
    SN.openModal(modal);
    SN.api(box.getAttribute('data-check').replace(/0$/, id), { silent: true })
      .then(function (r) { title(r.result.port, r.result.router); render(r.result); state('done'); })
      .catch(function (err) { modal.querySelector('[data-cb-error-msg]').textContent = err.message; state('error'); });
  });

  // ---------------- العرض ----------------
  function kv(rows) {
    return '<dl class="cb-kv">' + rows.map(function (r) { return '<div><dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd></div>'; }).join('') + '</dl>';
  }
  function section(ic, head, body, extra) {
    return '<section class="cb-sec' + (extra ? ' ' + extra : '') + '"><h4>' + icon(ic) + esc(head) + '</h4>' + body + '</section>';
  }
  // رسم الكابل: الجهاز في جهة البداية، والطرف الآخر في جهة النهاية، وكل زوج بطوله المقاس وعلامة القطع أو التماس
  function cable(r) {
    var pairs = r.pairs || [];
    var dists = pairs.map(function (p) { return p.m; }).filter(function (m) { return m !== null && m !== undefined; });
    var L = Math.max.apply(null, dists.concat([r.length || 0, 0]));
    var scaled = L > 2;
    var rows = pairs.map(function (p) {
      var pct = scaled && p.m !== null && p.m !== undefined ? Math.max(2, Math.min(100, p.m / L * 100)) : 100;
      var cut = p.cls === 'bad' && p.state === 'open';
      var shortP = p.state === 'short';
      var fill = p.cls === 'muted' ? 100 : pct;
      var mark = '';
      if ((cut || shortP) && p.m !== null) {
        mark = '<span class="cb-mark ' + (shortP ? 'is-short' : 'is-cut') + '" style="inset-inline-start:' + pct + '%">' + icon(shortP ? 'zap' : 'scissors', 'icon-sm') + '<em>' + metres(p.m) + '</em></span>';
      } else if (p.state === 'open' && p.m !== null && p.cls !== 'muted') {
        // نهاية الزوج: علامة، ومسافتها مكتوبة إلا إذا كانت طول الكابل نفسه (مذكور تحت الرسم)
        mark = '<span class="cb-mark is-end" style="inset-inline-start:' + pct + '%">' + (p.m === r.length ? '' : '<em>' + metres(p.m) + '</em>') + '</span>';
      }
      var dist = p.m !== null && p.m !== undefined ? ' · ' + metres(p.m) : '';
      return '<div class="cb-pair is-' + esc(p.cls) + '">'
        + '<div class="cb-pair-info"><i class="cb-pair-dot" style="--c:' + esc(p.hex) + '"></i><b>الزوج ' + num(p.no) + '</b><span>' + esc(p.color) + ' · <bdi>' + esc(p.pins) + '</bdi></span></div>'
        + '<div class="cb-wire' + (cut || shortP ? ' has-fault' : '') + '" style="--c:' + esc(p.hex) + '"><span class="cb-wire-fill" style="width:' + fill + '%"></span>' + mark + '</div>'
        + '<div class="cb-pair-state">' + esc(p.text) + dist + '</div></div>';
    }).join('');
    var scale = scaled ? '<div class="cb-scale" aria-hidden="true"><span>0 م</span><span>' + metres(Math.round(L / 2)) + '</span><span>' + metres(L) + '</span></div>' : '';
    var lenLine = r.length ? '<div class="cb-length">' + icon('ruler') + '<span>طول الكابل</span><b>' + metres(r.length) + '</b></div>'
      : (r.prev_length ? '<div class="cb-length is-prev">' + icon('ruler') + '<span>آخر طول مقاس لهذا الكابل</span><b>' + metres(r.prev_length) + '</b></div>' : '');
    return '<div class="cb-cable"><div class="cb-ends"><span>' + icon('router', 'icon-sm') + 'الجهاز · <bdi class="cb-mono">' + esc(r.port) + '</bdi></span><span>الطرف الآخر' + icon('ethernet-port', 'icon-sm') + '</span></div>'
      + rows + scale + '</div>' + lenLine;
  }
  // الأسلاك الثمانية كما تُرى في الرأس (الطرف 1 يساراً، معيار T568B)، وتحتها حالة كل سلك
  function wires(list) {
    var mark = { bad: 'circle-x', warn: 'triangle-alert', ok: 'circle-check', muted: 'minus' };
    var cols = list.map(function (w) {
      return '<div class="cb-w is-' + esc(w.cls) + '" style="--c:' + esc(w.hex) + '" title="' + esc('السلك ' + w.pin + ' · ' + w.color + ' · ' + w.text) + '">'
        + '<span class="cb-w-pin">' + num(w.pin) + '</span><i class="cb-w-line' + (w.striped ? ' is-striped' : '') + '"></i>'
        + '<span class="cb-w-mark">' + icon(mark[w.cls] || 'minus', 'icon-sm') + '</span></div>';
    }).join('');
    var rows = list.map(function (w) {
      return '<li class="is-' + esc(w.cls) + '"><i class="cb-w-chip' + (w.striped ? ' is-striped' : '') + '" style="--c:' + esc(w.hex) + '"></i>'
        + '<b>السلك ' + num(w.pin) + '</b><span class="cb-w-color">' + esc(w.color) + ' · الزوج ' + num(w.pair) + '</span><em>' + esc(w.text) + '</em></li>';
    }).join('');
    return '<div class="cb-rj"><div class="cb-rj-wires" dir="ltr">' + cols + '</div><div class="cb-rj-plug" aria-hidden="true"><span>RJ45 · T568B</span></div></div>'
      + '<ul class="cb-w-list">' + rows + '</ul>'
      + '<p class="cb-w-note">' + icon('info', 'icon-sm') + '<span>يقيس الجهاز كل زوج (سلكين ملتفّين) معاً: إذا انقطع أحد السلكين ظهر القطع على الاثنين لأن الجهاز لا يميّز بينهما، والتماس يعني أن سلكي الزوج متلامسان عند المسافة المذكورة.</span></p>';
  }
  function counters(r) {
    var list = r.counters || [];
    if (!list.length) { return ''; }
    var hot = function (c) { return c.group !== 'other' && ((c.now || 0) > 0 || (c.since || 0) > 0); };
    var shown = list.filter(function (c) { return c.total > 0 || hot(c); });
    var cell = function (v, isHot) { return v === null || v === undefined ? '<td class="text-muted">—</td>' : '<td class="' + (v > 0 && isHot ? 'is-up' : '') + '"><bdi>' + (v > 0 ? '+' : '') + num(v) + '</bdi></td>'; };
    var row = function (c) {
      return '<tr class="' + (hot(c) ? 'is-hot' : '') + '"><th><span>' + esc(c.label) + '</span><bdi class="cb-mono">' + esc(c.key) + '</bdi></th><td><bdi>' + num(c.total) + '</bdi></td>'
        + cell(c.now, true) + cell(c.since, true) + '</tr>';
    };
    var head = '<thead><tr><th>العدّاد</th><th>منذ التشغيل</th><th>أثناء الفحص</th><th>منذ الفحص السابق</th></tr></thead>';
    var body = shown.length ? '<div class="table-wrap"><table class="table cb-counters">' + head + '<tbody>' + shown.map(row).join('') + '</tbody></table></div>'
      : '<p class="cb-clean">' + icon('circle-check', 'icon-sm') + 'لا أخطاء مسجّلة على المنفذ منذ تشغيل الجهاز.</p>';
    var rest = list.filter(function (c) { return shown.indexOf(c) === -1; });
    if (rest.length) {
      body += '<details class="cb-more"><summary>كل العدّادات (' + num(list.length) + ')</summary><div class="table-wrap"><table class="table cb-counters">' + head + '<tbody>' + list.map(row).join('') + '</tbody></table></div></details>';
    }
    return section('activity', 'عدّادات الأخطاء', body);
  }
  function deepBox(r) {
    var o = r.deep_offer;
    if (!o) { return ''; }
    if (!o.ok) {
      return '<div class="cb-deep is-blocked">' + icon('shield-check') + '<div><b>قياس الطول ومكان العطل غير متاح على هذا المنفذ</b><p>' + esc(o.reason) + '</p></div></div>';
    }
    if (!canDeep) { return ''; }
    return '<div class="cb-deep">' + icon('ruler') + '<div><b>قِس طول الكابل ومكان أي قطع أو تماس بالمتر</b>'
      + '<p>الرابط قائم، والجهاز لا يقيس الأزواج إلا والرابط مفصول. الفحص العميق يفصل المنفذ ثانيتين ثم يعيده فوراً، ويضبط على الجهاز مؤقّت أمان يعيده تلقائياً خلال 45 ثانية حتى لو انقطع الاتصال.</p>'
      + (o.poe ? '<p class="cb-deep-warn">' + icon('zap', 'icon-sm') + 'هذا المنفذ يغذّي الجهاز الآخر بالكهرباء (PoE): سينطفئ ويقلع من جديد، فيعود خلال دقيقة أو دقيقتين.</p>'
        : '<p class="cb-deep-warn">' + icon('triangle-alert', 'icon-sm') + 'ينقطع الاتصال عن الجهاز الموصول بهذا المنفذ ثوانيَ معدودة.</p>')
      + '<button type="button" class="btn btn-primary" data-cb-deep>' + icon('ruler') + 'فحص عميق</button></div></div>';
  }
  // مسار الكابل: من هذا الجهاز ومنفذه إلى الجهاز الآخر ومنفذه
  function route(r) {
    var peer = r.peer;
    var here = '<div class="cb-end"><span class="cb-end-icon">' + icon('router') + '</span><div><b>' + esc(r.router || current.routerName) + '</b><span>المنفذ <bdi class="cb-mono">' + esc(r.port) + '</bdi>'
      + (r.comment ? ' · ' + esc(r.comment) : '') + '</span></div></div>';
    var far;
    if (peer && peer.kind === 'many') {
      far = '<div class="cb-end is-far"><span class="cb-end-icon">' + icon('users') + '</span><div><b>' + num(peer.macs) + ' جهازاً خلف المنفذ</b><span>مبدّل (سويتش) أو شبكة كاملة</span></div></div>';
    } else if (peer) {
      var nameHtml = peer.system ? '<a href="' + esc(SN.base + '/routers/' + peer.system.id) + '">' + esc(peer.name) + '</a>' : esc(peer.name);
      far = '<div class="cb-end is-far' + (peer.remembered ? ' is-old' : '') + '"><span class="cb-end-icon">' + icon(peer.kind === 'host' ? 'smartphone' : 'router') + '</span><div><b>' + nameHtml + '</b><span>'
        + (peer.remote_port ? 'المنفذ <bdi class="cb-mono">' + esc(peer.remote_port) + '</bdi>' : (peer.address ? '<bdi class="cb-mono">' + esc(peer.address) + '</bdi>' : ''))
        + (peer.remembered ? ' · آخر ما عُرف عليه' : '') + '</span></div></div>';
    } else {
      far = '<div class="cb-end is-far is-unknown"><span class="cb-end-icon">' + icon('circle-help') + '</span><div><b>الطرف الآخر غير معروف</b><span>لا يعلن جهازه عن نفسه (MNDP/LLDP/CDP)</span></div></div>';
    }
    var len = r.length ? metres(r.length) : (r.prev_length ? metres(r.prev_length) : '');
    return '<div class="cb-route">' + here + '<div class="cb-route-line is-' + esc(r.level) + '"><i></i>' + (len ? '<em>' + icon('ruler', 'icon-sm') + len + '</em>' : '') + '</div>' + far + '</div>';
  }
  function peerInfo(peer) {
    if (!peer || peer.kind === 'many') { return ''; }
    var rows = [];
    if (peer.system) { rows.push(['جهاز في النظام', peer.system.name]); }
    if (peer.identity && peer.identity !== peer.name) { rows.push(['اسم الجهاز (identity)', peer.identity]); }
    if (peer.model) { rows.push(['الطراز', peer.model]); }
    if (peer.platform || peer.version) { rows.push(['النظام', [peer.platform, peer.version].filter(Boolean).join(' ')]); }
    if (peer.remote_port) { rows.push(['منفذه', peer.remote_port]); }
    if (peer.address) { rows.push(['العنوان', peer.address]); }
    if (peer.mac) { rows.push(['MAC', peer.mac]); }
    if (peer.via) { rows.push(['عُرف عبر', peer.via]); }
    if (peer.others > 0) { rows.push(['أجهزة أخرى مكتشفة على المنفذ', num(peer.others)]); }
    if (peer.remembered) { rows.push(['آخر ظهور', String(peer.seen || '').slice(0, 16)]); }
    return rows.length ? section(peer.kind === 'host' ? 'smartphone' : 'router', peer.remembered ? 'الطرف الآخر (آخر ما عُرف)' : 'الطرف الآخر', kv(rows)) : '';
  }
  function render(r) {
    var lv = LEVEL[r.level] || LEVEL.ok;
    var sub = [r.router || current.routerName, r.saved ? r.saved.ago : 'الآن'];
    if (r.deep) { sub.push('فحص عميق'); }
    if (r.saved && r.saved.source === 'auto') { sub.push('فحص دوري'); }
    if (r.comment) { sub.unshift(r.comment); }
    var html = '<div class="cb-verdict is-' + esc(r.level) + '"><span class="cb-verdict-icon">' + icon(lv[1]) + '</span><div><span class="cb-verdict-level">' + esc(lv[0]) + '</span><b>'
      + esc(r.headline) + '</b><span class="cb-verdict-sub">' + sub.filter(Boolean).map(esc).join(' · ') + '</span></div></div>';
    html += route(r);
    if (r.pairs && r.pairs.length) {
      html += section('cable', 'الأزواج والطول', cable(r));
      if (r.wires && r.wires.length) { html += section('ethernet-port', 'الأسلاك الثمانية في الرأس', wires(r.wires)); }
    }
    var findings = (r.findings || []).filter(function (f, i) { return !(i === 0 && f.text === r.headline && r.level !== 'ok'); });
    if (findings.length) {
      html += section('scan-line', 'التشخيص', '<ul class="cb-findings">' + findings.map(function (f) {
        return '<li class="is-' + esc(f.level) + '">' + icon((LEVEL[f.level] || LEVEL.info)[1], 'icon-sm') + '<span>' + esc(f.text) + '</span></li>';
      }).join('') + '</ul>');
    }
    html += deepBox(r);
    var side = peerInfo(r.peer);
    if (r.link && r.link.length) { side += section('gauge', 'حالة الرابط', kv(r.link)); }
    if (r.sfp && r.sfp.length) { side += section('zap', 'وحدة SFP', kv(r.sfp)); }
    if (r.links && r.links.length) { side += section('unplug', 'انقطاعات الرابط', kv(r.links)); }
    if (side) { html += '<div class="cb-cols">' + side + '</div>'; }
    html += counters(r);
    var out = modal.querySelector('[data-cb-result]');
    out.innerHTML = html;
    var deep = out.querySelector('[data-cb-deep]');
    if (deep) {
      deep.addEventListener('click', function () {
        SN.confirm('فحص عميق للمنفذ ' + r.port + ': سيُفصل المنفذ ثانيتين ثم يعاد' + (r.deep_offer.poe ? '، والجهاز الذي يغذّيه سيقلع من جديد (دقيقة أو دقيقتان).' : '.') + ' متابعة؟',
          { okText: 'افحص الآن', danger: false })
          .then(function (yes) { if (yes) { run(r.port, 'deep'); } });
      });
    }
    var body = modal.querySelector('.modal-body');
    if (body) { body.scrollTop = 0; }
  }
})(window, document);
