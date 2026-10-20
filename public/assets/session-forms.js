// Keep the live form (including files and multi-value fields) during re-authentication.
(function () {
  var ready = new WeakSet(), pending = new WeakSet(), sending = new Map(), resent = null;
  // The account that rendered this page (layout.php marks it on the timeout dialog).
  var shell = document.getElementById && document.getElementById('timeout-warn');
  var pageUser = shell ? shell.getAttribute('data-uid') : null;
  // A polite live region already in the page (layout.php) announces the check.
  // The message in the form is not a live region: its failures are announced by
  // moving focus to it, and a focused live region is read twice.
  var live = document.getElementById && document.getElementById('session-check-live');
  function progress(text) { if (live) live.textContent = text; }
  // Once a checked form has really been sent, the server may still be working on
  // it (sending an email can take most of a minute), and a second press would post
  // it again. The form is held: its button is marked unavailable, the live region
  // says it is sending, and further submits are refused until the page is shown
  // again (Back) or a minute has passed, so a form that only downloads a file is
  // not left stuck.
  var SENDING = 'Sending, please wait…', STILL = 'Still sending, please wait…';
  function hold(form, button) {
    var held = {button: button && typeof button.setAttribute === 'function' ? button : null, had: null};
    if (held.button) { held.had = held.button.getAttribute('aria-disabled'); held.button.setAttribute('aria-disabled', 'true'); }
    held.timer = setTimeout(function () { release(form); }, 60000);
    sending.set(form, held);
    progress(SENDING);
  }
  function release(form) {
    var held = sending.get(form);
    if (!held) return;
    sending.delete(form);
    clearTimeout(held.timer);
    if (held.button) {
      if (held.had === null) held.button.removeAttribute('aria-disabled'); else held.button.setAttribute('aria-disabled', held.had);
    }
    if (live && (live.textContent === SENDING || live.textContent === STILL)) progress('');
  }
  if (typeof window !== 'undefined' && window.addEventListener) {
    window.addEventListener('pageshow', function () { Array.from(sending.keys()).forEach(release); });
  }
  // A date or time only partly typed (for example no year, or no AM/PM) is posted
  // blank, which the server cannot tell from an empty field, so it would be lost
  // without a word. Stop the submission, say so on each such field and move focus
  // to the first: its message is in its description, so it is read once there.
  var PARTIAL = {
    'date': 'Enter the complete date (month, day and four-digit year), or clear the field.',
    'datetime-local': 'Enter the complete date and time (month, day, four-digit year, hour, minutes and AM or PM), or clear the field.',
    'time': 'Enter the complete time (hour, minutes and AM or PM), or clear the field.'
  };
  function partialDates(form) {
    // Clear the messages from an earlier attempt and give each field back its own
    // description and state (a server error on it keeps its aria-invalid).
    form.querySelectorAll('[data-partial-for]').forEach(function (old) { old.parentNode.removeChild(old); });
    form.querySelectorAll('[data-partial-field]').forEach(function (field) {
      var described = field.getAttribute('data-partial-describedby'), invalid = field.getAttribute('data-partial-invalid');
      if (described) field.setAttribute('aria-describedby', described); else field.removeAttribute('aria-describedby');
      if (invalid) field.setAttribute('aria-invalid', invalid); else field.removeAttribute('aria-invalid');
      field.removeAttribute('data-partial-field'); field.removeAttribute('data-partial-describedby'); field.removeAttribute('data-partial-invalid');
    });
    var first = null;
    form.querySelectorAll('input[type="date"], input[type="datetime-local"], input[type="time"]').forEach(function (field, index) {
      if (field.disabled || field.closest('[hidden]') || !field.validity || !field.validity.badInput) return;
      var key = field.id || field.name || String(index);
      var note = document.createElement('p');
      note.className = 'field-error';
      note.lang = 'en';
      note.id = 'err-' + key + '-partial';
      note.setAttribute('data-partial-for', '');
      note.textContent = PARTIAL[field.type] || PARTIAL.date;
      var described = field.getAttribute('aria-describedby') || '';
      field.setAttribute('data-partial-field', '');
      if (described) field.setAttribute('data-partial-describedby', described);
      if (field.hasAttribute('aria-invalid')) field.setAttribute('data-partial-invalid', field.getAttribute('aria-invalid'));
      field.insertAdjacentElement('afterend', note);
      field.setAttribute('aria-invalid', 'true');
      field.setAttribute('aria-describedby', (described ? described + ' ' : '') + note.id);
      if (!first) first = field;
    });
    if (first) first.focus();
    return !!first;
  }
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (form.method.toLowerCase() !== 'post' || !form.querySelector('[name="csrf"]') || /(?:^|\/)logout\.php(?:\?|$)/.test(form.action)) return;
    if (ready.has(form)) { ready.delete(form); resent = event; return; }
    if (event.defaultPrevented) return; // A page-specific handler owns this submission.
    if (sending.has(form)) { event.preventDefault(); progress(STILL); return; }
    // Only forms that skip the browser's own checks (novalidate) can be sent with a
    // half-typed date; this runs before the session check, and with or without it.
    if (form.noValidate && typeof form.querySelectorAll === 'function' && partialDates(form)) { event.preventDefault(); return; }
    // Without requestSubmit (Safari before 16) a checked form could not be sent on
    // with its button, so the browser submits it directly instead.
    if (typeof form.requestSubmit !== 'function') return;
    event.preventDefault();
    if (pending.has(form)) return;
    pending.add(form);
    var submitter = event.submitter;
    var controller = new AbortController();
    var deadline = setTimeout(function () { controller.abort(); }, 10000);
    var message = form.querySelector('.session-form-message');
    if (!message) {
      message = document.createElement('div');
      message.className = 'session-form-message alert';
      // Its messages are English, also inside a translated public page (3.1.2).
      message.lang = 'en';
      message.tabIndex = -1;
      form.prepend(message);
    }
    message.textContent = 'Checking your session before submitting…';
    message.hidden = false;
    progress('Checking your session before submitting…');
    fetch('ping.php', {credentials: 'same-origin', signal: controller.signal, cache: 'no-store'})
      .then(function (response) {
        var token = response.headers.get('X-CSRF-Token');
        // If another account signed in on this browser since the page opened, its
        // token must not carry this form: the entry would be filed under that account.
        var signedIn = response.headers.get('X-User-Id');
        if (response.ok && token && pageUser && signedIn && signedIn !== pageUser) {
          message.textContent = 'A different account has signed in on this browser since this page was opened, so nothing was sent from this form. Your entries are still here. Copy them, then sign in as yourself and submit again.';
          message.focus();
        } else if (response.ok && token) {
          form.querySelector('[name="csrf"]').value = token;
          window.__csrf = token;
          message.hidden = true;
          ready.add(form);
          // requestSubmit fires the submit event again; the form's own inline
          // onsubmit (for example a confirm()) already ran and passed for this
          // submission, so it is set aside for the re-send, not asked twice.
          var inline = form.onsubmit;
          form.onsubmit = null;
          resent = null;
          try { form.requestSubmit(submitter || undefined); }
          finally { ready.delete(form); form.onsubmit = inline; }
          // Held only if the re-sent submission went ahead (no later handler stopped it).
          if (resent && !resent.defaultPrevented) hold(form, submitter);
          resent = null;
        } else if (response.status === 401) {
          // Tells voice.js to forget this session's conversation.
          if (document.dispatchEvent) { try { document.dispatchEvent(new Event('cms:session-ended')); } catch (e) {} }
          message.textContent = 'Your session ended. Your entries are still here. ';
          var link = document.createElement('a');
          link.href = 'login.php'; link.target = '_blank'; link.rel = 'noopener';
          link.textContent = 'Sign in again (opens in a new tab)';
          message.appendChild(link);
          message.appendChild(document.createTextNode(', then return here and submit again.'));
          message.focus();
        } else { throw new Error('Session check failed'); }
      })
      .catch(function () {
        message.textContent = 'We could not check your session. Your entries are still here. Check your connection and submit again. Nothing was sent from this form.';
        message.focus();
      })
      .finally(function () { clearTimeout(deadline); pending.delete(form); if (!sending.has(form)) progress(''); });
  });
})();
