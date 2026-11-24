/*!
 * SN Hotspot SDK — ربط صفحة هوتسبوت MikroTik بنظام إدارة الشبكة
 * لا يعتمد على أي مكتبة، ويعمل على المتصفحات القديمة (ES5).
 *
 * الاستخدام داخل login.html على الراوتر:
 *   <script src="sn-config.js"></script>
 *   <script src="sohaibnet.js"></script>
 *   SNHotspot.login({ code: ..., password: ..., linkLogin: '$(link-login-only)',
 *                     linkOrig: '$(link-orig)', chapId: '$(chap-id)', chapChallenge: '$(chap-challenge)',
 *                     mac: '$(mac)', ip: '$(ip)', onError: fn, onLoading: fn });
 */
(function (window, document) {
  'use strict';

  var cfg = window.SN_CONFIG || {};
  var TIMEOUT = (cfg.timeout || 8) * 1000;

  // ------------------------------------------------------------------
  // MD5 (RFC 1321) على سلسلة بايتات — لتشفير CHAP كما يتطلبه الراوتر
  // ------------------------------------------------------------------
  function md5(str) {
    function add(x, y) { var l = (x & 0xFFFF) + (y & 0xFFFF); return (((x >> 16) + (y >> 16) + (l >> 16)) << 16) | (l & 0xFFFF); }
    function rol(n, c) { return (n << c) | (n >>> (32 - c)); }
    function cmn(q, a, b, x, s, t) { return add(rol(add(add(a, q), add(x, t)), s), b); }
    function ff(a, b, c, d, x, s, t) { return cmn((b & c) | ((~b) & d), a, b, x, s, t); }
    function gg(a, b, c, d, x, s, t) { return cmn((b & d) | (c & (~d)), a, b, x, s, t); }
    function hh(a, b, c, d, x, s, t) { return cmn(b ^ c ^ d, a, b, x, s, t); }
    function ii(a, b, c, d, x, s, t) { return cmn(c ^ (b | (~d)), a, b, x, s, t); }
    var n = str.length, i, x = [], len = n * 8;
    for (i = 0; i < n; i++) { x[i >> 2] |= (str.charCodeAt(i) & 0xFF) << ((i % 4) * 8); }
    x[len >> 5] |= 0x80 << (len % 32);
    x[(((len + 64) >>> 9) << 4) + 14] = len;
    var a = 1732584193, b = -271733879, c = -1732584194, d = 271733878;
    for (i = 0; i < x.length; i += 16) {
      var oa = a, ob = b, oc = c, od = d;
      for (var k = 0; k < 16; k++) { if (x[i + k] === undefined) { x[i + k] = 0; } }
      a = ff(a, b, c, d, x[i], 7, -680876936); d = ff(d, a, b, c, x[i + 1], 12, -389564586); c = ff(c, d, a, b, x[i + 2], 17, 606105819); b = ff(b, c, d, a, x[i + 3], 22, -1044525330);
      a = ff(a, b, c, d, x[i + 4], 7, -176418897); d = ff(d, a, b, c, x[i + 5], 12, 1200080426); c = ff(c, d, a, b, x[i + 6], 17, -1473231341); b = ff(b, c, d, a, x[i + 7], 22, -45705983);
      a = ff(a, b, c, d, x[i + 8], 7, 1770035416); d = ff(d, a, b, c, x[i + 9], 12, -1958414417); c = ff(c, d, a, b, x[i + 10], 17, -42063); b = ff(b, c, d, a, x[i + 11], 22, -1990404162);
      a = ff(a, b, c, d, x[i + 12], 7, 1804603682); d = ff(d, a, b, c, x[i + 13], 12, -40341101); c = ff(c, d, a, b, x[i + 14], 17, -1502002290); b = ff(b, c, d, a, x[i + 15], 22, 1236535329);
      a = gg(a, b, c, d, x[i + 1], 5, -165796510); d = gg(d, a, b, c, x[i + 6], 9, -1069501632); c = gg(c, d, a, b, x[i + 11], 14, 643717713); b = gg(b, c, d, a, x[i], 20, -373897302);
      a = gg(a, b, c, d, x[i + 5], 5, -701558691); d = gg(d, a, b, c, x[i + 10], 9, 38016083); c = gg(c, d, a, b, x[i + 15], 14, -660478335); b = gg(b, c, d, a, x[i + 4], 20, -405537848);
      a = gg(a, b, c, d, x[i + 9], 5, 568446438); d = gg(d, a, b, c, x[i + 14], 9, -1019803690); c = gg(c, d, a, b, x[i + 3], 14, -187363961); b = gg(b, c, d, a, x[i + 8], 20, 1163531501);
      a = gg(a, b, c, d, x[i + 13], 5, -1444681467); d = gg(d, a, b, c, x[i + 2], 9, -51403784); c = gg(c, d, a, b, x[i + 7], 14, 1735328473); b = gg(b, c, d, a, x[i + 12], 20, -1926607734);
      a = hh(a, b, c, d, x[i + 5], 4, -378558); d = hh(d, a, b, c, x[i + 8], 11, -2022574463); c = hh(c, d, a, b, x[i + 11], 16, 1839030562); b = hh(b, c, d, a, x[i + 14], 23, -35309556);
      a = hh(a, b, c, d, x[i + 1], 4, -1530992060); d = hh(d, a, b, c, x[i + 4], 11, 1272893353); c = hh(c, d, a, b, x[i + 7], 16, -155497632); b = hh(b, c, d, a, x[i + 10], 23, -1094730640);
      a = hh(a, b, c, d, x[i + 13], 4, 681279174); d = hh(d, a, b, c, x[i], 11, -358537222); c = hh(c, d, a, b, x[i + 3], 16, -722521979); b = hh(b, c, d, a, x[i + 6], 23, 76029189);
      a = hh(a, b, c, d, x[i + 9], 4, -640364487); d = hh(d, a, b, c, x[i + 12], 11, -421815835); c = hh(c, d, a, b, x[i + 15], 16, 530742520); b = hh(b, c, d, a, x[i + 2], 23, -995338651);
      a = ii(a, b, c, d, x[i], 6, -198630844); d = ii(d, a, b, c, x[i + 7], 10, 1126891415); c = ii(c, d, a, b, x[i + 14], 15, -1416354905); b = ii(b, c, d, a, x[i + 5], 21, -57434055);
      a = ii(a, b, c, d, x[i + 12], 6, 1700485571); d = ii(d, a, b, c, x[i + 3], 10, -1894986606); c = ii(c, d, a, b, x[i + 10], 15, -1051523); b = ii(b, c, d, a, x[i + 1], 21, -2054922799);
      a = ii(a, b, c, d, x[i + 8], 6, 1873313359); d = ii(d, a, b, c, x[i + 15], 10, -30611744); c = ii(c, d, a, b, x[i + 6], 15, -1560198380); b = ii(b, c, d, a, x[i + 13], 21, 1309151649);
      a = ii(a, b, c, d, x[i + 4], 6, -145523070); d = ii(d, a, b, c, x[i + 11], 10, -1120210379); c = ii(c, d, a, b, x[i + 2], 15, 718787259); b = ii(b, c, d, a, x[i + 9], 21, -343485551);
      a = add(a, oa); b = add(b, ob); c = add(c, oc); d = add(d, od);
    }
    var out = '', words = [a, b, c, d], hex = '0123456789abcdef';
    for (i = 0; i < 16; i++) {
      var byte = (words[i >> 2] >> ((i % 4) * 8)) & 0xFF;
      out += hex.charAt((byte >> 4) & 0x0F) + hex.charAt(byte & 0x0F);
    }
    return out;
  }

  // ------------------------------------------------------------------
  // أدوات
  // ------------------------------------------------------------------
  var DIGITS = { '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
                 '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9' };

  function normalize(value) {
    var s = String(value == null ? '' : value).replace(/[٠-٩۰-۹]/g, function (c) { return DIGITS[c]; }).replace(/\s+/g, '');
    if (cfg.codeCase === 'lower') { s = s.toLowerCase(); }
    if (cfg.codeCase === 'upper') { s = s.toUpperCase(); }
    return s;
  }

  // القيمة كما كُتبت (أرقام لاتينية فقط): لمستخدمي MikroTik المحليين الذين تهمّ حالة أحرف أسمائهم
  function raw(value) {
    return String(value == null ? '' : value).replace(/[٠-٩۰-۹]/g, function (c) { return DIGITS[c]; }).replace(/^\s+|\s+$/g, '');
  }

  function encode(data) {
    var parts = [];
    for (var k in data) {
      if (Object.prototype.hasOwnProperty.call(data, k) && data[k] != null) {
        parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k]));
      }
    }
    return parts.join('&');
  }

  /** طلب إلى النظام بصيغة نموذج بسيطة (بدون طلب CORS تمهيدي) */
  function request(method, path, data, done) {
    if (!cfg.panel || !cfg.key) { done({ network: true }); return; }
    var xhr = new XMLHttpRequest();
    var finished = false;
    var url = cfg.panel.replace(/\/+$/, '') + path;
    var body = encode(data || {});
    if (method === 'GET') { url += (url.indexOf('?') === -1 ? '?' : '&') + body; body = null; }
    var timer = setTimeout(function () {
      if (finished) { return; }
      finished = true;
      try { xhr.abort(); } catch (e) {}
      done({ network: true });
    }, TIMEOUT);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4 || finished) { return; }
      finished = true;
      clearTimeout(timer);
      if (xhr.status === 0 || xhr.status >= 500) { done({ network: true }); return; }
      var json = null;
      try { json = JSON.parse(xhr.responseText); } catch (e) {}
      done(json ? { data: json } : { network: true });
    };
    try {
      xhr.open(method, url, true);
      if (method !== 'GET') { xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded'); }
      xhr.send(body);
    } catch (e) {
      if (!finished) { finished = true; clearTimeout(timer); done({ network: true }); }
    }
  }

  /**
   * الدخول المباشر عبر الراوتر (CHAP إن توفّر التحدي، وإلا PAP).
   */
  function submitLogin(username, password, o) {
    o = o || {};
    var action = o.linkLogin || (window.SN_MT && window.SN_MT.linkLogin) || '';
    var chapId = o.chapId != null ? o.chapId : (window.SN_MT && window.SN_MT.chapId) || '';
    var chapChallenge = o.chapChallenge != null ? o.chapChallenge : (window.SN_MT && window.SN_MT.chapChallenge) || '';
    var dst = o.linkOrig != null ? o.linkOrig : (window.SN_MT && window.SN_MT.linkOrig) || '';
    var form = document.createElement('form');
    form.method = 'post';
    form.action = action;
    form.style.display = 'none';
    var fields = {
      username: username,
      password: chapId ? md5(chapId + password + chapChallenge) : password,
      dst: dst,
      popup: 'true'
    };
    for (var k in fields) {
      if (Object.prototype.hasOwnProperty.call(fields, k)) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = k;
        input.value = fields[k];
        form.appendChild(input);
      }
    }
    document.body.appendChild(form);
    form.submit();
  }

  /**
   * التحقق عبر النظام ثم الدخول عبر الراوتر.
   * الخيارات: code, password, mac, ip, linkLogin, linkOrig, chapId, chapChallenge,
   *           onLoading(bool), onError({code, message, needPassword}), onSuccess(result)
   */
  function login(o) {
    o = o || {};
    var mt = window.SN_MT || {};
    var code = normalize(o.code);
    var password = normalize(o.password || '');
    var rawCode = raw(o.code), rawPassword = raw(o.password || '');
    var loading = o.onLoading || function () {};
    var onError = o.onError || function (e) { window.alert(e.message); };
    if (!code) {
      onError({ code: 'invalid', message: 'أدخل رقم الكرت.' });
      return;
    }
    loading(true);
    request('POST', '/api/hotspot/login', {
      key: cfg.key,
      code: code,
      password: password,
      raw_code: rawCode !== code ? rawCode : null,
      raw_password: rawPassword !== password ? rawPassword : null,
      mac: o.mac != null ? o.mac : mt.mac,
      ip: o.ip != null ? o.ip : mt.ip
    }, function (res) {
      if (res.network) {
        // تعذّر الوصول إلى النظام: الدخول المباشر بالبيانات المدخلة
        if (cfg.fallback !== false) {
          submitLogin(code, password || code, o);
        } else {
          loading(false);
          onError({ code: 'unavailable', message: 'تعذّر الاتصال. حاول مجدداً.' });
        }
        return;
      }
      var d = res.data;
      if (d && d.ok) {
        if (o.onSuccess) { o.onSuccess(d); }
        submitLogin(d.username, d.password, o);
        return;
      }
      loading(false);
      onError({ code: d && d.error || 'error', message: d && d.message || 'تعذّر الدخول.', needPassword: !!(d && d.need_password) });
    });
  }

  /** معلومات الكرت/الاشتراك لصفحة الحالة: onResult(data) */
  function status(o) {
    o = o || {};
    var mt = window.SN_MT || {};
    request('GET', '/api/hotspot/status', {
      key: cfg.key,
      u: o.username != null ? o.username : mt.username,
      mac: o.mac != null ? o.mac : mt.mac
    }, function (res) {
      if (o.onResult) { o.onResult(res.data || { ok: false, message: 'تعذّر الاتصال.' }); }
    });
  }

  /** بيانات الشبكة والباقات والأسعار: callback(data) */
  function info(callback) {
    request('GET', '/api/hotspot/info', { key: cfg.key }, function (res) {
      if (callback) { callback(res.data || { ok: false }); }
    });
  }

  // ------------------------------------------------------------------
  // إعلانات صفحة الدخول (عروض الشبكة وإعلانات المحلات)
  // ------------------------------------------------------------------

  /** قائمة الإعلانات: callback({ok, interval, ads:[{id,title,body,advertiser,image,link,phone,tel}]}) */
  function ads(callback) {
    var mt = window.SN_MT || {};
    request('GET', '/api/hotspot/ads', { key: cfg.key, mac: mt.mac }, function (res) {
      if (callback) { callback(res.data || { ok: false, ads: [] }); }
    });
  }

  function adClick(id) {
    var mt = window.SN_MT || {};
    request('POST', '/api/hotspot/ads/' + id + '/click', { key: cfg.key, mac: mt.mac }, function () {});
  }

  var adsStyled = false;
  function adsStyle() {
    if (adsStyled) { return; }
    adsStyled = true;
    var css = '.sn-ads{position:relative;max-width:420px;margin:12px auto;font-family:inherit;direction:rtl;text-align:right}'
      + '.sn-ad{display:none;border-radius:14px;overflow:hidden;background:#fff;color:#1b2540;box-shadow:0 6px 20px rgba(15,23,42,.12);border:1px solid rgba(15,23,42,.08)}'
      + '.sn-ad.sn-on{display:block;animation:snAdIn .35s ease}'
      + '@keyframes snAdIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}'
      + '.sn-ad img{display:block;width:100%;max-height:200px;object-fit:cover}'
      + '.sn-ad-b{padding:10px 14px 14px}'
      + '.sn-ad-adv{font-size:12px;font-weight:600;color:#2453d6;margin-bottom:2px}'
      + '.sn-ad-t{font-size:16px;font-weight:700;line-height:1.4}'
      + '.sn-ad-x{font-size:13px;color:#475569;margin-top:4px;white-space:pre-line}'
      + '.sn-ad-a{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}'
      + '.sn-ad-a a{display:inline-block;padding:7px 14px;border-radius:999px;background:#2453d6;color:#fff;font-size:13px;font-weight:600;text-decoration:none}'
      + '.sn-ad-a a.sn-alt{background:#eef2ff;color:#1b2540}'
      + '.sn-ads-dots{display:flex;justify-content:center;gap:6px;margin-top:8px}'
      + '.sn-ads-dots button{width:8px;height:8px;border-radius:50%;border:0;padding:0;background:rgba(100,116,139,.35);cursor:pointer}'
      + '.sn-ads-dots button.sn-on{background:#2453d6;width:18px;border-radius:4px}';
    var tag = document.createElement('style');
    tag.appendChild(document.createTextNode(css));
    (document.head || document.documentElement).appendChild(tag);
  }

  function el(name, cls, text) {
    var e = document.createElement(name);
    if (cls) { e.className = cls; }
    if (text != null && text !== '') { e.appendChild(document.createTextNode(text)); }
    return e;
  }

  /**
   * عرض الإعلانات داخل عنصر (بالتناوب): SNHotspot.renderAds('#sn-ads') أو SNHotspot.renderAds(element)
   * الخيارات: interval (ثوانٍ)، onEmpty()
   */
  function renderAds(target, o) {
    o = o || {};
    var box = typeof target === 'string' ? document.querySelector(target) : target;
    if (!box) { return; }
    ads(function (d) {
      var list = (d && d.ok && d.ads) || [];
      if (!list.length) { box.style.display = 'none'; if (o.onEmpty) { o.onEmpty(); } return; }
      adsStyle();
      box.className = (box.className ? box.className + ' ' : '') + 'sn-ads';
      box.innerHTML = '';
      var cards = [], dots = null, current = 0, timer = null;
      for (var i = 0; i < list.length; i++) {
        (function (ad, index) {
          var card = el('div', 'sn-ad' + (index === 0 ? ' sn-on' : ''));
          if (ad.image) { var img = document.createElement('img'); img.src = ad.image; img.alt = ''; card.appendChild(img); }
          var b = el('div', 'sn-ad-b');
          if (ad.advertiser) { b.appendChild(el('div', 'sn-ad-adv', ad.advertiser)); }
          b.appendChild(el('div', 'sn-ad-t', ad.title));
          if (ad.body) { b.appendChild(el('div', 'sn-ad-x', ad.body)); }
          if (ad.tel || ad.link) {
            var acts = el('div', 'sn-ad-a');
            if (ad.tel) {
              var call = el('a', '', 'اتصال ' + ad.phone);
              call.href = 'tel:' + ad.tel;
              call.onclick = function () { adClick(ad.id); };
              acts.appendChild(call);
            }
            if (ad.link) {
              var go = el('a', 'sn-alt', 'التفاصيل');
              go.href = ad.link; go.target = '_blank'; go.rel = 'noopener';
              go.onclick = function () { adClick(ad.id); };
              acts.appendChild(go);
            }
            b.appendChild(acts);
          }
          card.appendChild(b);
          box.appendChild(card);
          cards.push(card);
        })(list[i], i);
      }
      function show(n) {
        cards[current].className = 'sn-ad';
        if (dots) { dots.childNodes[current].className = ''; }
        current = (n + cards.length) % cards.length;
        cards[current].className = 'sn-ad sn-on';
        if (dots) { dots.childNodes[current].className = 'sn-on'; }
      }
      if (cards.length > 1) {
        dots = el('div', 'sn-ads-dots');
        for (var j = 0; j < cards.length; j++) {
          (function (n) {
            var dot = document.createElement('button');
            dot.type = 'button';
            dot.setAttribute('aria-label', String(n + 1));
            if (n === 0) { dot.className = 'sn-on'; }
            dot.onclick = function () { show(n); restart(); };
            dots.appendChild(dot);
          })(j);
        }
        box.appendChild(dots);
        var every = Math.max(3, Number(o.interval || d.interval || 6)) * 1000;
        var restart = function () { clearInterval(timer); timer = setInterval(function () { show(current + 1); }, every); };
        restart();
      }
    });
  }

  function formatBytes(b) {
    b = Number(b) || 0;
    if (b <= 0) { return '0 MB'; }
    var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = Math.min(Math.floor(Math.log(b) / Math.log(1024)), 4);
    return (b / Math.pow(1024, i)).toFixed(i < 2 ? 0 : 1).replace(/\.0$/, '') + ' ' + u[i];
  }

  function formatDuration(s) {
    s = Math.max(0, Math.floor(Number(s) || 0));
    var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
    var parts = [];
    if (d) { parts.push(d + ' يوم'); }
    if (h) { parts.push(h + ' ساعة'); }
    if (m && !d) { parts.push(m + ' دقيقة'); }
    return parts.length ? parts.join(' و') : 'أقل من دقيقة';
  }

  // عنصر الإعلانات في الصفحة (<div id="sn-ads"></div>) يُملأ تلقائياً
  function autoAds() {
    var box = document.getElementById('sn-ads');
    if (box && !box.getAttribute('data-sn-manual')) { renderAds(box); }
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', autoAds); } else { autoAds(); }

  window.SNHotspot = {
    version: '1.2.0',
    config: cfg,
    login: login,
    status: status,
    info: info,
    ads: ads,
    renderAds: renderAds,
    submitLogin: submitLogin,
    normalize: normalize,
    md5: md5,
    formatBytes: formatBytes,
    formatDuration: formatDuration
  };
})(window, document);
