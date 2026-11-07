(function () {
  'use strict';

  // عدّاد الأحرف لحقلي العنوان والوصف في جوجل
  document.querySelectorAll('[data-max]').forEach(function (field) {
    var max = parseInt(field.getAttribute('data-max'), 10);
    var out = document.querySelector('.count[data-for="' + field.id + '"]');
    if (!out) return;
    var update = function () {
      var len = Array.from(field.value).length;
      out.textContent = len + ' / ' + max + (len > max ? ' (طويل، قد يُقصّ في نتائج البحث)' : '');
      out.classList.toggle('is-over', len > max);
    };
    field.addEventListener('input', update);
    update();
  });

  // تنبيه قبل مغادرة صفحة التعديل بدون حفظ
  var editor = document.querySelector('form.editor');
  if (editor) {
    var dirty = false;
    editor.addEventListener('input', function () { dirty = true; });
    editor.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (e) {
      if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });
  }

  // تصغير الصورة في المتصفح قبل الرفع: صور الجوال (3 إلى 8 ميغابايت) تتجاوز حد أغلب الاستضافات.
  // صورة الواجهة (data-alpha) تُحفظ بصيغة تحافظ على الخلفية الشفافة.
  document.querySelectorAll('form.upload').forEach(function (upload) {
    if (!window.FileReader || !window.DataTransfer) return;
    var input = upload.querySelector('input[type=file]');
    var limit = parseInt(upload.getAttribute('data-limit'), 10) || 2097152;
    var alpha = upload.getAttribute('data-alpha') === '1';
    var busy = false;
    upload.addEventListener('submit', function (e) {
      var file = input.files && input.files[0];
      if (busy || !file || (file.size < 900000 && file.size < limit)) return;
      e.preventDefault();
      busy = true;
      var btn = upload.querySelector('button');
      if (btn) btn.disabled = true;
      var send = function () { upload.submit(); };
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var max = alpha ? 1400 : 1200;
        var scale = Math.min(1, max / (alpha ? img.naturalHeight : Math.max(img.naturalWidth, img.naturalHeight)));
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(img.naturalWidth * scale);
        canvas.height = Math.round(img.naturalHeight * scale);
        var ctx = canvas.getContext('2d');
        if (!alpha) {
          ctx.fillStyle = '#140608';
          ctx.fillRect(0, 0, canvas.width, canvas.height);
        }
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        URL.revokeObjectURL(url);
        canvas.toBlob(function (blob) {
          try {
            if (blob && blob.size < file.size) {
              var type = blob.type || (alpha ? 'image/png' : 'image/jpeg');
              var ext = type === 'image/webp' ? 'webp' : (type === 'image/png' ? 'png' : 'jpg');
              var dt = new DataTransfer();
              dt.items.add(new File([blob], 'photo.' + ext, { type: type }));
              input.files = dt.files;
            }
          } catch (err) { /* نرفع الأصلية */ }
          send();
        }, alpha ? 'image/webp' : 'image/jpeg', 0.9);
      };
      img.onerror = function () { URL.revokeObjectURL(url); send(); };
      img.src = url;
    });
  });

  // إخفاء رسالة النجاح بعد قليل
  var ok = document.querySelector('.alert--ok[role="status"]');
  if (ok) setTimeout(function () { ok.style.transition = 'opacity .5s'; ok.style.opacity = '0'; setTimeout(function () { ok.remove(); }, 600); }, 4000);
})();
