/*!
 * MADIX — نافذة بوت خدمة الزبائن للموقع
 * تُضاف بسطر واحد:
 *   <script src=".../assets/widget/bot-widget.js" data-endpoint="https://.../api/bot/KEY" data-title="..." defer></script>
 * خيارات: data-mode="page" مع data-target (صفحة كاملة) · data-side="right" · data-color="#4f46e5"
 * مكتوبة بصيغة ES5 لتعمل على المتصفحات القديمة، ولا تحتاج أي مكتبة.
 */
(function (w, d) {
  'use strict';
  var script = d.currentScript || (function () {
    var all = d.getElementsByTagName('script');
    for (var i = all.length - 1; i >= 0; i--) {
      if (all[i].getAttribute('data-endpoint')) { return all[i]; }
    }
    return null;
  })();
  if (!script || w.__mxbLoaded) { return; }
  w.__mxbLoaded = true;

  var endpoint = (script.getAttribute('data-endpoint') || '').replace(/\/+$/, '');
  if (!endpoint) { return; }
  var title = script.getAttribute('data-title') || 'خدمة الزبائن';
  var mode = script.getAttribute('data-mode') === 'page' ? 'page' : 'float';
  var side = script.getAttribute('data-side') === 'right' ? 'right' : 'left';
  var color = /^#[0-9a-f]{3,8}$/i.test(script.getAttribute('data-color') || '') ? script.getAttribute('data-color') : '#4f46e5';
  var greeting = script.getAttribute('data-greeting') || 'أهلاً بك! اكتب سؤالك وسنرد عليك.';

  // ---------- هوية الزائر (تبقى في متصفحه) ----------
  var storeKey = 'mxb:' + endpoint.slice(-32);
  function randomHex() {
    var out = '', i, bytes;
    if (w.crypto && w.crypto.getRandomValues) {
      bytes = new Uint8Array(16);
      w.crypto.getRandomValues(bytes);
      for (i = 0; i < 16; i++) { out += ('0' + bytes[i].toString(16)).slice(-2); }
      return out;
    }
    for (i = 0; i < 32; i++) { out += Math.floor(Math.random() * 16).toString(16); }
    return out;
  }
  var sid = null;
  try { sid = w.localStorage.getItem(storeKey); } catch (e) { /* التخزين محظور */ }
  if (!sid || !/^[a-f0-9]{32}$/.test(sid)) {
    sid = randomHex();
    try { w.localStorage.setItem(storeKey, sid); } catch (e2) { /* جلسة مؤقتة */ }
  }

  // ---------- التنسيق ----------
  var css = ''
    + '.mxb,.mxb *{box-sizing:border-box;font-family:inherit}'
    + '.mxb{direction:rtl;font:14px/1.7 system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;color:#111827}'
    + '.mxb-btn{position:fixed;bottom:20px;' + side + ':20px;width:58px;height:58px;border-radius:50%;border:0;cursor:pointer;background:' + color + ';color:#fff;box-shadow:0 8px 24px rgba(0,0,0,.22);z-index:2147483000;display:flex;align-items:center;justify-content:center}'
    + '.mxb-btn svg{width:28px;height:28px}'
    + '.mxb-panel{position:fixed;bottom:90px;' + side + ':20px;width:360px;max-width:calc(100vw - 24px);height:520px;max-height:calc(100vh - 110px);background:#fff;border-radius:18px;box-shadow:0 18px 48px rgba(0,0,0,.25);z-index:2147483001;display:none;flex-direction:column;overflow:hidden}'
    + '.mxb-open .mxb-panel{display:flex}'
    + '.mxb-page .mxb-panel{position:absolute;inset:0;width:auto;height:auto;max-width:none;max-height:none;border-radius:0;box-shadow:none;display:flex}'
    + '.mxb-head{background:' + color + ';color:#fff;padding:14px 16px;display:flex;align-items:center;gap:10px}'
    + '.mxb-head b{flex:1;font-size:15px}'
    + '.mxb-x{background:transparent;border:0;color:#fff;font-size:22px;line-height:1;cursor:pointer;padding:0 4px}'
    + '.mxb-page .mxb-x{display:none}'
    + '.mxb-log{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:8px;background:#f4f6fb}'
    + '.mxb-msg{max-width:84%;padding:8px 12px;border-radius:14px;white-space:pre-wrap;word-wrap:break-word;overflow-wrap:anywhere}'
    + '.mxb-msg a{color:inherit;text-decoration:underline}'
    + '.mxb-user{align-self:flex-start;background:' + color + ';color:#fff;border-bottom-right-radius:4px}'
    + '.mxb-bot,.mxb-staff{align-self:flex-end;background:#fff;border:1px solid #e5e7eb;border-bottom-left-radius:4px}'
    + '.mxb-tag{display:block;font-size:11px;color:#6b7280;margin-bottom:2px}'
    + '.mxb-err{align-self:center;background:#fee2e2;color:#991b1b;font-size:13px}'
    + '.mxb-typing{align-self:flex-end;color:#6b7280;font-size:13px;padding:4px 8px}'
    + '.mxb-form{display:flex;gap:8px;padding:10px;border-top:1px solid #e5e7eb;background:#fff}'
    + '.mxb-form textarea{flex:1;resize:none;border:1px solid #d1d5db;border-radius:12px;padding:9px 12px;font-size:14px;min-height:42px;max-height:120px;outline:none}'
    + '.mxb-form textarea:focus{border-color:' + color + '}'
    + '.mxb-send{border:0;border-radius:12px;background:' + color + ';color:#fff;min-width:46px;cursor:pointer}'
    + '.mxb-send[disabled]{opacity:.55;cursor:default}'
    + '@media (max-width:480px){.mxb-float.mxb-open .mxb-panel{inset:0;width:auto;height:auto;max-height:none;max-width:none;border-radius:0}.mxb-float.mxb-open .mxb-btn{display:none}}';
  var style = d.createElement('style');
  style.appendChild(d.createTextNode(css));
  (d.head || d.documentElement).appendChild(style);

  // ---------- البناء ----------
  var root = d.createElement('div');
  root.className = 'mxb mxb-' + mode;
  var chatIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
  root.innerHTML = (mode === 'float' ? '<button type="button" class="mxb-btn" aria-label="محادثة">' + chatIcon + '</button>' : '')
    + '<div class="mxb-panel" role="dialog" aria-label="">'
    + '<div class="mxb-head"><b></b><button type="button" class="mxb-x" aria-label="إغلاق">×</button></div>'
    + '<div class="mxb-log" aria-live="polite"></div>'
    + '<form class="mxb-form"><textarea rows="1" maxlength="1000" placeholder="اكتب رسالتك…" aria-label="رسالتك"></textarea>'
    + '<button class="mxb-send" type="submit" aria-label="إرسال">➤</button></form>'
    + '</div>';
  var host = mode === 'page' ? (d.getElementById(script.getAttribute('data-target') || '') || d.body) : d.body;
  host.appendChild(root);
  var q = function (sel) { return root.querySelector(sel); };
  q('.mxb-head b').appendChild(d.createTextNode(title));
  q('.mxb-panel').setAttribute('aria-label', title);
  var log = q('.mxb-log');
  var form = q('.mxb-form');
  var input = q('textarea');
  var sendBtn = q('.mxb-send');

  var lastId = 0;
  var seen = {};
  var busy = false;
  var loaded = false;
  var timer = null;

  function linkify(el, text) {
    var re = /(https?:\/\/[^\s<>"]+)/g, pos = 0, m;
    while ((m = re.exec(text)) !== null) {
      if (m.index > pos) { el.appendChild(d.createTextNode(text.slice(pos, m.index))); }
      var a = d.createElement('a');
      a.href = m[1]; a.target = '_blank'; a.rel = 'noopener nofollow';
      a.appendChild(d.createTextNode(m[1]));
      el.appendChild(a);
      pos = m.index + m[1].length;
    }
    if (pos < text.length) { el.appendChild(d.createTextNode(text.slice(pos))); }
  }

  function bubble(role, text, tag) {
    var el = d.createElement('div');
    el.className = 'mxb-msg mxb-' + role;
    if (tag) {
      var t = d.createElement('span');
      t.className = 'mxb-tag';
      t.appendChild(d.createTextNode(tag));
      el.appendChild(t);
    }
    linkify(el, text);
    log.appendChild(el);
    log.scrollTop = log.scrollHeight;
    return el;
  }

  function render(list) {
    for (var i = 0; i < list.length; i++) {
      var m = list[i];
      if (seen[m.id]) { continue; }
      seen[m.id] = true;
      lastId = Math.max(lastId, m.id);
      bubble(m.role === 'user' ? 'user' : (m.role === 'staff' ? 'staff' : 'bot'), m.body, m.role === 'staff' ? 'فريق خدمة الزبائن' : '');
    }
  }

  function request(method, path, body, done) {
    var xhr = new XMLHttpRequest();
    xhr.open(method, endpoint + path, true);
    xhr.setRequestHeader('Accept', 'application/json');
    // نص عادي لا application/json: طلب بسيط لا يحتاج إذناً مسبقاً حين تكون النافذة في موقع آخر
    if (body) { xhr.setRequestHeader('Content-Type', 'text/plain;charset=UTF-8'); }
    xhr.timeout = 90000;
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) { return; }
      var data = null;
      try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
      done(xhr.status, data);
    };
    xhr.ontimeout = function () { done(0, null); };
    xhr.send(body ? JSON.stringify(body) : null);
  }

  function poll() {
    if (busy) { return; }
    request('GET', '/poll?sid=' + sid + '&after=' + lastId, null, function (status, data) {
      if (status === 200 && data && data.messages) { render(data.messages); }
    });
  }

  function startPolling() {
    if (timer) { return; }
    timer = w.setInterval(function () {
      if (!d.hidden) { poll(); }
    }, 5000);
  }

  function open() {
    root.className = 'mxb mxb-' + mode + ' mxb-open';
    if (!loaded) {
      loaded = true;
      bubble('bot', greeting);
      poll();
    }
    startPolling();
    w.setTimeout(function () { try { input.focus(); } catch (e) { /* */ } }, 50);
  }

  function close() {
    root.className = 'mxb mxb-' + mode;
    if (timer) { w.clearInterval(timer); timer = null; }
  }

  function send(e) {
    if (e) { e.preventDefault(); }
    var text = input.value.replace(/^\s+|\s+$/g, '');
    if (!text || busy) { return; }
    busy = true;
    sendBtn.disabled = true;
    input.value = '';
    var mine = bubble('user', text);
    var typing = d.createElement('div');
    typing.className = 'mxb-typing';
    typing.appendChild(d.createTextNode('يكتب…'));
    log.appendChild(typing);
    log.scrollTop = log.scrollHeight;
    request('POST', '/send', { sid: sid, text: text, after: lastId }, function (status, data) {
      busy = false;
      sendBtn.disabled = false;
      if (typing.parentNode) { typing.parentNode.removeChild(typing); }
      if (status === 200 && data && data.ok) {
        if (mine.parentNode) { mine.parentNode.removeChild(mine); }
        render(data.messages || []);
      } else {
        bubble('err', (data && data.message) || 'تعذّر الإرسال، حاول مرة أخرى.');
        if (!input.value) { input.value = text; }
      }
    });
  }

  form.onsubmit = send;
  input.onkeydown = function (e) {
    e = e || w.event;
    if ((e.key === 'Enter' || e.keyCode === 13) && !e.shiftKey) { send(e); }
  };
  input.oninput = function () {
    input.style.height = 'auto';
    input.style.height = Math.min(120, input.scrollHeight) + 'px';
  };
  q('.mxb-x').onclick = close;
  if (mode === 'float') {
    q('.mxb-btn').onclick = function () {
      if (root.className.indexOf('mxb-open') >= 0) { close(); } else { open(); }
    };
  } else {
    open();
  }
})(window, document);
