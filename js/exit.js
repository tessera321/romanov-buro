/* Поп-ап «на выходе»: все бонусы бюро за номер телефона.
   Компьютер — курсор уходит за верхний край окна; телефон — быстрый скролл вверх
   после 30 секунд на странице. Один раз за сеанс и никогда после заявки.
   Заявку отправляет общий js/lead.js (он слушает submit всех форм). */
(function () {
  if (!window.HTMLDialogElement || /\/spasibo\//.test(location.pathname)) return;
  var LS = window.localStorage, SS = window.sessionStorage;
  function get(s, k) { try { return s.getItem(k); } catch (e) { return null; } }
  function set(s, k) { try { s.setItem(k, '1'); } catch (e) {} }

  // Любая отправленная заявка с телефоном — поп-ап больше не нужен
  document.addEventListener('submit', function (e) {
    var t = e.target && e.target.querySelector && e.target.querySelector('input[type="tel"]');
    if (t && t.value.replace(/\D/g, '').length >= 10) set(LS, 'rb_lead');
  }, true);
  if (get(LS, 'rb_lead') || get(SS, 'rb_exit')) return;

  var CSS = '' +
    '.ex-dlg{padding:0;border:1px solid rgba(181,161,138,.28);background:#141414;color:#F5F0E8;width:min(980px,calc(100vw - 32px));max-width:none;max-height:calc(100dvh - 32px);overflow:auto;font-family:"Wix Madefor Text",sans-serif;box-shadow:0 40px 120px rgba(0,0,0,.6)}' +
    '.ex-dlg::backdrop{background:rgba(8,8,8,.78);backdrop-filter:blur(4px)}' +
    '.ex-dlg[open]{animation:exIn .45s cubic-bezier(.16,1,.3,1)}@keyframes exIn{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:none}}' +
    '.ex-in{display:grid;grid-template-columns:1fr 1.05fr}' +
    '.ex-l{background:radial-gradient(120% 90% at 30% 10%,rgba(181,161,138,.16),transparent 60%),#0D0D0D;padding:36px 36px 30px;border-right:1px solid rgba(181,161,138,.15)}' +
    '.ex-vis{position:relative;height:250px;margin-bottom:26px}' +
    '.ex-vis img{position:absolute;display:block;box-shadow:0 18px 40px rgba(0,0,0,.55)}' +
    '.ex-vis .v1{width:52%;left:0;top:38px;transform:rotate(-4deg)}' +
    '.ex-vis .v2{width:36%;right:6%;top:0;transform:rotate(3deg)}' +
    '.ex-vis .v3{width:62%;right:-4%;bottom:-6px;box-shadow:none;filter:drop-shadow(0 14px 26px rgba(0,0,0,.55))}' +
    '.ex-cap{display:block;font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:#B5A18A;margin-bottom:14px}' +
    '.ex-list{list-style:none;margin:0;padding:0;display:grid;gap:14px}' +
    '.ex-list li{position:relative;padding-left:30px;font-size:15px;line-height:1.35}' +
    '.ex-list li:before{content:"";position:absolute;left:0;top:1px;width:18px;height:18px;border:1px solid #B5A18A;border-radius:50%;background:url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 24 24%27 fill=%27none%27 stroke=%27%23B5A18A%27 stroke-width=%272.4%27%3E%3Cpath d=%27M7 12.5l3.2 3L17 9%27/%3E%3C/svg%3E") center/14px no-repeat}' +
    '.ex-list b{font-weight:600;display:block}.ex-list small{display:block;margin-top:3px;font-size:13px;color:rgba(245,240,232,.55)}' +
    '.ex-free{margin:22px 0 0;padding:12px 16px;border:1px solid rgba(181,161,138,.18);font-size:13px;color:rgba(245,240,232,.65)}' +
    '.ex-free b{color:#F5F0E8;font-weight:600}' +
    '.ex-r{position:relative;padding:40px 40px 32px;display:flex;flex-direction:column;margin:0}' +
    '.ex-x:focus:not(:focus-visible){outline:none}.ex-x{position:absolute;right:12px;top:10px;width:40px;height:40px;border:0;background:none;color:rgba(245,240,232,.6);font-size:28px;line-height:1;cursor:pointer}.ex-x:hover{color:#F5F0E8}' +
    '.ex-k{font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:#8C7E6A;margin-bottom:14px}' +
    '.ex-r h3{font-family:"Wix Madefor Display",sans-serif;font-weight:500;font-size:clamp(28px,3.2vw,38px);line-height:1.1;margin:0 0 14px;letter-spacing:-.01em}' +
    '.ex-r h3 span{color:#B5A18A}' +
    '.ex-d{margin:0 0 24px;font-size:15px;line-height:1.5;color:rgba(245,240,232,.65)}' +
    '.ex-lab{display:block;font-size:13px;color:rgba(245,240,232,.75);margin:0 0 10px}' +
    '.ex-pills{display:flex;gap:10px;margin-bottom:20px}' +
    '.ex-pills button{font:500 14px "Wix Madefor Text",sans-serif;padding:11px 20px;background:transparent;color:rgba(245,240,232,.7);border:1px solid rgba(181,161,138,.25);cursor:pointer}' +
    '.ex-pills button[aria-pressed="true"]{border-color:#B5A18A;color:#B5A18A;background:rgba(181,161,138,.12)}' +
    '.ex-ph{width:100%;box-sizing:border-box;font:500 17px "Wix Madefor Text",sans-serif;padding:15px 16px;background:#0D0D0D;color:#F5F0E8;border:1px solid rgba(181,161,138,.3);border-radius:0;outline:none}' +
    '.ex-ph:focus{border-color:#B5A18A}' +
    '.ex-err{margin:8px 0 0;font-size:13px;color:#e08f7a}' +
    '.ex-go{margin-top:18px;font:600 13px "Wix Madefor Text",sans-serif;letter-spacing:.06em;text-transform:uppercase;padding:18px 20px;background:#B5A18A;color:#0D0D0D;border:0;cursor:pointer;transition:background .25s}.ex-go:hover{background:#CDBEAA}' +
    '.ex-cons{margin:14px 0 0;font-size:11.5px;line-height:1.45;color:rgba(245,240,232,.42)}.ex-cons a{color:inherit}' +
    '@media (max-width:760px){.ex-dlg{width:calc(100vw - 20px);max-height:calc(100dvh - 20px)}.ex-in{grid-template-columns:1fr}' +
    '.ex-l{order:2;padding:22px 20px 22px;border-right:0;border-top:1px solid rgba(181,161,138,.15)}.ex-vis,.ex-free{display:none}' +
    '.ex-r{padding:30px 20px 22px}.ex-r h3{font-size:27px;padding-right:28px}.ex-d{font-size:14px;margin-bottom:18px}.ex-list li{font-size:14px}.ex-x{right:4px;top:4px}}';

  var st = document.createElement('style'); st.textContent = CSS; document.head.appendChild(st);

  var d = document.createElement('dialog');
  d.className = 'ex-dlg';
  d.setAttribute('aria-labelledby', 'exT');
  d.innerHTML = '<div class="ex-in">' +
    '<div class="ex-l"><div class="ex-vis" aria-hidden="true">' +
      '<img class="v1" src="/img/quiz/gift-catalog50.webp" alt="" loading="lazy" decoding="async">' +
      '<img class="v2" src="/img/quiz/gift-check20.webp" alt="" loading="lazy" decoding="async">' +
      '<img class="v3" src="/img/quiz/docs-mock3.webp" alt="" loading="lazy" decoding="async"></div>' +
      '<span class="ex-cap">Вы получите бесплатно</span><ul class="ex-list">' +
      '<li><b>Чек-лист «20 ошибок в дизайн-проекте»</b><small>одна такая ошибка после отделки — до 300 000 ₽ на переделках</small></li>' +
      '<li><b>Каталог 50 реализованных дизайн-проектов</b><small>планировки, визуализации и фото готовых объектов</small></li>' +
      '<li><b>Образец сметы и графика работ</b><small>как выглядит цена, зафиксированная в договоре</small></li></ul>' +
      '<p class="ex-free">Это <b>бесплатно и ни к чему не обязывает</b>.</p></div>' +
    '<form class="ex-r ex-form" novalidate><button type="button" class="ex-x" aria-label="Закрыть">×</button>' +
      '<span class="ex-k">БюроРОМАНОВЪ</span><h3 id="exT">Не уходите <span>с пустыми руками</span></h3>' +
      '<p class="ex-d">Заберите все материалы бюро за номер телефона — пришлём в удобный мессенджер в течение рабочего дня.</p>' +
      '<span class="ex-lab">Куда прислать?</span><div class="ex-pills" role="radiogroup">' +
      '<button type="button" class="pill" role="radio" aria-checked="true" aria-pressed="true">Telegram</button>' +
      '<button type="button" class="pill" role="radio" aria-checked="false" aria-pressed="false">MAX</button></div>' +
      '<label class="ex-lab" for="exPhone">Ваш номер телефона</label>' +
      '<input class="ex-ph" id="exPhone" type="tel" autocomplete="tel" inputmode="tel" placeholder="+7 (___) ___-__-__" required>' +
      '<label for="exSrc" hidden>Запрос</label><input id="exSrc" type="text" value="Все бонусы — поп-ап при уходе со страницы" readonly tabindex="-1" aria-hidden="true" style="display:none">' +
      '<p class="ex-err" hidden>Проверьте номер телефона</p>' +
      '<button class="ex-go" type="submit">Забрать все бонусы</button>' +
      '<p class="ex-cons">Нажимая кнопку, вы даёте <a href="/soglasie-na-obrabotku/">согласие на обработку персональных данных</a> и принимаете <a href="/politika-konfidencialnosti/">политику конфиденциальности</a>.</p>' +
    '</form></div>';
  document.body.appendChild(d);

  var f = d.querySelector('form'), ph = d.querySelector('#exPhone'), er = d.querySelector('.ex-err'), pw = d.querySelector('.ex-pills');
  pw.addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    [].forEach.call(pw.children, function (x) { var on = x === b; x.setAttribute('aria-pressed', on); x.setAttribute('aria-checked', on); });
  });
  // Маска +7 (___) ___-__-__
  ph.addEventListener('input', function () {
    var v = ph.value.replace(/\D/g, '');
    if (!v) { ph.value = ''; return; }
    if (v[0] === '8') v = '7' + v.slice(1); else if (v[0] !== '7') v = '7' + v;
    v = v.slice(0, 11);
    var r = '+7';
    if (v.length > 1) r += ' (' + v.slice(1, 4);
    if (v.length >= 4) r += ')';
    if (v.length > 4) r += ' ' + v.slice(4, 7);
    if (v.length > 7) r += '-' + v.slice(7, 9);
    if (v.length > 9) r += '-' + v.slice(9, 11);
    ph.value = r;
  });
  // На window в фазе перехвата — раньше lead.js (он слушает document): неполный номер не уходит
  window.addEventListener('submit', function (e) {
    if (e.target !== f) return;
    if (ph.value.replace(/\D/g, '').length < 11) { e.preventDefault(); e.stopImmediatePropagation(); er.hidden = false; ph.focus(); }
    else e.preventDefault(); // страницу не перезагружаем — lead.js сам отправит и уведёт на «Спасибо»
  }, true);
  d.addEventListener('click', function (e) { if (e.target === d || e.target.closest('.ex-x')) d.close(); });

  var t0 = Date.now(), shown = false;
  function busy() {
    if (document.querySelector('dialog[open]')) return true;
    var a = document.activeElement;
    return a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName) && a.value;
  }
  function show() {
    if (shown || get(LS, 'rb_lead') || get(SS, 'rb_exit') || busy()) return;
    shown = true; set(SS, 'rb_exit');
    d.showModal();
  }

  if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    document.addEventListener('mouseout', function (e) {
      if (e.relatedTarget || e.clientY > 8 || Date.now() - t0 < 5000) return;
      show();
    });
  } else {
    var ly = window.scrollY, lt = Date.now();
    window.addEventListener('scroll', function () {
      var y = window.scrollY, t = Date.now(), dy = y - ly, dt = t - lt;
      if (dt > 0 && dt < 250 && dy < 0 && -dy / dt > 2.2 && y > 600 && t - t0 > 30000) show();
      ly = y; lt = t;
    }, { passive: true });
  }
})();
