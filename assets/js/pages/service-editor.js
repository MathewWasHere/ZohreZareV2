/* ==========================================================================
   service-editor.js — بهبود تجربه‌ی ویرایش خدمت در پنل مدیریت
   زهره زارع
   --------------------------------------------------------------------------
   این فایل یک «لایه‌ی بهبود» است: روی همان نشانه‌گذاری‌ای سوار می‌شود که
   خودِ پنل می‌سازد و آن را کامل می‌کند — هیچ بخشی از پنل بازنویسی نمی‌شود.
   اگر این فایل اجرا نشود یا خطا بدهد، ویرایشگر قبلی دست‌نخورده کار می‌کند.

   چه چیزی اضافه می‌کند:
     • افزودن، حذف و جابه‌جایی گزینه‌های قیمت
       (پنل تا پیش از این فقط گزینه‌های موجود را ویرایش می‌کرد)
     • حذف قابل‌بازگشت — نوار «واگرد» تا یک اشتباه، داده‌ای را از بین نبرد
     • به‌روزشدن زنده‌ی برچسب قیمت هر گزینه هم‌زمان با تایپ
     • نوار پرش سریع بین بخش‌های ویرایشگر
     • نشانگر «ذخیره‌نشده» و هشدار پیش از بستن صفحه
     • اعلام تغییرات برای صفحه‌خوان‌ها و مدیریت درست فوکوس
   ========================================================================== */
(function (W) {
  'use strict';

  var ZZ = W.ZZ;
  if (!ZZ || !ZZ.u || typeof ZZ.icon !== 'function') { return; }

  var u    = ZZ.u;
  var icon = ZZ.icon;
  var CUR  = (ZZ.config && ZZ.config.booking && ZZ.config.booking.currency) || 'تومان';

  var UNDO_MS = 9000;

  var lastDeleted = null;   /* { wrap, node, before, name } */
  var undoTimer   = null;
  var saveInFlight = false;
  var saveSynced   = false; /* آیا آخرین ذخیره فهرست کامل گزینه‌ها را برگرداند؟ */

  /* ------------------------------------------------------------- ابزارها */

  function el(html) {
    var box = document.createElement('div');
    box.innerHTML = html;
    return box.firstElementChild;
  }

  function editRoot() {
    return u.$('.svc-edit');
  }

  function variantWrap(node) {
    return node && node.closest ? node.closest('.svc-vars') : null;
  }

  function notify(msg) {
    if (ZZ.toast && typeof ZZ.toast.error === 'function') {
      ZZ.toast.error(msg);
    } else if (ZZ.dialog && typeof ZZ.dialog.alert === 'function') {
      ZZ.dialog.alert({ title: 'توجه', message: msg });
    }
  }

  function money(n) {
    return (typeof u.money === 'function' ? u.money(n) : String(n)) + ' ' + CUR;
  }

  /* صفحه‌خوان‌ها تغییرات ساختاری را از DOM نمی‌فهمند؛ یک ناحیه‌ی اعلام
     برایشان نگه می‌داریم. ناحیه پیش از هر اعلام ساخته می‌شود تا از همان
     ابتدا در دسترس باشد. */
  function ensureLive() {
    var live = document.getElementById('zzSvcLive');
    if (!live) {
      live = el('<p id="zzSvcLive" class="zz-sr" role="status" aria-live="polite"></p>');
      document.body.appendChild(live);
    }
    return live;
  }

  function announce(msg) {
    var live = ensureLive();
    live.textContent = '';
    W.setTimeout(function () { live.textContent = msg; }, 40);
  }

  /* پنل وضعیت «تغییر ذخیره‌نشده» را فقط از رویداد input روی فیلدها
     می‌فهمد؛ اما افزودن یا حذف گزینه هیچ رویداد input تولید نمی‌کند.
     پس یک رویداد ساختگی روی یکی از فیلدها می‌فرستیم و در کنارش، به‌عنوان
     پشتیبان، خودِ نشانگر را هم روشن می‌کنیم. */
  function markDirty() {
    var edit = editRoot();
    if (!edit) { return; }

    var probe = u.$('[data-s="title"]', edit) || u.$('[data-f="name"]', edit);
    if (probe) {
      probe.dispatchEvent(new Event('input', { bubbles: true }));
    }
    u.$$('[data-save]', edit).forEach(function (btn) {
      btn.classList.add('is-dirty');
    });
  }

  /* شماره‌ها، برچسب گروه برای صفحه‌خوان و وضعیت دکمه‌های جابه‌جایی را با
     ترتیب تازه هم‌تراز می‌کند. */
  function syncControls(wrap) {
    var cards = u.$$('.svc-var', wrap);
    var last  = cards.length - 1;
    var total = u.toFa(cards.length);

    cards.forEach(function (card, i) {
      var num = u.$('.svc-var__num', card);
      var label = u.toFa(i + 1);
      if (num && num.textContent !== label) { num.textContent = label; }

      var price = u.$('.svc-var__price', card);
      if (price) { price.setAttribute('data-price-live', i); }

      /* هر گزینه یک گروه نام‌دار است تا فیلدهایش بی‌نام و بی‌مرجع خوانده نشوند */
      if (card.getAttribute('role') !== 'group') { card.setAttribute('role', 'group'); }
      var aria = 'گزینه ' + label + ' از ' + total;
      if (card.getAttribute('aria-label') !== aria) { card.setAttribute('aria-label', aria); }

      var up   = u.$('[data-var-up]', card);
      var down = u.$('[data-var-down]', card);
      if (up)   { up.disabled   = (i === 0); }
      if (down) { down.disabled = (i === last); }
    });

    return cards;
  }

  /* پس از یک ذخیره‌ی موفق، حافظه‌ی محلی پنل دقیقاً همان فهرست سرور است و
     سرور هم به همان ترتیب آرایه برمی‌گرداند. پس شماره‌ی ارجاع هر کارت را
     با جایگاهش در DOM هم‌تراز می‌کنیم تا ذخیره‌ی بعدی ردیف تکراری نسازد.
     (پیش‌تر به‌جای این کار کل صفحه نوسازی می‌شد.) */
  function resyncIndices(wrap) {
    u.$$('.svc-var', wrap).forEach(function (card, i) {
      card.setAttribute('data-vi', i);
    });
  }

  /* -------------------------------------------------------- نوار واگرد */

  function undoBarRemove() {
    if (undoTimer) { W.clearTimeout(undoTimer); undoTimer = null; }
    var bar = u.$('.svc-undo');
    if (bar && bar.parentNode) { bar.parentNode.removeChild(bar); }
  }

  function undoReset() {
    undoBarRemove();
    lastDeleted = null;
  }

  function showUndo(wrap, node, before, name) {
    undoBarRemove();
    lastDeleted = { wrap: wrap, node: node, before: before, name: name };

    wrap.appendChild(el(
      '<div class="svc-undo" role="status">'
      + icon('alert', null, 14)
      + '<span class="svc-undo__txt">گزینه «' + u.esc(name) + '» حذف شد.</span>'
      + '<button type="button" class="svc-undo__btn" data-var-undo="1">واگرد</button>'
      + '</div>'
    ));

    undoTimer = W.setTimeout(function () {
      undoBarRemove();
      lastDeleted = null;
    }, UNDO_MS);
  }

  function undoDelete() {
    if (!lastDeleted) { return; }
    var d = lastDeleted;
    var wrap = d.wrap;
    if (!wrap || !document.body.contains(wrap)) { undoReset(); return; }

    if (d.before && d.before.parentNode === wrap) {
      wrap.insertBefore(d.node, d.before);
    } else {
      wrap.insertBefore(d.node, u.$('[data-add-var]', wrap) || null);
    }

    undoBarRemove();
    lastDeleted = null;
    syncControls(wrap);
    markDirty();

    var name = u.$('[data-f="name"]', d.node);
    if (name) { name.focus(); }
    announce('گزینه «' + d.name + '» بازگردانده شد.');
  }

  /* -------------------------------------------------------- گزینه‌ها */

  function variantControls() {
    return el(
      '<span class="svc-var__btns">'
      + '<button type="button" class="row-btn" data-var-up="1"'
      + ' title="انتقال به بالا" aria-label="انتقال این گزینه به بالا">'
      + icon('chevronUp', null, 14) + '</button>'
      + '<button type="button" class="row-btn" data-var-down="1"'
      + ' title="انتقال به پایین" aria-label="انتقال این گزینه به پایین">'
      + icon('chevronDown', null, 14) + '</button>'
      + '<button type="button" class="row-btn row-btn--del" data-var-del="1"'
      + ' title="حذف این گزینه" aria-label="حذف این گزینه">'
      + icon('x', null, 14) + '</button>'
      + '</span>'
    );
  }

  /* گزینه‌ی تازه عمداً data-vi ندارد: پنل از همین صفت کلید گزینه را
     پیدا می‌کند، و نبودنش یعنی «تازه» — سرور خودش کلید یکتا می‌سازد. */
  function newVariantCard() {
    return el(
      '<div class="svc-var">'
      + '<div class="svc-var__foot svc-var__foot--top">'
      + '<span class="svc-var__num">۱</span>'
      + '<span class="svc-var__price" data-price-live="0">' + money(0) + '</span>'
      + variantControls().outerHTML
      + '</div>'
      + '<div class="svc-var__grid">'
      + '<label class="svc-field svc-field--name"><span>نام گزینه</span>'
      + '<input class="input" type="text" data-f="name" value=""></label>'
      + '<label class="svc-field"><span>قیمت — ' + CUR + '</span>'
      + '<input class="input ltr" type="number" min="0" step="50000" data-f="price" value="0"></label>'
      + '<label class="svc-field"><span>مدت (دقیقه)</span>'
      + '<input class="input ltr" type="number" min="5" step="15" data-f="duration_min" value="60"></label>'
      + '</div>'
      + '<label class="svc-field"><span>توضیح کوتاه</span>'
      + '<input class="input" type="text" data-f="note" value=""></label>'
      + '</div>'
    );
  }

  function addVariant(btn) {
    var wrap = variantWrap(btn);
    if (!wrap) { return; }

    var card = newVariantCard();
    wrap.insertBefore(card, btn);
    var cards = syncControls(wrap);

    markDirty();
    announce('گزینه تازه افزوده شد. ' + u.toFa(cards.length) + ' گزینه.');

    var name = u.$('[data-f="name"]', card);
    if (name) { name.focus(); }
  }

  function deleteVariant(btn) {
    var card = btn.closest('.svc-var');
    var wrap = variantWrap(btn);
    if (!card || !wrap) { return; }

    var cards = u.$$('.svc-var', wrap);
    if (cards.length <= 1) {
      notify('حداقل یک گزینه لازم است؛ خدمت بدون قیمت معنا ندارد.');
      return;
    }

    var idx    = cards.indexOf(card);
    var before = card.nextElementSibling;
    var field  = u.$('[data-f="name"]', card);
    var name   = (field && field.value.trim()) || ('گزینه ' + u.toFa(idx + 1));

    wrap.removeChild(card);
    var rest = syncControls(wrap);
    markDirty();
    showUndo(wrap, card, before, name);

    /* فوکوس نباید به بدنه‌ی صفحه پرت شود؛ روی گزینه‌ی بعدی (یا قبلی) می‌ماند */
    var target = rest[Math.min(idx, rest.length - 1)];
    var focusEl = target && (u.$('[data-var-del]', target) || u.$('[data-f="name"]', target));
    if (!focusEl) { focusEl = u.$('[data-add-var]', wrap); }
    if (focusEl) { focusEl.focus(); }
  }

  function moveVariant(btn, dir) {
    var card = btn.closest('.svc-var');
    var wrap = variantWrap(btn);
    if (!card || !wrap) { return; }

    var sib = dir === -1 ? card.previousElementSibling : card.nextElementSibling;
    if (!sib || !sib.classList || !sib.classList.contains('svc-var')) { return; }

    if (dir === -1) { wrap.insertBefore(card, sib); } else { wrap.insertBefore(sib, card); }
    var cards = syncControls(wrap);
    markDirty();

    /* دکمه‌ی همین جهت ممکن است الان در انتهای فهرست غیرفعال شده باشد */
    var cands = [u.$('[data-var-up]', card), u.$('[data-var-down]', card)];
    var focusEl = cands.filter(function (b) { return b && !b.disabled; })[0] || u.$('[data-var-del]', card);
    if (focusEl) { focusEl.focus(); }

    announce('گزینه به جایگاه ' + u.toFa(cards.indexOf(card) + 1) + ' منتقل شد.');
  }

  /* ------------------------------------------------------------- تزئین */

  function decorateVariants(edit) {
    var wrap = u.$('.svc-vars', edit);
    if (!wrap) { return; }

    u.$$('.svc-var', wrap).forEach(function (card) {
      if (u.$('.svc-var__btns', card)) { return; }
      var foot = u.$('.svc-var__foot--top', card);
      if (foot) { foot.appendChild(variantControls()); }
    });

    if (!u.$('[data-add-var]', wrap)) {
      wrap.appendChild(el(
        '<button type="button" class="svc-add svc-add--var" data-add-var="1">'
        + icon('plus', null, 15) + 'افزودن گزینه</button>'
      ));
    }

    syncControls(wrap);
  }

  /* برچسب هر بخش از عنوان خودِ آن بخش خوانده می‌شود تا با تغییر متن‌های
     پنل، این نوار هم خودبه‌خود درست بماند. */
  function decorateSections(edit) {
    var groups = u.$$('.svc-group', edit);
    if (!groups.length) { return; }

    groups.forEach(function (group, i) {
      if (!group.id) { group.id = 'svc-sec-' + i; }
    });

    if (u.$('.svc-nav', edit)) { return; }
    var bar = u.$('.svc-edit__bar', edit);
    if (!bar) { return; }

    var nav = el('<nav class="svc-nav" aria-label="پرش به بخش"></nav>');
    groups.forEach(function (group) {
      var titleEl = u.$('.svc-group__title', group);
      var label = titleEl ? titleEl.textContent.trim() : '';
      if (!label) { return; }
      nav.appendChild(el(
        '<button type="button" class="svc-nav__link" data-jump="' + group.id + '">'
        + u.esc(label) + '</button>'
      ));
    });
    if (!nav.firstElementChild) { return; }
    bar.insertAdjacentElement('afterend', nav);
  }

  function decorateDirty(edit) {
    var bar = u.$('.svc-edit__bar', edit);
    if (!bar || u.$('.svc-dirty-chip', bar)) { return; }
    var save = u.$('[data-save]', bar);
    if (!save) { return; }

    save.insertAdjacentElement('beforebegin', el(
      '<span class="svc-dirty-chip">' + icon('alert', null, 13) + 'ذخیره‌نشده</span>'
    ));
  }

  /* --------------------------------------------------- ذخیره و هم‌خوانی */

  /* پاسخ سرور فقط وقتی «کامل» است که فهرست گزینه‌ها را با کلیدهایشان
     برگرداند. همان شرطی که در Ja() پنل هم بررسی می‌شود. */
  function isServerList(res) {
    return !!(res && Array.isArray(res.variants) && res.variants.length
      && res.variants.every(function (v) { return v && v.id; }));
  }

  /* مسیر ذخیره‌ی پنل را نگاه می‌داریم تا بدانیم پاسخ، فهرست کامل را
     داشت یا نه — چون فقط در حالت اول می‌توان بی‌نوسازی صفحه ادامه داد. */
  function wrapApi() {
    var A = ZZ.appointments && ZZ.appointments.admin;
    if (!A || typeof A.updateService !== 'function' || A.__zzWrapped) { return; }

    var orig = A.updateService;
    A.updateService = function () {
      var p = orig.apply(this, arguments);
      if (!p || typeof p.then !== 'function') { return p; }
      return p.then(function (res) {
        saveSynced = isServerList(res);
        return res;
      }, function (err) {
        saveSynced = false;
        throw err;
      });
    };
    A.__zzWrapped = true;
  }

  function watchSave(edit) {
    if (edit.__zzSaveWatch) { return; }
    edit.__zzSaveWatch = true;

    /* پنل در پایان ذخیره، is-loading و is-dirty را در دو تغییر جداگانه
       برمی‌دارد؛ پس بلافاصله تصمیم نمی‌گیریم و کمی صبر می‌کنیم تا هر دو
       بنشینند. */
    new MutationObserver(function () {
      if (!saveInFlight) { return; }
      var save = u.$('[data-save]', edit);
      if (!save || save.classList.contains('is-loading')) { return; }

      W.setTimeout(function () {
        if (!saveInFlight) { return; }
        var btn = u.$('[data-save]', edit);
        if (!btn || btn.classList.contains('is-loading')) { return; }

        saveInFlight = false;
        if (btn.classList.contains('is-dirty')) { return; }   /* ذخیره نشد */

        var wrap = u.$('.svc-vars', edit);
        if (saveSynced) {
          /* حافظه‌ی محلی حالا همان فهرست سرور است؛ فقط ارجاع‌ها را هم‌تراز کن */
          if (wrap) { resyncIndices(wrap); }
          undoReset();
        } else if (wrap && W.location && W.location.reload) {
          /* اگر پاسخ فهرست کامل را نداشت، تنها راه امن، نوسازی صفحه است */
          W.setTimeout(function () { W.location.reload(); }, 500);
        }
      }, 60);
    }).observe(edit, { subtree: true, attributes: true, attributeFilter: ['class'] });
  }

  function decorate() {
    var edit = editRoot();
    if (!edit) { return; }
    ensureLive();
    decorateVariants(edit);
    decorateSections(edit);
    decorateDirty(edit);
    watchSave(edit);
  }

  /* --------------------------------------------------------- رویدادها */

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) { return; }

    var jump = t.closest('[data-jump]');
    if (jump) {
      var target = document.getElementById(jump.getAttribute('data-jump'));
      if (target) {
        target.scrollIntoView({
          behavior: u.reducedMotion && u.reducedMotion() ? 'auto' : 'smooth',
          block: 'start'
        });
      }
      return;
    }

    var add = t.closest('[data-add-var]');
    if (add) { addVariant(add); return; }

    var undo = t.closest('[data-var-undo]');
    if (undo) { undoDelete(); return; }

    var del = t.closest('[data-var-del]');
    if (del) { deleteVariant(del); return; }

    var up = t.closest('[data-var-up]');
    if (up) { moveVariant(up, -1); return; }

    var down = t.closest('[data-var-down]');
    if (down) { moveVariant(down, 1); return; }

    if (t.closest('[data-save]')) {
      saveInFlight = true;
      saveSynced = false;
      undoReset();
    }
  }, true);

  /* برچسب قیمت هر گزینه هم‌زمان با تایپ به‌روز شود.
     (پنل صفت data-price-live را می‌ساخت ولی هیچ‌وقت به‌روزش نمی‌کرد.) */
  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !t.dataset || t.dataset.f !== 'price') { return; }

    var card  = t.closest('.svc-var');
    var badge = card && u.$('.svc-var__price', card);
    if (!badge) { return; }

    var n = parseInt(String(t.value).replace(/[^\d]/g, ''), 10);
    badge.textContent = money(isNaN(n) ? 0 : n);
  }, true);

  /* نام خدمت: فاصله‌های اضافی حذف و خالی‌بودن درجا نشان داده شود.
     خودِ پنل هم نام خالی را رد می‌کند؛ این فقط نشانه‌گذاری دیداری است. */
  document.addEventListener('blur', function (e) {
    var t = e.target;
    if (!t || !t.dataset || t.dataset.s !== 'title') { return; }

    var box = t.closest('.svc-field');
    var val = t.value.trim();
    if (val !== t.value) { t.value = val; }
    if (box) { box.classList.toggle('is-invalid', val === ''); }
  }, true);

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !t.dataset || t.dataset.s !== 'title') { return; }
    var box = t.closest('.svc-field');
    if (box) { box.classList.remove('is-invalid'); }
  }, true);

  /* روی گوشی بستن تب یا نوسازی صفحه، تغییرات ذخیره‌نشده را از بین می‌برد. */
  W.addEventListener('beforeunload', function (e) {
    if (document.querySelector('.svc-edit [data-save].is-dirty')) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  /* ---------------------------------------------------------- راه‌اندازی */

  function boot() {
    wrapApi();

    var root = document.getElementById('adminRoot');
    if (!root) { return; }

    var pending = null;
    new MutationObserver(function () {
      if (pending) { return; }
      pending = W.setTimeout(function () {
        pending = null;
        decorate();
      }, 60);
    }).observe(root, { childList: true, subtree: true });

    decorate();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window);
