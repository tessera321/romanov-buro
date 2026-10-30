/* Отправка заявок со всех форм сайта на /api/lead.php.
   Слушает submit на уровне документа (фаза перехвата) — раньше обработчиков страниц,
   поэтому их интерфейс («Спасибо…») не меняется. Номер короче 10 цифр не отправляется:
   страница сама покажет ошибку. */
(function () {
  var ENDPOINT = '/api/lead.php';

  // UTM и источник запоминаем на первой странице визита
  try {
    var q = new URLSearchParams(location.search), utm = {};
    ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'yclid'].forEach(function (k) {
      if (q.get(k)) utm[k] = q.get(k);
    });
    if (Object.keys(utm).length) sessionStorage.setItem('rb_utm', JSON.stringify(utm));
    if (!sessionStorage.getItem('rb_ref')) sessionStorage.setItem('rb_ref', document.referrer || '');
  } catch (e) {}

  function txt(el) { return (el && el.textContent || '').replace(/\s+/g, ' ').trim(); }

  function labelOf(input, form) {
    if (input.id) {
      var l = form.querySelector('label[for="' + input.id + '"]') || document.querySelector('label[for="' + input.id + '"]');
      if (l) return txt(l);
    }
    var g = input.closest('label, .form-group');
    if (g) { var gl = g.querySelector('label'); if (gl && gl !== input) return txt(gl); }
    return input.getAttribute('placeholder') || input.name || input.type;
  }

  function collect(form) {
    var fields = {}, phone = '';
    form.querySelectorAll('input, textarea, select').forEach(function (el) {
      if (el.type === 'hidden' || el.type === 'submit' || el.type === 'button' || el.type === 'file') return;
      if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
      var v = el.tagName === 'SELECT' ? txt(el.options[el.selectedIndex]) : (el.value || '').trim();
      if (!v) return;
      if (el.type === 'tel' && !phone) { phone = v; return; }
      fields[labelOf(el, form)] = v;
    });

    var pill = form.querySelector('.pill[aria-pressed="true"], .pill[aria-checked="true"]');
    var channel = pill ? txt(pill) : '';
    if (!channel) { // в части форм способ связи — выпадающий список
      [].some.call(form.querySelectorAll('select'), function (s) {
        var o = s.options[s.selectedIndex];
        if (o && !o.disabled && /^(Telegram|MAX|WhatsApp|Звонок)$/.test(txt(o))) { channel = txt(o); return true; }
      });
    }

    // Ответы квиза: выбранные варианты в блоке вокруг формы
    var scope = form.closest('section, .quiz-container') || form.parentElement || form;
    var answers = [];
    scope.querySelectorAll('.selected, [aria-pressed="true"], [aria-checked="true"]').forEach(function (el) {
      if (form.contains(el) && el.classList.contains('pill')) return; // канал связи уже учтён
      var t = txt(el); if (!t || t.length > 140) return;
      var host = el.closest('[data-key]');
      var qt = host && host.querySelector('.quiz-q-text');
      answers.push(qt ? txt(qt) + ' — ' + t : t);
    });
    var range = scope.querySelector('#qrRange');
    if (range && txt(range) && txt(range) !== '—') fields['Предварительный расчёт'] = txt(range);

    var heading = txt((form.closest('section') || document).querySelector('h2, h1'));
    var utm = {};
    try { utm = JSON.parse(sessionStorage.getItem('rb_utm') || '{}'); } catch (e) {}

    return {
      phone: phone, channel: channel, fields: fields, answers: answers,
      form: form.className || form.id || 'form', block: heading,
      page: document.title, url: location.href,
      referrer: (function () { try { return sessionStorage.getItem('rb_ref') || ''; } catch (e) { return ''; } })(),
      utm: utm, website: (form.querySelector('[name="website"]') || {}).value || ''
    };
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement) || form.dataset.leadSent) return;
    var data = collect(form);
    if (data.phone.replace(/\D/g, '').length < 10) return;
    form.dataset.leadSent = '1';
    var fileInput = form.querySelector('input[type="file"]');
    var file = fileInput && fileInput.files && fileInput.files[0];

    // После отправки — страница «Спасибо»: способ связи и тип объекта для её текста и ссылок
    var p = location.pathname, COM = /kommerch|shourum-falmec|marcelo-miracles|klub-na-polyarnoy/;
    var type = /domov/.test(p) ? 'house' : COM.test(p) ? 'com' : 'apt';
    var thanks = '/spasibo/?t=' + type + (data.channel ? '&ch=' + encodeURIComponent(data.channel) : '');
    var left = false;
    function go() { if (left) return; left = true; location.href = thanks; }

    function send() {
      var body = JSON.stringify(data);
      setTimeout(go, 3000); // если сеть зависла — заявку всё равно добьёт keepalive/sendBeacon
      try {
        fetch(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true })
          .then(function (r) { if (!r.ok) throw new Error(r.status); })
          .catch(function () { if (navigator.sendBeacon) navigator.sendBeacon(ENDPOINT, new Blob([body], { type: 'application/json' })); })
          .then(function () { setTimeout(go, 600); });
      } catch (err) { setTimeout(go, 600); }
    }

    // Файл («Прикрепите проект или смету») сначала кладём на сервер, в заявку уходит его номер.
    // Не загрузился (размер, тип, сеть) — заявка всё равно уходит, с пометкой.
    if (file && window.fetch && window.FormData) {
      var fd = new FormData(); fd.append('file', file);
      var done = false;
      var fallback = setTimeout(function () { if (done) return; done = true; data.fields['Файл'] = file.name + ' — не успел загрузиться, попросить прислать в мессенджер'; send(); }, 90000);
      fetch('/api/upload.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .then(function (j) {
          if (done) return; done = true; clearTimeout(fallback);
          if (j && j.ok && j.id) data.files = [j.id];
          else data.fields['Файл'] = file.name + ' — не загрузился (' + ((j && j.error) || 'ошибка') + '), попросить прислать в мессенджер';
          send();
        })
        .catch(function () { if (done) return; done = true; clearTimeout(fallback); data.fields['Файл'] = file.name + ' — не загрузился, попросить прислать в мессенджер'; send(); });
    } else {
      send();
    }
    try { if (window.ym && window.RB_METRIKA_ID) ym(window.RB_METRIKA_ID, 'reachGoal', 'lead'); } catch (err) {}
  }, true);
})();
