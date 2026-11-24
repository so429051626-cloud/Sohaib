/*
 * أدوات بوت تيليجرام في صفحات الإعداد:
 *   [data-tg-test="رابط"]   رسالة تجريبية بما كُتب في النموذج (أو المحفوظ إن كان الحقل فارغاً)
 *   [data-tg-detect="رابط"] المحادثات التي راسلت البوت مؤخراً، وتعبئة معرّف المحادثة منها
 * الحقلان: data-tg-token و data-tg-chat (محدّدا CSS)، والنتائج في [data-tg-found="محدّد حقل المحادثة"].
 */
(function (document) {
  'use strict';
  var TYPES = { private: 'حساب', group: 'مجموعة', supergroup: 'مجموعة', channel: 'قناة' };
  function field(sel) { return sel ? document.querySelector(sel) : null; }
  function val(sel) { var el = field(sel); return el ? el.value.trim() : ''; }

  function run(btn, url, done) {
    btn.classList.add('is-loading');
    btn.disabled = true;
    SN.api(url, { method: 'POST', data: { token: val(btn.getAttribute('data-tg-token')), chat: val(btn.getAttribute('data-tg-chat')) } })
      .then(done)
      .catch(function () {})
      .then(function () { btn.classList.remove('is-loading'); btn.disabled = false; });
  }

  function add(input, id) {
    var ids = input.value.split(/[\s,،;]+/).filter(Boolean);
    if (ids.indexOf(id) < 0) { ids.push(id); }
    input.value = ids.join(', ');
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function show(btn, r) {
    var sel = btn.getAttribute('data-tg-chat');
    var input = field(sel);
    var box = document.querySelector('[data-tg-found="' + sel + '"]');
    if (box) { box.innerHTML = ''; }
    var chats = r.chats || [];
    if (!chats.length) { SN.toast('warning', r.message); return; }
    if (chats.length === 1 && input && !input.value.trim()) {
      add(input, chats[0].id);
      SN.toast('success', 'تمت تعبئة المعرّف: ' + chats[0].name);
      return;
    }
    SN.toast('success', r.message);
    if (!box || !input) { return; }
    chats.forEach(function (c) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'tg-chip';
      var name = document.createElement('b'); name.textContent = c.name;
      var kind = document.createElement('small'); kind.textContent = TYPES[c.type] || '';
      var id = document.createElement('span'); id.dir = 'ltr'; id.textContent = c.id;
      b.appendChild(name); b.appendChild(kind); b.appendChild(id);
      b.addEventListener('click', function () { add(input, c.id); b.classList.add('is-picked'); });
      box.appendChild(b);
    });
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-tg-test]');
    if (t) { e.preventDefault(); run(t, t.getAttribute('data-tg-test'), function (r) { SN.toast('success', r.message); }); return; }
    var d = e.target.closest('[data-tg-detect]');
    if (d) { e.preventDefault(); run(d, d.getAttribute('data-tg-detect'), function (r) { show(d, r); }); }
  });
})(document);
