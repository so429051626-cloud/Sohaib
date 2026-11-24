/* ==========================================================================
   MADIX NEXUS — الجسر مع تطبيقات Windows وAndroid
   البصمة (حفظ رمز الجهاز في مخزن آمن لا يُفتح إلا بالبصمة)، والطباعة.
   البروتوكول: request({id, action, payload}) ← الرد MadixBridge._resolve(id, {ok, data, error})
   ========================================================================== */
(function (window, document) {
  'use strict';

  var pending = {};
  var seq = 0;
  var platform = null;
  if (window.MadixAndroid && typeof window.MadixAndroid.request === 'function') {
    platform = 'android';
  } else if (window.chrome && window.chrome.webview && /MadixNexusApp/.test(navigator.userAgent)) {
    platform = 'windows';
  }

  function resolve(id, result) {
    var p = pending[id];
    if (!p) { return; }
    delete pending[id];
    if (typeof result === 'string') { try { result = JSON.parse(result); } catch (e) { result = { ok: false }; } }
    if (result && result.ok) { p.resolve(result.data || {}); } else { p.reject(new Error((result && result.error) || 'failed')); }
  }

  function request(action, payload) {
    return new Promise(function (ok, fail) {
      if (!platform) { fail(new Error('unavailable')); return; }
      var id = ++seq;
      pending[id] = { resolve: ok, reject: fail };
      var msg = JSON.stringify({ id: id, action: action, payload: payload || {} });
      try {
        if (platform === 'android') { window.MadixAndroid.request(msg); } else { window.chrome.webview.postMessage(msg); }
      } catch (e) { delete pending[id]; fail(e); return; }
      setTimeout(function () { if (pending[id]) { delete pending[id]; fail(new Error('timeout')); } }, 120000);
    });
  }

  if (platform === 'windows') {
    window.chrome.webview.addEventListener('message', function (e) {
      var d = e.data;
      if (typeof d === 'string') { try { d = JSON.parse(d); } catch (x) { return; } }
      if (d && d.id) { resolve(d.id, d); }
    });
  }

  var Bridge = { available: !!platform, platform: platform, request: request, _resolve: resolve };
  window.MadixBridge = Bridge;
  if (!platform) { return; }
  document.documentElement.classList.add('in-app', 'in-app-' + platform);

  // طباعة الصفحة من التطبيق (WebView في Android لا يدعم window.print)
  if (platform === 'android') {
    window.print = function () { request('print', { title: document.title }).catch(function () {}); };

    // الملفات المُنشأة داخل الصفحة (مثل PDF الكروت) تُحفظ في التنزيلات عبر التطبيق
    var saveBlob = function (a) {
      var href = a.getAttribute('href') || '';
      if (href.indexOf('blob:') !== 0 && href.indexOf('data:') !== 0) { return false; }
      fetch(href).then(function (r) { return r.blob(); }).then(function (blob) {
        return new Promise(function (ok) {
          var fr = new FileReader();
          fr.onload = function () { ok({ blob: blob, data: String(fr.result).split(',')[1] || '' }); };
          fr.readAsDataURL(blob);
        });
      }).then(function (o) {
        return request('file.save', { name: a.getAttribute('download') || 'file', mime: o.blob.type || 'application/octet-stream', data: o.data });
      }).catch(function () {});
      return true;
    };
    var nativeClick = HTMLAnchorElement.prototype.click;
    HTMLAnchorElement.prototype.click = function () {
      if (this.hasAttribute('download') && saveBlob(this)) { return; }
      return nativeClick.apply(this, arguments);
    };
    document.addEventListener('click', function (e) {
      var a = e.target && e.target.closest ? e.target.closest('a[download]') : null;
      if (a && saveBlob(a)) { e.preventDefault(); }
    }, true);
  }

  var baseMeta = document.querySelector('meta[name="base-url"]');
  var base = baseMeta ? baseMeta.getAttribute('content') : '';
  var scope = location.origin + base;
  var SN = window.SN;

  function toast(type, msg) { if (SN && SN.toast) { SN.toast(type, msg); } }

  // ------------------------------------------------------------------
  // شاشة الدخول: الدخول بالبصمة
  // ------------------------------------------------------------------
  var bioBtn = document.querySelector('[data-biometric-login]');
  if (bioBtn) {
    var busy = false;
    var doBio = function () {
      if (busy) { return; }
      busy = true;
      bioBtn.classList.add('is-loading');
      var deviceId = null;
      request('bio.get', { scope: scope, reason: 'تسجيل الدخول' })
        .then(function (c) {
          deviceId = c.deviceId;
          return SN.api(base + '/login/device', { method: 'POST', data: { device_id: c.deviceId, token: c.token }, silent: true });
        })
        .then(function (r) {
          return request('bio.save', { scope: scope, deviceId: deviceId, token: r.token }).then(function () { window.location.href = r.redirect; });
        })
        .catch(function (err) {
          if (err && err.message === 'reset') {
            bioBtn.hidden = true;
            toast('error', 'تغيّرت البصمات المسجلة على الجهاز. سجّل الدخول بكلمة المرور.');
            return;
          }
          if (err && err.data && err.data.reset) {
            request('bio.clear', { scope: scope }).catch(function () {});
            bioBtn.hidden = true;
          }
          if (err && err.message && err.message !== 'cancelled' && err.message !== 'unavailable') {
            toast('error', err.data ? err.message : 'تعذّر التحقق بالبصمة.');
          }
        })
        .then(function () { busy = false; bioBtn.classList.remove('is-loading'); });
    };
    request('bio.status', { scope: scope }).then(function (st) {
      if (!st.available || !st.enrolled) { return; }
      bioBtn.hidden = false;
      bioBtn.addEventListener('click', doBio);
      var hadError = !!document.getElementById('flash-data');
      var key = 'mnx-bio-auto';
      var asked = false;
      try { asked = sessionStorage.getItem(key) === '1'; sessionStorage.setItem(key, '1'); } catch (e) { /* التخزين غير متاح */ }
      if (!hadError && !asked) { setTimeout(doBio, 350); }
    }).catch(function () {});
    return;
  }

  // ------------------------------------------------------------------
  // داخل النظام: تفعيل البصمة على هذا الجهاز
  // ------------------------------------------------------------------
  if (!document.querySelector('meta[name="auth"]')) { return; }
  try { sessionStorage.removeItem('mnx-bio-auto'); } catch (e) { /* التخزين غير متاح */ }

  function enroll(st) {
    return SN.api(base + '/account/devices', { method: 'POST', data: { name: st.deviceName || '', platform: platform } })
      .then(function (r) { return request('bio.save', { scope: scope, deviceId: r.device_id, token: r.token }).then(function () { return r; }); })
      .then(function (r) { toast('success', r.message); return r; });
  }

  request('bio.status', { scope: scope }).then(function (st) {
    if (!st.available) { return; }
    var panel = document.querySelector('[data-bio-enroll]');
    if (panel && !st.enrolled) {
      panel.hidden = false;
      panel.querySelector('[data-bio-enable]').addEventListener('click', function () {
        enroll(st).then(function () { window.location.reload(); }).catch(function () {});
      });
    }
    if (st.enrolled) { return; }
    var skipKey = 'mnx-bio-skip:' + scope;
    try { if (localStorage.getItem(skipKey) === '1' || sessionStorage.getItem('mnx-bio-offered') === '1') { return; } sessionStorage.setItem('mnx-bio-offered', '1'); } catch (e) { return; }
    setTimeout(function () {
      SN.confirm('تفعيل الدخول بالبصمة على هذا الجهاز؟', { okText: 'تفعيل', danger: false }).then(function (yes) {
        if (yes) { enroll(st).catch(function () {}); } else { try { localStorage.setItem(skipKey, '1'); } catch (e) { /* التخزين غير متاح */ } }
      });
    }, 900);
  }).catch(function () {});
})(window, document);
