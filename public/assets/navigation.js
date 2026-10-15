// Navigation and unsaved-form protection. Draft contents stay in this page's memory.
(function () {
  'use strict';
  var forms = Array.from(document.querySelectorAll('main form[method="post"], main form[method="POST"]'));
  var initial = new Map(), restored = new Set(), leaving = false, submitting = null;
  function values(form) {
    return JSON.stringify(Array.from(form.elements).filter(function (e) {
      return e.name && !e.disabled && !/^(hidden|submit|button|reset|image)$/i.test(e.type);
    }).map(function (e) {
      if (e.type === 'checkbox' || e.type === 'radio') return [e.name, e.value, e.checked];
      if (e.type === 'file') return [e.name, Array.from(e.files || []).map(function (f) { return [f.name, f.size, f.lastModified]; })];
      if (e.multiple && e.options) return [e.name, Array.from(e.options).filter(function (o) { return o.selected; }).map(function (o) { return o.value; })];
      return [e.name, e.value];
    }));
  }
  forms.forEach(function (f) {
    initial.set(f, values(f));
    if (Array.from(f.elements).some(function (e) {
      return e.name && /^(submit|hidden)$/.test(e.type) && (window.cmsRestoredFields || []).indexOf(e.name) >= 0
        && /^(add_note|add_track_note|save_assessment|save_note|gen_track_note|generate_note|ai_do|generate)$/.test(e.name);
    })) restored.add(f);
  });
  function changed(except) { return forms.find(function (f) { return f !== except && (restored.has(f) || values(f) !== initial.get(f)); }); }
  function menu() {
    var list = document.getElementById('main-nav'), toggle = document.getElementById('nav-toggle');
    if (!list) return;
    list.classList.add('open');
    if (toggle) toggle.setAttribute('aria-expanded', 'true');
    list.focus();
  }
  if (location.hash === '#main-nav') menu();
  // Use a same-site previous page when known; direct arrivals retain Dashboard as fallback.
  document.querySelectorAll('[data-cms-back]').forEach(function (a) {
    try {
      var previous = new URL(document.referrer);
      if (previous.origin === location.origin && previous.href.split('#')[0] !== location.href.split('#')[0] && /\.php$/.test(previous.pathname)
          && !/\/(login|logout|verify|reset|sso|passkey)\.php$/.test(previous.pathname)) a.href = previous.href;
    } catch (e) {}
  });
  var dialog = document.createElement('dialog');
  dialog.className = 'unsaved-dialog';
  dialog.lang = 'en';
  dialog.setAttribute('aria-labelledby', 'unsaved-title');
  dialog.setAttribute('aria-describedby', 'unsaved-description');
  dialog.innerHTML = '<h2 id="unsaved-title">Unsaved changes</h2><p id="unsaved-description">You have changes that have not been saved. Save the form, keep editing, or leave without saving.</p><div class="page-actions"><button type="button" data-save>Save changes</button><button type="button" data-stay autofocus>Keep editing</button><button type="button" data-leave>Leave without saving</button></div>';
  document.body.appendChild(dialog);
  var save = dialog.querySelector('[data-save]'), stay = dialog.querySelector('[data-stay]'), leave = dialog.querySelector('[data-leave]');
  var destination = null, dirtyForm = null, saveButton = null, priorFocus = null;
  function close() { dialog.close(); if (priorFocus && priorFocus.focus) priorFocus.focus(); }
  function ask(action, form) {
    dirtyForm = form; destination = action; priorFocus = document.activeElement;
    // Prefer known note saves; otherwise offer only a single explicitly labelled Save action.
    // Do not guess between multiple actions or submit AI generation/deletion buttons.
    saveButton = form.querySelector('button[name="add_note"], button[name="add_track_note"], button[name="save_assessment"]');
    if (!saveButton) {
      var saves = Array.from(form.elements).filter(function (e) {
        return e.type === 'submit' && !e.disabled && /^(Save|Guardar)(\s|$)/i.test((e.textContent || e.value || '').trim());
      });
      if (saves.length === 1) saveButton = saves[0];
    }
    save.hidden = !saveButton || saveButton.disabled || typeof form.requestSubmit !== 'function';
    if (typeof dialog.showModal !== 'function') {
      if (window.confirm('Leave without saving your changes? Choose Cancel to return to the form and save.')) { leaving = true; action(); }
      return;
    }
    if (!dialog.open) dialog.showModal();
    stay.focus();
  }
  stay.addEventListener('click', close);
  dialog.addEventListener('cancel', function (e) { e.preventDefault(); close(); });
  leave.addEventListener('click', function () { var action = destination; dialog.close(); leaving = true; action(); });
  save.addEventListener('click', function () {
    var form = dirtyForm, button = saveButton;
    close();
    // The real form handles validation, session renewal and errors. Never navigate
    // away on an assumed save; its normal successful response determines the destination.
    if (button && !button.disabled) form.requestSubmit(button);
  });
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || e.defaultPrevented || e.button > 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || a.hasAttribute('download') || (a.target && a.target !== '_self')) return;
    var url = new URL(a.href, location.href);
    if (a.hasAttribute('data-cms-menu') && document.getElementById('main-nav')) { e.preventDefault(); menu(); return; }
    if (!/^https?:$/.test(url.protocol) || (url.pathname === location.pathname && url.search === location.search && url.hash)) return;
    var form = changed();
    if (form) { e.preventDefault(); ask(function () { location.assign(url.href); }, form); }
  });
  document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    var other = changed(e.target);
    if (other && !leaving) {
      e.preventDefault();
      var form = e.target, button = e.submitter;
      ask(function () {
        form.requestSubmit(button || undefined);
        // Invalid forms do not emit submit; keep the abandoned draft protected.
        if (!submitting) leaving = false;
      }, other);
      return;
    }
    submitting = e.target;
    // Session checks may defer submission. If they refuse it, keep protecting the draft.
    // A task, not a microtask: a user's submit runs microtasks between listeners, before
    // session-forms.js has decided, so only after dispatch is defaultPrevented final.
    setTimeout(function () { if (e.defaultPrevented && submitting === e.target) { submitting = null; leaving = false; } }, 0);
  });
  window.addEventListener('beforeunload', function (e) {
    if (!leaving && changed(submitting)) { e.preventDefault(); e.returnValue = ''; }
  });
  document.addEventListener('input', function () { leaving = false; submitting = null; });
  document.addEventListener('change', function () { leaving = false; submitting = null; });
  window.addEventListener('pageshow', function () { leaving = false; submitting = null; });
})();
