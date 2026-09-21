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
     • به‌روزشدن زنده‌ی برچسب قیمت هر گزینه هم‌زمان با تایپ
     • نوار پرش سریع بین بخش‌های ویرایشگر
     • نشانگر «ذخیره‌نشده» و هشدار پیش از بستن صفحه
   ========================================================================== */
(function (W) {
  'use strict';

  var ZZ = W.ZZ;
  if (!ZZ || !ZZ.u || typeof ZZ.icon !== 'function') { return; }

  var u    = ZZ.u;
  var icon = ZZ.icon;
  var CUR  = (ZZ.config && ZZ.config.booking && ZZ.config.booking.currency) || 'تومان';

  /* اگر مجموعه‌ی گزینه‌ها عوض شود، حافظه‌ی محلی پنل دیگر با سرور
     هم‌خوان نیست و ذخیره‌ی بعدی ردیف تکراری می‌سازد. */
  var varSetChanged = false;
  var saveClicked   = false;

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

  /* شماره‌ها و وضعیت دکمه‌های جابه‌جایی را با ترتیب تازه هم‌تراز می‌کند */
  function syncControls(wrap) {
    var cards = u.$$('.svc-var', wrap);
    var last  = cards.length - 1;

    cards.forEach(function (card, i) {
      var num = u.$('.svc-var__num', card);
      var label = u.toFa(i + 1);
      if (num && num.textContent !== label) { num.textContent = label; }

      var price = u.$('.svc-var__price', card);
      if (price) { price.setAttribute('data-price-live', i); }

      var up   = u.$('[data-var-up]', card);
      var down = u.$('[data-var-down]', card);
      if (up)   { up.disabled   = (i === 0); }
      if (down) { down.disabled = (i === last); }
    });
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
    syncControls(wrap);

    varSetChanged = true;
    markDirty();

    var name = u.$('[data-f="name"]', card);
    if (name) { name.focus(); }
  }

  function deleteVariant(btn) {
    var card = btn.closest('.svc-var');
    var wrap = variantWrap(btn);
    if (!card || !wrap) { return; }

    if (u.$$('.svc-var', wrap).length <= 1) {
      notify('حداقل یک گزینه لازم است؛ خدمت بدون قیمت معنا ندارد.');
      return;
    }

    wrap.removeChild(card);
    syncControls(wrap);
    varSetChanged = true;
    markDirty();
  }

  function moveVariant(btn, dir) {
    var card = btn.closest('.svc-var');
    var wrap = variantWrap(btn);
    if (!card || !wrap) { return; }

    var sib = dir === -1 ? card.previousElementSibling : card.nextElementSibling;
    if (!sib || !sib.classList || !sib.classList.contains('svc-var')) { return; }

    if (dir === -1) { wrap.insertBefore(card, sib); } else { wrap.insertBefore(sib, card); }
    syncControls(wrap);
    varSetChanged = true;
    markDirty();
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

  /* پنل بعد از ذخیره، فهرست گزینه‌ها را در حافظه‌ی محلی دست‌نخورده
     می‌گذارد؛ پس اگر مجموعه‌ی گزینه‌ها عوض شده باشد، همان یک بار صفحه را
     نوسازی می‌کنیم تا فهرست با سرور هم‌خوان شود. کمی صبر می‌کنیم تا
     پیام «ذخیره شد» دیده شود. */
  function watchSave(edit) {
    if (edit.__zzSaveWatch) { return; }
    edit.__zzSaveWatch = true;

    new MutationObserver(function () {
      if (!varSetChanged || !saveClicked) { return; }
      if (edit.querySelector('[data-save].is-dirty')) { return; }

      varSetChanged = false;
      saveClicked = false;
      W.setTimeout(function () { W.location.reload(); }, 700);
    }).observe(edit, { subtree: true, attributes: true, attributeFilter: ['class'] });
  }

  function decorate() {
    var edit = editRoot();
    if (!edit) { return; }
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

    var del = t.closest('[data-var-del]');
    if (del) { deleteVariant(del); return; }

    var up = t.closest('[data-var-up]');
    if (up) { moveVariant(up, -1); return; }

    var down = t.closest('[data-var-down]');
    if (down) { moveVariant(down, 1); return; }

    if (t.closest('[data-save]')) { saveClicked = true; }
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
