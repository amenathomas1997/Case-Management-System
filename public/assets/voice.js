// Voice assistant: natural-language control of the whole app. Speech is recognized
// in the browser (with a typed fallback), interpreted by the server's AI intent
// endpoint (no fixed command list), confirmed before any write, and spoken back in
// a human voice (provider TTS, or the best browser voice). A "conversation" mode
// keeps a hands-free session going — it listens again after each reply and survives
// page navigation — so someone can just keep talking to the case-management system.
// Short earcons give non-visual cues (listening, confirm, success, error).
(function () {
  'use strict';
  var fab = document.getElementById('voice-fab');
  if (!fab) return;
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  var btn = fab.querySelector('.vfab-btn'),
      panel = fab.querySelector('.vfab-panel'),
      statusEl = fab.querySelector('.vfab-status'),
      logEl = fab.querySelector('.vfab-log'),
      input = fab.querySelector('.vfab-input'),
      listenBtn = fab.querySelector('.vfab-listen'),
      convoBtn = fab.querySelector('.vfab-convo'),
      muteBtn = fab.querySelector('.vfab-mute'),
      voiceBtn = fab.querySelector('.vfab-voice'),
      volSel = fab.querySelector('.vfab-vol-sel'),
      confirmBox = fab.querySelector('.vfab-confirm'),
      ctext = fab.querySelector('.vfab-ctext'),
      sendBtn = fab.querySelector('.vfab-send'),
      yesBtn = fab.querySelector('.vfab-yes'),
      noBtn = fab.querySelector('.vfab-no');
  var listening = false, rec = null, pending = null, bestVoice = null, convMode = false, speaking = false, yesFocusedAt = 0;
  var activeAudio = null, speechGeneration = 0, replyInProgress = null;
  // Silence whatever is playing without abandoning what the reply was due to do next.
  var currentUtterance = null; // the browser voice's reply; also keeps it from being collected before it ends
  function quiet() {
    if (activeAudio) { activeAudio.pause(); activeAudio = null; }
    currentUtterance = null;
    if (window.speechSynthesis) speechSynthesis.cancel();
  }
  function stopSpeech() {
    speechGeneration++;
    replyInProgress = null;
    quiet();
    speaking = false;
  }
  var PROVIDER_TTS = !!window.__voiceSpeak;
  var CONVO_KEY = 'cms_convo', HIST_KEY = 'cms_convo_hist', VOICE_KEY = 'cms_voice_replies';
  var LISTENING = 'Listening… say what you need.';
  // A reload would lose what is typed in the page's own forms, so (as elsewhere in
  // the app) the page stays open and the sign-in is done in a new tab.
  var SESSION_CHANGED = 'Your session has changed or ended. What you typed on this page is still here. Sign in again in a new tab, then come back to this page and try again.';
  var SIGN_IN = { url: 'login.php', label: 'Sign in again (opens in a new tab)', newTab: true };
  // Spoken replies can be turned off so a screen reader (or braille display)
  // reads each reply from the status line instead of hearing a second voice.
  // With no choice saved yet, Max (deaf-blind) loudness starts with them off,
  // since a braille display can show the status line but not the audio.
  function savedSpoken() {
    try { var v = localStorage.getItem(VOICE_KEY); return v === 'on' ? true : (v === 'off' ? false : null); } catch (e) { return null; }
  }
  var spoken = savedSpoken();
  // Set while Max loudness (not the user) has switched spoken replies off; see volSel.
  var autoMuted = false;
  if (spoken === null) { spoken = !(window.Earcon && Earcon.volume === 'max'); autoMuted = !spoken; }
  function earcon(n) { if (window.Earcon) Earcon.play(n); }

  // The token is read on every request: session-forms.js and refreshToken() (below) refresh it after re-checking the session.
  function headers() { return { 'Content-Type': 'application/json', 'X-CSRF-Token': window.__csrf || '' }; }
  function setStatus(t) { statusEl.textContent = t; }
  // A polite live region outside the panel, in place from page load, for a result
  // that arrives after the panel was closed (see tell).
  var pageLive = null;
  try {
    pageLive = document.createElement('div');
    pageLive.className = 'visually-hidden';
    pageLive.setAttribute('role', 'status');
    fab.appendChild(pageLive);
  } catch (e) { pageLive = null; }
  function announceOutside(t) {
    if (!pageLive) return;
    pageLive.textContent = '';
    setTimeout(function () { pageLive.textContent = t; }, 150);
  }

  // --- Conversation history (persists across navigation) -------------------
  // Stored with a mark of the signed-in user and session, so a later sign-in in
  // this tab (someone else on a shared computer) never sees, resumes or sends it.
  function owner() {
    var s = String(window.__voiceUid || '') + '|' + String(window.__csrf || ''), h = 2166136261;
    for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619) >>> 0; }
    return h.toString(36);
  }
  function history() {
    try {
      var s = JSON.parse(sessionStorage.getItem(HIST_KEY) || 'null');
      return (s && s.o === owner() && Array.isArray(s.h)) ? s.h : [];
    } catch (e) { return []; }
  }
  function pushHistory(role, text) {
    try { var h = history(); h.push({ role: role, text: text }); sessionStorage.setItem(HIST_KEY, JSON.stringify({ o: owner(), h: h.slice(-8) })); } catch (e) {}
  }
  function forgetConversation() {
    try { sessionStorage.removeItem(HIST_KEY); sessionStorage.removeItem(CONVO_KEY); } catch (e) {}
  }
  // A 401, a redirect to the sign-in page, or a plain-text 403 (the refusal of a
  // stale token) means the session changed — not that the network failed, and
  // nothing was done on the server. Any other reply that is not JSON (a host's
  // 502, 503 or 508 page, or an empty body) is a server problem: the session and
  // the conversation stay, and a write may or may not have been saved.
  var SESSION_GONE = new Error('session'), SERVER_TROUBLE = new Error('server');
  // A request that reached the server signed in kept the session alive there:
  // session-timeout.js re-arms its warning, so it never opens over a conversation.
  function activity(r) { if (r && r.ok) { try { document.dispatchEvent(new Event('cms:activity')); } catch (e) {} } }
  // A plain-text 403 refuses the token this page was given, and nothing was done.
  // Every sign-in issues a new token, so after signing in again in a new tab (as
  // SESSION_CHANGED asks) this page's token is stale. When the account signed in
  // now is the one this page belongs to, its current token is taken from ping.php
  // (with the conversation, which is that account's own) and the request is sent
  // once more; otherwise the session has changed. Resolves true when refreshed.
  function refreshToken() {
    return fetch('ping.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (p) {
      var header = function (n) { return p.headers && p.headers.get ? p.headers.get(n) : null; };
      var token = header('X-CSRF-Token');
      if (!p.ok || !token || token === window.__csrf || header('X-User-Id') !== String(window.__voiceUid || '')) return false;
      var kept = history();
      window.__csrf = token;
      try { sessionStorage.setItem(HIST_KEY, JSON.stringify({ o: owner(), h: kept })); } catch (e) {}
      activity(p);
      return true;
    }, function () { return false; });
  }
  // again: sends the same request once more (see refreshToken), or null.
  function readReply(r, again) {
    activity(r);
    return r.text().then(function (body) {
      var d = null;
      try { d = JSON.parse(body); } catch (e) {}
      var json = !!d && typeof d === 'object';
      var toLogin = !!r.redirected && /\/login\.php(?:[?#]|$)/.test(String(r.url || ''));
      if (again && r.status === 403 && !json) {
        return refreshToken().then(function (fresh) {
          if (fresh) return again();
          forgetConversation(); throw SESSION_GONE;
        });
      }
      if (r.status === 401 || toLogin || (r.status === 403 && !json)) { forgetConversation(); throw SESSION_GONE; }
      if (!json) throw SERVER_TROUBLE;
      return d;
    });
  }
  // A request to the voice endpoint; resolves to its JSON reply (see readReply).
  function command(body) {
    var send = function (retry) {
      return fetch('ai_command.php', { method: 'POST', headers: headers(), body: JSON.stringify(body) })
        .then(function (r) { return readReply(r, retry ? function () { return send(false); } : null); });
    };
    return send(true);
  }
  // What to say when a request failed. A changed session also ends a running
  // conversation: every further command would fail until the page is reloaded.
  function failure(err, otherwise) {
    if (err === SERVER_TROUBLE) return 'The server had a problem with that request. Please try again in a moment.';
    if (err !== SESSION_GONE) return otherwise;
    if (convMode) setConvo(false);
    return SESSION_CHANGED;
  }
  // Each entry says who said it (in text, not only by its colour and side), and
  // returns its element so a link that belongs to a reply goes inside it.
  function logMsg(who, text) {
    if (!logEl) return null;
    logEl.hidden = false;
    var d = document.createElement('div');
    d.className = 'vmsg ' + (who === 'u' ? 'u' : 'a');
    var w = document.createElement('span');
    w.className = 'visually-hidden';
    w.textContent = who === 'u' ? 'You: ' : 'Assistant: ';
    var t = document.createElement('span');
    t.textContent = text;
    d.appendChild(w); d.appendChild(t);
    logEl.appendChild(d);
    logEl.scrollTop = logEl.scrollHeight;
    return d;
  }
  // Only ever navigate to a same-origin http(s) path. Server responses are trusted
  // today (whitelisted internal paths), but this guards against a future endpoint
  // change introducing javascript:/data:/external-origin DOM-XSS or open-redirect.
  function safeUrl(u) {
    if (typeof u !== 'string' || !u) return null;
    try {
      var r = new URL(u, window.location.origin);
      if (r.origin !== window.location.origin) return null;
      if (r.protocol !== 'http:' && r.protocol !== 'https:') return null;
      return r.pathname + r.search + r.hash;
    } catch (e) { return null; }
  }
  // A page a reply can be followed up on is offered as a link after the reply's
  // text, inside the same entry, and named for where it goes ("Go to Maria
  // Lopez's notes"). It is only ever followed when the user chooses it.
  function addLink(d, link) {
    if (!d || !link) return;
    var safe = safeUrl(link.url);
    if (!safe) return;
    var sp = document.createElement('span');
    sp.textContent = ' ';
    var a = document.createElement('a');
    a.href = safe;
    a.textContent = (typeof link.label === 'string' && link.label) ? link.label : 'Go to the page for this reply';
    if (link.newTab) { a.target = '_blank'; a.rel = 'noopener'; }
    d.appendChild(sp); d.appendChild(a);
    logEl.scrollTop = logEl.scrollHeight;
  }

  // --- Speech output -------------------------------------------------------
  function pickVoice() {
    var vs = (window.speechSynthesis && speechSynthesis.getVoices()) || [];
    var pref = ['Samantha', 'Google US English', 'Microsoft Aria', 'Microsoft Jenny', 'Siri', 'Ava'];
    for (var i = 0; i < pref.length; i++) {
      var hit = vs.filter(function (v) { return v.name.indexOf(pref[i]) >= 0; })[0];
      if (hit) { bestVoice = hit; return; }
    }
    bestVoice = vs.filter(function (v) { return v.lang && v.lang.indexOf('en') === 0 && !v.localService; })[0]
      || vs.filter(function (v) { return v.lang && v.lang.indexOf('en') === 0; })[0] || vs[0] || null;
  }
  if (window.speechSynthesis) { pickVoice(); speechSynthesis.onvoiceschanged = pickVoice; }

  // If the browser cannot play the reply (or has no voice to play it with), the
  // status line carries it instead, and cb is told so with cb(true).
  // Some voices (Chrome's network voices) stop part-way through a long reply and
  // never report the end. A watchdog, at the reply's length read at 10 characters
  // a second (slower than any voice speaks), then treats it as not played: the
  // status line carries it, and the assistant is never left stuck "speaking".
  function browserSpeak(text, cb) {
    var synth = window.speechSynthesis;
    if (!synth || !(synth.getVoices() || []).length) { setStatus(text); if (cb) cb(true); return; }
    var settled = false, timer = null, u = null;
    var end = function (failed) {
      if (settled) return;
      settled = true;
      if (timer) clearTimeout(timer);
      if (currentUtterance === u) currentUtterance = null;
      if (failed) setStatus(text);
      if (cb) cb(failed);
    };
    try {
      synth.cancel();
      u = currentUtterance = new SpeechSynthesisUtterance(text);
      if (bestVoice) u.voice = bestVoice;
      u.rate = 1.0;
      u.onend = function () { end(false); };
      u.onerror = function (e) {
        // Stopping speech on purpose also reports an error; only a real failure falls back to text.
        end(!e || (e.error !== 'canceled' && e.error !== 'interrupted'));
      };
      synth.speak(u);
      timer = setTimeout(function () {
        if (settled) return;
        // Stopped on purpose, or replaced by a newer reply: nothing more to do for this one.
        if (currentUtterance !== u) { settled = true; return; }
        end(true);
        try { synth.cancel(); } catch (e) {}
      }, 2000 + Math.ceil(text.length / 10) * 1000);
    } catch (e) { end(true); }
  }
  // Roughly how long a screen reader needs to read a short reply before the page changes.
  function readTime(text) { return text ? Math.min(8000, 800 + text.length * 60) : 0; }
  // Give the reply, then run cb once it has finished (so the mic never hears it).
  // One voice at a time: a reply that is played aloud stays out of the status
  // live region, so a screen reader does not read it over the audio. With spoken
  // replies off, or when the audio fails, the status line is the reply and cb
  // gets true: a screen reader is reading it, so the microphone waits for
  // Continue listening, and a page change (leaving) first allows reading time.
  // Leaving is only for a page the user asked to open; a save's result stays
  // here, with its page offered as a link (see addLink).
  function speak(text, cb, leaving, link) {
    addLink(logMsg('a', text), link); pushHistory('assistant', text);
    stopSpeech();
    var generation = speechGeneration, finished = false, reply = { text: text };
    speaking = true;
    // Runs once, even if spoken replies are switched off part-way (see setSpoken).
    reply.finish = function (asText) {
      if (finished) return;
      finished = true;
      if (replyInProgress === reply) replyInProgress = null;
      activeAudio = null;
      var shown = !!asText || !spoken;
      if (shown && leaving) {
        setTimeout(function () { if (generation === speechGeneration) { speaking = false; if (cb) cb(true); } }, readTime(text));
        return;
      }
      speaking = false;
      if (cb) cb(shown);
    };
    replyInProgress = reply;
    var done = function (asText) { if (generation === speechGeneration) reply.finish(asText); };
    if (!spoken) { setStatus(text); done(true); return; }
    setStatus('');
    if (!PROVIDER_TTS) { browserSpeak(text, done); return; }
    fetch('ai_speak.php', { method: 'POST', headers: headers(), body: JSON.stringify({ text: text }) })
      .then(function (r) { if (!r.ok) throw 0; activity(r); return r.blob(); })
      .then(function (b) {
        if (generation !== speechGeneration || finished) return;
        var url = URL.createObjectURL(b);
        var a = activeAudio = new Audio(url);
        a.addEventListener('ended', function () { URL.revokeObjectURL(url); }, {once:true});
        a.addEventListener('pause', function () { URL.revokeObjectURL(url); }, {once:true});
        a.onended = function () { done(false); };
        a.onerror = function () { URL.revokeObjectURL(url); if (generation === speechGeneration && !finished) browserSpeak(text, done); };
        // play() rejects (not onerror) when autoplay is blocked — e.g. a
        // conversation auto-resuming after navigation with no fresh gesture.
        // Catch it so the hands-free loop never stalls with speaking=true.
        var p = a.play();
        if (p && p.catch) p.catch(function () { URL.revokeObjectURL(url); if (generation === speechGeneration && !finished) browserSpeak(text, done); });
      })
      .catch(function () { if (generation === speechGeneration && !finished) browserSpeak(text, done); });
  }
  // After a reply, listen again if we're in a hands-free conversation — unless
  // the reply is on screen (shown), where a screen reader is still reading it:
  // then the microphone waits for Continue listening (or Alt+L), not a timer.
  function afterReply(shown) { if (convMode && !panel.hidden && confirmBox.hidden && shown !== true) startListen(); }
  // After a confirmation question: in a hands-free conversation, with the question
  // played aloud, the microphone opens for the yes or no. Otherwise focus moves to
  // "Yes" so keyboard and screen-reader users land on the choice instead of hunting
  // for it (the choice is a group labelled by the question, so with spoken replies
  // off, moving focus there is what reads it). Never both: a screen reader
  // announcing "Yes, confirm" into an open microphone would answer for the user.
  // Focus only moves while the user is still waiting where they asked (an empty
  // request box, Send, the microphone button or nowhere): someone already typing
  // an answer, or reading the page, keeps their place and the status line says
  // what is waiting. A keystroke already on its way is not taken as a yes (see yesBtn).
  function afterQuestion(shown) {
    if (!pending || confirmBox.hidden || panel.hidden) return;
    if (convMode && shown !== true) { startListen(); return; }
    var here = document.activeElement;
    if (here && (here === yesBtn || here === noBtn)) return;
    var waiting = !here || here === document.body || here === sendBtn || here === listenBtn || (here === input && !input.value.trim());
    if (waiting && yesBtn) { yesBtn.focus(); yesFocusedAt = Date.now(); return; }
    setStatus('Waiting for your answer: ' + ctext.textContent + ' Use the Yes, confirm or No button, or type yes or no.');
  }

  // --- Command handling ----------------------------------------------------
  // A spoken or typed answer to "Say yes to save", read as the app reads it: whole
  // words only, negatives first (any "no"-word cancels: "no, don't save it"), and
  // a yes only counts in a short answer (four words or fewer). Anything else is unclear.
  function confirmAnswer(text) {
    var tokens = String(text).toLowerCase().replace(/’/g, "'").split(/[^a-z0-9']+/).filter(Boolean);
    var words = tokens.map(function (t) { return t.replace(/'/g, ''); }).filter(Boolean);
    if (!words.length) return 'unclear';
    var negatives = ['no', 'nope', 'not', 'dont', 'cancel', 'stop', 'never', 'nevermind', 'wrong', 'incorrect'];
    if (tokens.some(function (t) { return /n't$/.test(t); }) || words.some(function (w) { return negatives.indexOf(w) >= 0; })) return 'no';
    if (words.length > 4) return 'unclear';
    // A hesitation or a question ("okay, wait", "sure — which one?") is not a yes (the same list as the app).
    // A contracted question word counts too: "what's" (its part before the
    // apostrophe), or "whats" typed without one.
    var hedges = ['wait', 'hold', 'hang', 'which', 'what', 'who', 'when', 'where', 'why', 'how', 'but', 'actually', 'hmm', 'um',
      'whats', 'whos', 'whens', 'wheres', 'whys', 'hows'];
    if (tokens.some(function (t) { return hedges.indexOf(t.replace(/'/g, '')) >= 0 || hedges.indexOf(t.split("'")[0]) >= 0; })) return 'unclear';
    var yes = ['yes', 'yeah', 'yep', 'ok', 'okay', 'sure', 'confirm', 'correct'];
    if (words.some(function (w) { return yes.indexOf(w) >= 0; })) return 'yes';
    for (var i = 0; i + 1 < words.length; i++) {
      if (['go ahead', 'do it', 'save it'].indexOf(words[i] + ' ' + words[i + 1]) >= 0) return 'yes';
    }
    return 'unclear';
  }
  // While a confirmation waits, what is said or typed answers it (the same
  // confirmed write the Yes button sends), and is never sent to the AI as a new
  // request. An unclear answer is asked once more, then nothing is saved.
  function answerConfirm(text) {
    logMsg('u', text); pushHistory('user', text);
    var a = confirmAnswer(text);
    if (a !== 'unclear') { resolveConfirm(a === 'yes'); return; }
    if (!pending.askedAgain) { pending.askedAgain = true; earcon('confirm'); speak('Please say yes to confirm, or no to cancel.', afterQuestion); return; }
    resolveConfirm(false, "I still couldn't tell if that was a yes or a no, so nothing was saved.");
  }
  function handle(transcript) {
    transcript = (transcript || '').trim();
    var awaiting = !!pending && !confirmBox.hidden;
    if (!transcript) { if (awaiting) afterQuestion(); else afterReply(); return; }
    if (awaiting) { answerConfirm(transcript); return; }
    // The request is echoed in the status line only when replies are not spoken.
    // Otherwise a short "Working on it…" is put there (the microphone is off
    // now), so a screen reader has finished it before the spoken reply starts.
    setStatus(spoken ? 'Working on it…' : '“' + transcript + '”');
    // The history sent is what came before: the request itself goes once, as the transcript.
    var earlier = history();
    logMsg('u', transcript); pushHistory('user', transcript);
    command({ transcript: transcript, history: earlier, context: { page: location.pathname } })
      .then(function (d) {
        if (panel.hidden) return;
        if (!d.ok) { earcon('error'); speak(d.error || 'Sorry, something went wrong.', afterReply); return; }
        if (d.action === 'navigate' && d.url) {
          earcon('nav'); speak(d.speak || '', function () { var t = safeUrl(d.url); if (t) location.href = t; }, true);
        } else if (d.action === 'confirm') {
          // A one-shot idempotency token so a double-tap / retry of the write
          // can't duplicate it server-side (used where the table supports it).
          var idem = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2);
          pending = { do: d.do, params: d.params || {}, idem: idem };
          ctext.textContent = d.speak; confirmBox.hidden = false; earcon('confirm');
          // Ask the question, then wait for the answer (see afterQuestion).
          if (spoken) speak(d.speak, afterQuestion);
          else { logMsg('a', d.speak); pushHistory('assistant', d.speak); stopSpeech(); afterQuestion(true); }
        } else {
          // Hands-free answer: speak it. If a page can also show it (or is where a
          // request voice could not finish can be done), the reply offers a named
          // link to it (we never auto-navigate for an answer).
          speak(d.speak || 'Okay.', afterReply, false, d.show_url ? { url: d.show_url, label: d.show_label } : null);
        }
      })
      .catch(function (err) { if (!panel.hidden) { earcon('error'); speak(failure(err, "I couldn't reach the server."), afterReply, false, err === SESSION_GONE ? SIGN_IN : null); } });
  }
  // The outcome of a confirmation is always given, even once the panel is closed
  // (the user may have closed it while a save was on its way): then it goes in the
  // conversation history and the status line for when the panel is reopened, and
  // is announced once from a live region outside the panel.
  function tell(text, link) {
    if (!panel.hidden) { speak(text, afterReply, false, link); return; }
    addLink(logMsg('a', text), link); pushHistory('assistant', text);
    setStatus(text);
    announceOutside('Voice assistant: ' + text);
  }
  function resolveConfirm(yes, cancelled) {
    // Yes and No are about to be hidden: move focus first so it is not lost to the page.
    if (!panel.hidden) input.focus();
    confirmBox.hidden = true;
    if (!yes || !pending) { pending = null; tell(cancelled || 'Okay, cancelled.'); return; }
    var body = pending.params || {}; body.do = pending.do; body.idem = pending.idem; pending = null;
    command(body)
      .then(function (d) {
        earcon(d.ok ? 'success' : 'error');
        // The user asked to save, not to leave: the page (and anything typed in
        // it) stays, the result stays in the history, and the saved record's page
        // is offered as a link to follow when they choose.
        tell(d.ok ? (d.speak || 'Done.') : (d.error || 'Could not save.'), d.ok && d.url ? { url: d.url, label: d.url_label } : null);
      })
      .catch(function (err) {
        earcon('error');
        tell(err === SESSION_GONE ? 'That was not saved. ' + failure(err)
          : err === SERVER_TROUBLE ? 'The server had a problem, so it may or may not have been saved. Check before trying again.'
          : 'That did not go through.', err === SESSION_GONE ? SIGN_IN : null);
      });
  }

  // --- Recognition ---------------------------------------------------------
  // Nothing is put in the status line (a live region) while the microphone is
  // open: a screen reader on speakers would be heard and sent as a request. The
  // listening sound marks the start, and this note (seen, not announced) shows
  // it. With sound cues turned off, "Listening…" is announced first and the
  // microphone opens once it has been read.
  var STILL_HERE = 'Still here — say something when ready.', listenTimer = null, listenNote = null;
  // Recognition errors in plain words, with what to do next.
  var BLOCKED = "The browser is blocking the microphone. Allow the microphone in this site's settings (usually the icon beside the web address), or type your request below.";
  var MIC_ERRORS = {
    'not-allowed': BLOCKED,
    'service-not-allowed': BLOCKED,
    'audio-capture': 'No microphone was found. Check that one is connected and turned on, or type your request below.',
    'network': 'The speech service could not be reached. Type your request below, or try the microphone again later.',
    'no-speech': 'I did not hear anything. Choose Use microphone to try again, or type your request below.',
    'aborted': 'The microphone stopped. Choose Use microphone to try again, or type your request below.',
    'language-not-supported': 'This browser cannot recognize English speech. Type your request below.'
  };
  try {
    listenNote = document.createElement('p');
    listenNote.className = 'vfab-listening';
    listenNote.setAttribute('aria-hidden', 'true');
    listenNote.hidden = true;
    listenNote.textContent = LISTENING;
    statusEl.parentNode.insertBefore(listenNote, statusEl.nextSibling);
  } catch (e) { listenNote = null; }
  function showListening(on) { if (on) btn.classList.add('listening'); else btn.classList.remove('listening'); if (listenNote) listenNote.hidden = !on; }
  // The inactivity warning (session-timeout.js) is a modal dialog that a screen
  // reader reads aloud. The microphone is never open, and never reopens, while it
  // is shown, or that reading would be heard and sent as a request. Once it closes,
  // the status line says the microphone was turned off; it is opened again only on
  // request (Continue listening or Alt+L).
  var timeoutWarn = document.getElementById('timeout-warn'), micPaused = false;
  function warningOpen() { return !!timeoutWarn && timeoutWarn.hidden === false; }
  if (timeoutWarn && window.MutationObserver) {
    try {
      new window.MutationObserver(function () {
        if (warningOpen()) {
          if (listening || listenTimer) { stopListen(true); micPaused = true; }
        } else if (micPaused) {
          micPaused = false;
          if (!panel.hidden) setStatus('The microphone was turned off while the inactivity warning was open. Choose ' + listenBtn.textContent + ' to go on.');
        }
      }).observe(timeoutWarn, { attributes: true, attributeFilter: ['hidden'] });
    } catch (e) {}
  }
  function startListen() {
    if (speaking) return; // automatic re-listening never captures our own voice (see listenNow)
    if (warningOpen()) { micPaused = true; return; }
    if (!SR) { setStatus('Voice input is not supported in this browser — type your request below.'); input.focus(); return; }
    if (listening || listenTimer) return;
    if (window.Earcon && Earcon.muted) {
      setStatus(LISTENING);
      listenTimer = setTimeout(function () { listenTimer = null; if (!panel.hidden && !speaking && !listening && !warningOpen()) openMic(); }, readTime(LISTENING));
      return;
    }
    openMic();
  }
  function openMic() {
    try {
      var r = rec = new SR(), heard = false, failed = false;
      r.lang = 'en-US'; r.interimResults = false; r.maxAlternatives = 1;
      r.onstart = function () { listening = true; showListening(true); earcon('listen'); };
      r.onerror = function (e) {
        failed = true; listening = false; showListening(false);
        if (rec !== r) return; // stopped on purpose
        // Restarted only once the screen reader has read this, so the microphone does not hear it.
        if (e.error === 'no-speech' && convMode) {
          // A waiting confirmation is asked once more; a second silence saves nothing.
          if (pending && !confirmBox.hidden) {
            if (pending.silentOnce) { resolveConfirm(false, 'I did not hear an answer, so nothing was saved.'); return; }
            pending.silentOnce = true; earcon('confirm'); speak('Please say yes to confirm, or no to cancel.', afterQuestion); return;
          }
          setStatus(STILL_HERE); setTimeout(afterReply, readTime(STILL_HERE)); return;
        }
        setStatus(MIC_ERRORS[e.error] || 'The microphone could not be used. Type your request below, or choose Use microphone to try again.'); input.focus();
      };
      // Recognition can end with no result and no error: say the microphone is off.
      r.onend = function () { listening = false; showListening(false); if (rec === r && !heard && !failed) setStatus('The microphone is off.'); };
      r.onresult = function (e) { heard = true; handle(e.results[0][0].transcript); };
      r.start();
    } catch (e) { setStatus('Could not start the microphone — type below.'); input.focus(); }
  }
  // discard: drop whatever was heard (abort) rather than let it end as a request (stop).
  function stopListen(discard) {
    if (listenTimer) { clearTimeout(listenTimer); listenTimer = null; }
    var r = rec; rec = null;
    if (r) {
      if (discard) r.onresult = null;
      try { if (discard && r.abort) r.abort(); else r.stop(); } catch (e) {}
    }
    listening = false; showListening(false);
  }
  // The microphone button and Alt+L are asked for on purpose: they stop a reply
  // that is still playing (or whose end never arrived) and listen.
  function listenNow() { if (speaking) stopSpeech(); startListen(); }

  // --- Conversation mode (persistent across pages) -------------------------
  function setConvo(on) {
    convMode = !!on;
    convoBtn.classList.toggle('on', convMode);
    convoBtn.textContent = convMode ? 'End conversation' : 'Start conversation';
    syncListen();
    try { on ? sessionStorage.setItem(CONVO_KEY, '1') : sessionStorage.removeItem(CONVO_KEY); } catch (e) {}
    if (convMode) { syncMute(); panel.hidden = false; setExpanded(true); if (logEl) logEl.hidden = false; startListen(); }
    else { stopListen(); stopSpeech(); setStatus('Conversation ended.'); }
  }

  // --- Mute and spoken-reply toggles -----------------------------------------
  // Each button's label names its current state ("…: on" / "…: off"), so it is
  // not also given aria-pressed, which would read as a second, conflicting state.
  function syncMute() {
    var m = window.Earcon ? Earcon.muted : false;
    muteBtn.textContent = 'Sound cues: ' + (m ? 'off' : 'on');
  }
  function syncVoice() { if (voiceBtn) voiceBtn.textContent = 'Spoken replies: ' + (spoken ? 'on' : 'off'); }
  // In a conversation the microphone button continues it; Alt+L does the same anywhere in the panel.
  function syncListen() { listenBtn.textContent = (convMode ? 'Continue listening' : 'Use microphone') + ' (Alt+L)'; }
  // Switching spoken replies off part-way through a reply moves that reply to
  // the status line and carries on from there (listen, leave the page or move
  // to Yes), instead of cutting it off along with what was due to follow it.
  function setSpoken(on, save) {
    spoken = on;
    if (save) { try { localStorage.setItem(VOICE_KEY, on ? 'on' : 'off'); } catch (e) {} }
    syncVoice();
    if (on) { setStatus('Spoken replies on.'); return; }
    var r = replyInProgress;
    quiet();
    if (r) { setStatus('Spoken replies off. ' + r.text); r.finish(true); }
    else setStatus('Spoken replies off. Replies will appear here as text.');
  }

  // --- Wiring --------------------------------------------------------------
  function setExpanded(open) { btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  // A confirmation left unanswered is cancelled, never left armed for a later
  // "okay" or "sure" to save it; the user is told that nothing was saved.
  function closePanel() {
    setConvo(false);
    panel.hidden = true;
    setExpanded(false);
    btn.focus();
    if (pending) resolveConfirm(false, 'The request that was waiting for your answer was cancelled when the voice assistant closed, so nothing was saved.');
  }
  btn.addEventListener('click', function () {
    if (!panel.hidden) { closePanel(); return; }
    // Sound cues may have been changed from the low-vision bar since the panel was last open.
    syncMute();
    panel.hidden = false; setExpanded(true); input.focus();
  });
  fab.querySelector('.vfab-close').addEventListener('click', closePanel);
  listenBtn.addEventListener('click', listenNow);
  listenBtn.setAttribute('aria-keyshortcuts', 'Alt+L');
  syncListen();
  // One message, set last: ending a conversation is said along with the stop, not overwritten.
  fab.querySelector('.vfab-stop').addEventListener('click', function () {
    var wasConvo = convMode;
    setConvo(false); stopSpeech();
    setStatus(wasConvo ? 'Speech stopped and the conversation ended. The reply remains in the conversation history. Choose Start conversation to continue.'
      : 'Speech stopped. The reply remains in the conversation history.');
  });
  panel.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') { event.preventDefault(); closePanel(); return; }
    if (event.altKey && !event.ctrlKey && !event.metaKey && (event.code === 'KeyL' || event.key === 'l' || event.key === 'L')) {
      event.preventDefault(); listenNow();
    }
  });
  function sendTyped() { var t = input.value.trim(); if (!t) { input.focus(); return; } input.value = ''; handle(t); }
  input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && input.value.trim()) { e.preventDefault(); sendTyped(); } });
  sendBtn.addEventListener('click', sendTyped);
  // A Space or Enter already on its way when focus arrived on Yes is not a yes.
  yesBtn.addEventListener('click', function () { if (Date.now() - yesFocusedAt < 500) return; resolveConfirm(true); });
  noBtn.addEventListener('click', function () { resolveConfirm(false); });
  convoBtn.addEventListener('click', function () { setConvo(!convMode); });
  // Said in the status line too (as Spoken replies is), since the button's new name is often not
  // announced; not while the microphone is open, where it would be heard as a request.
  muteBtn.addEventListener('click', function () {
    if (window.Earcon) { Earcon.toggle(); if (!Earcon.muted) Earcon.play('confirm'); }
    syncMute();
    if (window.Earcon && !listening) setStatus(Earcon.muted ? 'Sound cues off.' : 'Sound cues on.');
  });
  syncMute();
  if (voiceBtn) voiceBtn.addEventListener('click', function () { autoMuted = false; setSpoken(!spoken, true); });
  syncVoice();
  if (volSel) {
    if (window.Earcon) volSel.value = Earcon.volume;
    volSel.addEventListener('change', function () {
      if (window.Earcon) { Earcon.setVolume(volSel.value); Earcon.play('notify'); }
      // Max (deaf-blind) with no choice saved: replies switch to text a braille display can show.
      // Passing Max on the way to another loudness (arrow keys change it at once) turns them back on.
      if (volSel.value === 'max') {
        if (spoken && savedSpoken() === null) { setSpoken(false, false); autoMuted = true; }
      } else if (autoMuted) {
        autoMuted = false;
        if (!spoken && savedSpoken() === null) setSpoken(true, false);
      }
    });
  }

  // Resume a conversation that was running before the page navigated — only
  // this user's, from this session; anything else left in the tab is discarded.
  try {
    var prior = history();
    if (!prior.length) forgetConversation();
    else if (sessionStorage.getItem(CONVO_KEY) === '1') {
      prior.forEach(function (h) { logMsg(h.role === 'user' ? 'u' : 'a', String(h.text || '')); });
      setStatus('Your previous conversation is available. Choose Start conversation to resume.');
    }
  } catch (e) {}
  // Signing out, or the session ending (session-timeout.js), forgets the conversation in this tab.
  if (document.addEventListener) {
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (form && /(?:^|\/)logout\.php(?:\?|$)/.test(String(form.action || ''))) forgetConversation();
    }, true);
    document.addEventListener('cms:session-ended', forgetConversation);
  }
})();
