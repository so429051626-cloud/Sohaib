<?php
/*
 * حارس حداثة الصفحات المحمّلة مسبقاً: الصفحة التي جلبها المتصفح عند المرور على رابطها ثم فُتحت بعد أكثر من
 * 10 ثوانٍ تُطلب من الخادم من جديد فوراً، فلا تظهر بيانات قديمة أبداً.
 * يُقاس عمر الصفحة بفرق ساعة الجهاز عن ساعة الخادم المحفوظ من آخر صفحة فُتحت عادياً (يلغي اختلاف الساعتين).
 */
$generated = (int) round(microtime(true) * 1000);
?>
<script>
(function () {
  var gen = <?= $generated ?>, key = 'mnx-clock';
  try {
    var nav = performance.getEntriesByType('navigation')[0];
    if (nav && nav.deliveryType === 'navigational-prefetch') {
      var skew = parseFloat(sessionStorage.getItem(key));
      if (!isNaN(skew) && Date.now() - gen - skew > 10000) {
        // قياس السرعة: الصفحة الجديدة تُقاس من لمس الرابط، وتُعلَّم أنها طُلبت مرتين
        window.__mnxRefetch = true;
        try { sessionStorage.setItem('mnx-rum-refetch', '1'); } catch (e) {}
        location.replace(location.href);
      }
    } else {
      sessionStorage.setItem(key, String(Date.now() - gen));
    }
  } catch (e) {}
})();
</script>
