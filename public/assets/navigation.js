// Navigation and unsaved-form protection. Draft contents stay in this page's memory.
(function () {
  'use strict';
  var forms = Array.from(document.querySelectorAll('main form[method="post"], main form[method="POST"]'));
  var initial = new Map(), restored = new Set(), leaving = false, submitting = null, lastHintItem = null;
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
        && /^(add_note|add_track_note|add_log|save_assessment|save_note|gen_track_note|generate_note|ai_do|generate)$/.test(e.name);
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
  // Access keys: say the real key combination for this browser with each control (a hidden
  // description, read with the link or button, never a separate line) and as aria-keyshortcuts.
  (function () {
    if (typeof navigator === 'undefined') return;
    var ua = navigator.userAgent || '', mac = /Mac|iPhone|iPad/.test(navigator.platform || ua);
    // aria-keyshortcuts uses the ARIA key names (Alt, not Option); the visible hint says what
    // the keys are called on the keyboard. Screen readers announce aria-keyshortcuts with the
    // link, so there is no separate description for it (JAWS can also report the access key
    // itself; one channel keeps each link short).
    var ariaMod = mac ? 'Control+Alt+' : (/Firefox/.test(ua) ? 'Alt+Shift+' : 'Alt+');
    var shownMod = mac ? 'Control+Option+' : ariaMod;
    document.querySelectorAll('[accesskey]').forEach(function (el) {
      var key = el.getAttribute('accesskey'); if (!key) return;
      el.setAttribute('aria-keyshortcuts', ariaMod + key.toUpperCase());
      var item = el.closest && el.closest('.nav-item'), visual = item && item.querySelector('.nav-hint');
      if (visual) visual.textContent += ' Shortcut ' + shownMod + key.toUpperCase() + (mac ? ' (with VoiceOver, press Control+Option+Tab first)' : '') + '.';
    });
  })();
  // Escape dismisses a showing link hint (1.4.13), whether it came from keyboard focus or
  // from the pointer resting on the link; moving to another link brings hints back.
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var focused = document.activeElement && document.activeElement.closest && document.activeElement.closest('.nav-item');
    var hovered = document.querySelector('.nav-item:hover');
    if (focused || hovered || lastHintItem) document.documentElement.classList.add('cms-hints-off');
  });
  ['focusin', 'mouseover'].forEach(function (name) {
    document.addEventListener(name, function (e) {
      var item = e.target.closest && e.target.closest('.nav-item');
      if (item && item !== lastHintItem) { lastHintItem = item; document.documentElement.classList.remove('cms-hints-off'); }
    });
  });
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
    saveButton = form.querySelector('button[name="add_note"], button[name="add_track_note"], button[name="add_log"], button[name="save_assessment"]');
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
