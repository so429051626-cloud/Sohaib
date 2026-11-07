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

  // تصغير الصورة في المتصفح قبل الرفع: صور الجوال (3 إلى 8 ميغابايت) تتجاوز حد أغلب الاستضافات
  var upload = document.querySelector('form.upload');
  if (upload && window.FileReader && window.DataTransfer) {
    var input = upload.querySelector('input[type=file]');
    var limit = parseInt(upload.getAttribute('data-limit'), 10) || 2097152;
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
        var max = 1200;
        var scale = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(img.naturalWidth * scale);
        canvas.height = Math.round(img.naturalHeight * scale);
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#0a1120';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        URL.revokeObjectURL(url);
        canvas.toBlob(function (blob) {
          try {
            if (blob && blob.size < file.size) {
              var dt = new DataTransfer();
              dt.items.add(new File([blob], 'avatar.jpg', { type: 'image/jpeg' }));
              input.files = dt.files;
            }
          } catch (err) { /* نرفع الأصلية */ }
          send();
        }, 'image/jpeg', 0.9);
      };
      img.onerror = function () { URL.revokeObjectURL(url); send(); };
      img.src = url;
    });
  }

  // إخفاء رسالة النجاح بعد قليل
  var ok = document.querySelector('.alert--ok[role="status"]');
  if (ok) setTimeout(function () { ok.style.transition = 'opacity .5s'; ok.style.opacity = '0'; setTimeout(function () { ok.remove(); }, 600); }, 4000);
})();
