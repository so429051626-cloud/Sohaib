<?php
/**
 * قياس سرعة الصفحة من جهاز المستخدم نفسه (Real User Monitoring): من لمس الرابط حتى ظهور الصفحة، مقسّماً إلى
 * فتح الاتصال، ووقت الخادم، وانتظار الشبكة، والتنزيل، ورسم الهاتف. يُرسل مرة واحدة لكل صفحة إلى /api/rum
 * ويقرؤه أمر «madix speed» على الخادم. لا يحمل أي بيانات شخصية: نوع الصفحة ومسارها بلا أرقام، وأزمنة بالميلي ثانية.
 * @var string $area admin | net | login | landing
 */
use App\Core\Request;

$req = Request::current();
$page = $req ? (string) $req->path : '/';
$page = (string) preg_replace(['#/[0-9a-f]{24,}(?=/|$)#i', '#/\d+(?=/|$)#'], ['/{key}', '/{id}'], $page);
$page = mb_substr($page === '' ? '/' : $page, 0, 100);
// وقت الخادم حتى هذه اللحظة (القالب يُبنى بعد محتوى الصفحة، فهو قريب من وقت بنائها كاملاً)
$srv = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (int) round((microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000) : -1;
$cfg = json_encode(['a' => $area, 'p' => $page, 's' => $srv], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
?>
<script>
(function () {
  var P = window.performance, N = navigator;
  // صفحة محمّلة مسبقاً وقديمة يعيد حارس الحداثة طلبها الآن: تُقاس الصفحة الجديدة لا هذه
  if (!P || !P.getEntriesByType || !N.sendBeacon || !window.PerformanceObserver || window.__mnxRefetch) { return; }
  var D = <?= $cfg ?>, url = <?= json_encode(root_path() . '/api/rum', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>, KEY = 'mnx-rum-tap';
  var lt = 0, ltn = 0, lcp = 0, inp = 0, sent = false, t0 = 0, rf = 0;
  // لحظة لمس الرابط في الصفحة السابقة تُقرأ أولاً (قبل أي لمس في هذه الصفحة)
  try {
    t0 = parseFloat(sessionStorage.getItem(KEY)) || 0; sessionStorage.removeItem(KEY);
    rf = sessionStorage.getItem('mnx-rum-refetch') ? 1 : 0; sessionStorage.removeItem('mnx-rum-refetch');
  } catch (e) {}
  function watch(type, fn, extra) {
    try { var o = { type: type, buffered: true }; for (var k in extra) { o[k] = extra[k]; } new PerformanceObserver(function (l) { l.getEntries().forEach(fn); }).observe(o); } catch (e) {}
  }
  watch('longtask', function (e) { lt += Math.max(0, e.duration - 50); ltn++; });
  watch('largest-contentful-paint', function (e) { lcp = e.startTime; });
  watch('event', function (e) { if (e.interactionId && e.duration > inp) { inp = e.duration; } }, { durationThreshold: 40 });
  // لحظة لمس رابط أو إرسال نموذج: الصفحة التالية تحسب منها كم انتظر المستخدم حتى ظهرت
  function tap() { try { sessionStorage.setItem(KEY, String(Date.now())); } catch (e) {} }
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (a && a.origin === location.origin && (!a.target || a.target === '_self') && a.getAttribute('href').charAt(0) !== '#') { tap(); }
  }, true);
  document.addEventListener('submit', tap, true);
  function r(v) { return v > 0 ? Math.round(v) : 0; }
  function send() {
    if (sent) { return; }
    var n = P.getEntriesByType('navigation')[0];
    if (!n) { return; }
    sent = true;
    var fcp = 0, origin = P.timeOrigin || 0, rb = 0, rn = 0, rkb = 0, slow = null, c = N.connection || {};
    P.getEntriesByType('paint').forEach(function (e) { if (e.name === 'first-contentful-paint') { fcp = e.startTime; } });
    P.getEntriesByType('resource').forEach(function (e) {
      rn++; rkb += e.transferSize || 0;
      if (e.renderBlockingStatus === 'blocking' && e.responseEnd > rb) { rb = e.responseEnd; }
      if (!slow || e.duration > slow.duration) { slow = e; }
    });
    D.t = n.type; D.dt = n.deliveryType || ''; D.h = n.nextHopProtocol || '';
    D.red = r(n.redirectEnd - n.redirectStart);
    D.pre = r(n.requestStart);
    D.dns = r(n.domainLookupEnd - n.domainLookupStart);
    D.con = r(n.connectEnd - n.connectStart);
    D.tls = n.secureConnectionStart > 0 ? r(n.connectEnd - n.secureConnectionStart) : 0;
    D.ttfb = r(n.responseStart - n.requestStart);
    D.dl = r(n.responseEnd - n.responseStart);
    D.end = r(n.responseEnd);
    D.di = r(n.domInteractive); D.dcl = r(n.domContentLoadedEventEnd); D.ld = r(n.loadEventEnd);
    D.fcp = r(fcp); D.lcp = r(lcp); D.rb = r(rb); D.lt = r(lt); D.ltn = ltn; D.inp = r(inp);
    D.tap = t0 && origin && origin - t0 >= 0 && origin - t0 < 30000 ? r(origin - t0) : -1;
    D.kb = r((n.transferSize || 0) / 1024); D.rn = rn; D.rkb = r(rkb / 1024);
    if (slow) { D.sr = r(slow.duration); D.sn = String(slow.name).replace(/^https?:\/\/[^\/]+/, '').replace(/[?#].*$/, '').slice(0, 80); }
    D.net = c.effectiveType || ''; D.rtt = c.rtt >= 0 ? c.rtt : -1; D.dn = c.downlink > 0 ? Math.round(c.downlink * 10) : 0;
    D.mem = N.deviceMemory || 0; D.cpu = N.hardwareConcurrency || 0; D.m = /Mobi|Android/i.test(N.userAgent) ? 1 : 0;
    D.early = n.loadEventEnd ? 0 : 1; D.rf = rf;
    try { N.sendBeacon(url, JSON.stringify(D)); } catch (e) {}
  }
  addEventListener('load', function () { setTimeout(send, 2500); });
  addEventListener('pagehide', send);
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') { send(); } });
})();
</script>
