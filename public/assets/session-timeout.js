// Local dismissal always works, including while offline or a request is stalled.
(function () {
  var dialog = document.getElementById('timeout-warn');
  if (!dialog) return;
  var stayButton = document.getElementById('tw-stay');
  var dismissButton = document.getElementById('tw-dismiss');
  var error = document.getElementById('tw-error');
  // An assertive live region that is already inside the open dialog. A message
  // is shown by moving it in here, which is what makes screen readers speak it.
  var live = document.getElementById('tw-live');
  function announce(message) {
    message.hidden = false;
    if (live) live.appendChild(message);
  }
  // Short configured sessions need activity keepalives before their warning.
  var activityInterval = Math.max(1000, Math.min(60000, Number(dialog.dataset.warnMs) / 2));
  var timer, lastFocus = null, lastPing = Date.now(), pending = false;
  function arm() {
    clearTimeout(timer);
    timer = setTimeout(show, Number(dialog.dataset.warnMs));
  }
  function show() {
    if (!dialog.hidden) return;
    // A warning is armed only at page load or after a successful keepalive, so an
    // earlier failure or sign-out notice no longer applies: each one opens fresh.
    error.hidden = true;
    var expired = document.getElementById('tw-expired');
    if (expired && !expired.hidden) {
      expired.hidden = true;
      document.getElementById('tw-desc').hidden = false;
      stayButton.hidden = false;
      dialog.setAttribute('aria-describedby', 'tw-desc');
    }
    lastFocus = document.activeElement;
    dialog.hidden = false;
    (stayButton.hidden ? dismissButton : stayButton).focus();
  }
  function close() {
    dialog.hidden = true;
    // A screen-reader user reading in browse mode often has nothing focused (the
    // body); focusing that does nothing and loses the reading place, so return
    // them to the main content instead.
    var back = lastFocus && lastFocus.isConnected && lastFocus !== document.body && lastFocus !== document.documentElement
      ? lastFocus : document.getElementById('main');
    if (back) back.focus();
  }
  // Activity keepalive: re-arms the warning once the server confirms the session.
  function keepalive() {
    lastPing = Date.now();
    fetch('ping.php', {credentials: 'same-origin'})
      .then(function (response) { if (response.ok) arm(); }).catch(function () {});
  }
  // "Return to my work" and Escape close at once (never waiting on the network)
  // and count as activity, so the session is extended and a later warning is set.
  function dismiss() {
    close();
    keepalive();
  }
  function stay() {
    if (pending) return;
    pending = true;
    error.hidden = true;
    var controller = new AbortController();
    var deadline = setTimeout(function () { controller.abort(); }, 10000);
    fetch('ping.php', {credentials: 'same-origin', signal: controller.signal})
      .then(function (response) {
        if (response.ok) {
          if (!dialog.hidden) close();
          lastPing = Date.now();
          arm();
        } else if (response.status === 401) {
          // Tells voice.js to forget this session's conversation.
          if (document.dispatchEvent) { try { document.dispatchEvent(new Event('cms:session-ended')); } catch (e) {} }
          document.getElementById('tw-desc').hidden = true;
          stayButton.hidden = true;
          var expired = document.getElementById('tw-expired');
          announce(expired);
          dialog.setAttribute('aria-describedby', 'tw-expired');
          if (!dialog.hidden) expired.querySelector('a').focus();
        } else {
          throw new Error('Keepalive failed');
        }
      })
      .catch(function () { announce(error); })
      .finally(function () { clearTimeout(deadline); pending = false; });
  }
  stayButton.addEventListener('click', stay);
  dismissButton.addEventListener('click', dismiss);
  document.addEventListener('keydown', function (event) {
    if (dialog.hidden) return;
    if (event.key === 'Escape') { event.preventDefault(); dismiss(); return; }
    if (event.key !== 'Tab') return;
    var controls = Array.from(dialog.querySelectorAll('button, a[href]')).filter(function (el) {
      return !el.disabled && !el.closest('[hidden]');
    });
    var first = controls[0], last = controls[controls.length - 1];
    if (event.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement))) {
      event.preventDefault(); last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !dialog.contains(document.activeElement))) {
      event.preventDefault(); first.focus();
    }
  });
  // Keep keyboard focus inside an open modal even after pointer interaction.
  document.addEventListener('focusin', function (event) {
    if (!dialog.hidden && !dialog.contains(event.target)) dismissButton.focus();
  });
  ['input', 'keydown', 'pointerdown', 'focusin'].forEach(function (name) {
    document.addEventListener(name, function () {
      if (!dialog.hidden || Date.now() - lastPing < activityInterval) return;
      keepalive();
    }, true);
  });
  // A request that already reached the server signed in (the voice assistant, an
  // AI draft) kept the session alive there: re-arm the warning without a ping.
  document.addEventListener('cms:activity', function () {
    if (!dialog.hidden) return;
    lastPing = Date.now();
    arm();
  });
  arm();
})();
