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
  // The configured session length (layout.php). Once it passes with no activity the
  // server has seen, the session has ended there; this is known from the clock
  // alone, because asking ping.php would extend the session.
  var timeoutMs = Number(dialog.dataset.timeoutMs) || 0;
  var timer, endTimer, lastFocus = null, lastPing = Date.now(), pending = false, ended = false;
  // Every tab of this site shares one session. Each records when the server last
  // saw it in use, so a tab left idle does not say "signed out" while another kept
  // the session alive. Storage can be unavailable; the clock in this tab is then used.
  function shared(at) {
    try {
      if (at) localStorage.setItem('cms-session-active', String(at));
      return Number(localStorage.getItem('cms-session-active')) || 0;
    } catch (e) { return 0; }
  }
  function armEnd(from) {
    if (!(timeoutMs > 0)) return;
    clearTimeout(endTimer);
    // A few seconds' grace, so the notice never comes before the server has ended it
    // (and never past the longest delay a timer accepts, which would fire at once).
    endTimer = setTimeout(ending, Math.min(2147483647, Math.max(0, from + timeoutMs + 5000 - Date.now())));
  }
  function ending() {
    var seen = shared();
    if (seen && Date.now() - seen < timeoutMs) { armEnd(seen); return; }
    expire();
  }
  function arm() {
    ended = false;
    clearTimeout(timer);
    timer = setTimeout(show, Number(dialog.dataset.warnMs));
    shared(Date.now());
    armEnd(Date.now());
  }
  // The dialog's title while it asks, and while it says the session has ended (4.30.0).
  var title = document.getElementById('tw-title'), askTitle = title ? title.textContent : '';
  var lead = document.getElementById('tw-expired-lead');
  function show() {
    if (!dialog.hidden) return;
    if (title) title.textContent = askTitle;
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
  // The session has ended (the server refused a keepalive, or the configured time
  // passed): the dialog switches to its signed-out message and title, and opens if it
  // was closed, so nobody keeps typing believing they are still signed in. The message
  // is said once (4.30.0), by one channel: a dialog that opens now is read by its title
  // ("You were signed out") and description as focus moves in, so the notice is not
  // also put in the alert region; in a dialog that was already open, focus moving
  // within it reads neither, so the notice goes to the alert region instead. Once
  // said, it is not repeated until the session is confirmed again, so the dialog can
  // still be closed to copy unsaved text.
  function expire() {
    if (ended) return;
    ended = true;
    clearTimeout(timer); clearTimeout(endTimer);
    // Tells voice.js to forget this session's conversation.
    if (document.dispatchEvent) { try { document.dispatchEvent(new Event('cms:session-ended')); } catch (e) {} }
    error.hidden = true;
    document.getElementById('tw-desc').hidden = true;
    stayButton.hidden = true;
    var expired = document.getElementById('tw-expired');
    dialog.setAttribute('aria-describedby', 'tw-expired');
    if (title) title.textContent = dialog.dataset.expiredTitle || 'You were signed out';
    var opening = dialog.hidden;
    if (opening) { lastFocus = document.activeElement; dialog.hidden = false; }
    // The title says "You were signed out" already; the notice's own first sentence is
    // kept only where the notice is announced without the title.
    if (lead) lead.hidden = opening;
    if (opening) {
      // Out of the alert region if an earlier notice left it there, so showing it is not an alert.
      if (live && expired.parentNode === live && live.parentNode) live.parentNode.insertBefore(expired, live.nextSibling);
      expired.hidden = false;
    } else announce(expired);
    expired.querySelector('a').focus();
  }
  // Activity keepalive: re-arms the warning once the server confirms the session,
  // and says at once when the server reports it has already ended.
  function keepalive() {
    lastPing = Date.now();
    fetch('ping.php', {credentials: 'same-origin'})
      .then(function (response) {
        if (response.ok) arm();
        else if (response.status === 401) expire();
      }).catch(function () {});
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
          expire();
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
