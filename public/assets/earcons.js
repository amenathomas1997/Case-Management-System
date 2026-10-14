// Earcons — short synthesized audio cues for non-visual feedback across the CMS
// and the voice assistant. No audio files: tones are generated with the Web Audio
// API on demand. Volume is adjustable (Normal / Loud / Max) because deaf-blind and
// hard-of-hearing users need much louder, lower-pitched, longer cues; Max mode also
// drops the pitch and lengthens the tones for residual low-frequency hearing, and
// every cue fires a matching haptic vibration on devices that support it.
// Public API: Earcon.play(name), Earcon.muted (get/set), Earcon.toggle(),
// Earcon.volume (get/set 'normal'|'loud'|'max'), Earcon.setVolume(v).
(function () {
  'use strict';
  var KEY = 'cms_earcons', VKEY = 'cms_earcon_vol', ctx = null, muted = false, vol = 'normal';
  try { muted = localStorage.getItem(KEY) === 'off'; } catch (e) {}
  try { var v = localStorage.getItem(VKEY); if (v === 'loud' || v === 'max') vol = v; } catch (e) {}

  // Peak-gain multiplier per volume level. Capped below hard-clipping per tone.
  var VOLF = { normal: 1, loud: 2.4, max: 4.0 };
  function factor() { return VOLF[vol] || 1; }

  function ac() {
    if (!ctx) { var C = window.AudioContext || window.webkitAudioContext; if (!C) return null; try { ctx = new C(); } catch (e) { return null; } }
    if (ctx.state === 'suspended') { try { ctx.resume(); } catch (e) {} }
    return ctx;
  }
  // One shaped tone: frequency, start offset (s), duration (s), peak gain, wave.
  function tone(a, f, t0, dur, peak, type) {
    // Max (deaf-blind) mode: drop an octave and lengthen so low-frequency
    // residual hearing can catch it; louder levels also stretch slightly.
    if (vol === 'max') { f = f * 0.5; dur = dur * 1.6; }
    else if (vol === 'loud') { dur = dur * 1.2; }
    var o = a.createOscillator(), g = a.createGain(), t = a.currentTime + t0;
    o.type = type || 'sine'; o.frequency.value = f;
    var pk = Math.min(0.92, peak * factor());
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(pk, t + 0.012);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(g); g.connect(a.destination);
    o.start(t); o.stop(t + dur + 0.03);
  }
  // Each earcon is a short sequence of [freq, startOffset, duration, gain, wave?].
  var E = {
    listen:  [[660, 0, 0.09, 0.14], [990, 0.09, 0.11, 0.14]],                 // rising — ready / listening
    stop:    [[880, 0, 0.08, 0.12], [560, 0.08, 0.11, 0.12]],                 // falling — stopped
    success: [[784, 0, 0.09, 0.14], [1047, 0.10, 0.16, 0.14]],               // up third — saved / done
    error:   [[320, 0, 0.16, 0.18, 'square'], [240, 0.16, 0.22, 0.18, 'square']], // low descend — problem
    confirm: [[720, 0, 0.11, 0.13], [720, 0.18, 0.13, 0.13]],                 // double mid — needs a decision
    nav:     [[1000, 0, 0.07, 0.10]],                                         // soft blip — navigating
    notify:  [[1175, 0, 0.11, 0.12], [1568, 0.13, 0.17, 0.12]],              // bell up — notification
    message: [[880, 0, 0.09, 0.12], [1175, 0.11, 0.13, 0.12]]                 // two-note — new message
  };
  // Haptic pattern per earcon (ms on/off). Tactile backup for deaf-blind users.
  var VIB = {
    listen: [40], stop: [40], success: [60, 40, 60], error: [180, 80, 180],
    confirm: [50, 60, 50], nav: [25], notify: [40, 40, 90], message: [40, 40, 40]
  };
  function play(name) {
    if (muted) return;
    var seq = E[name]; if (!seq) return;
    // Haptics fire even if audio can't (no gesture yet) — a cue either way.
    try { if (navigator.vibrate) navigator.vibrate(VIB[name] || [40]); } catch (e) {}
    var a = ac(); if (!a) return;
    try { seq.forEach(function (s) { tone(a, s[0], s[1], s[2], s[3], s[4]); }); } catch (e) {}
  }
  window.Earcon = {
    play: play,
    names: function () { var k = []; for (var n in E) if (E.hasOwnProperty(n)) k.push(n); return k; },
    get muted() { return muted; },
    set muted(val) { muted = !!val; try { localStorage.setItem(KEY, val ? 'off' : 'on'); } catch (e) {} syncToggle(); },
    toggle: function () { this.muted = !muted; return !muted; },
    get volume() { return vol; },
    set volume(val) { vol = (val === 'loud' || val === 'max') ? val : 'normal'; try { localStorage.setItem(VKEY, vol); } catch (e) {} },
    setVolume: function (val) { this.volume = val; return vol; }
  };
  // "Sound cues" in the low-vision controls (layout.php, signed-in pages): every
  // user who hears earcons can turn them off, not only users of the voice panel.
  // Its pressed state follows Earcon.muted however it changes (the voice panel too).
  function syncToggle() {
    var btn = document.getElementById && document.getElementById('earcon-toggle');
    if (btn) btn.setAttribute('aria-pressed', String(!muted));
    // The voice panel's own button says the same thing (voice.js keeps it in step when the
    // toggle comes from the panel; this covers the low-vision bar).
    var fab = document.querySelector && document.querySelector('.vfab-mute');
    if (fab) fab.textContent = 'Sound cues: ' + (muted ? 'off' : 'on');
  }
  function wireToggle() {
    var btn = document.getElementById('earcon-toggle');
    if (!btn) return;
    var live = document.getElementById('vision-live');
    syncToggle();
    btn.hidden = false;
    btn.addEventListener('click', function () {
      window.Earcon.toggle();
      if (live) live.textContent = muted ? 'Sound cues off.' : 'Sound cues on.';
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wireToggle);
  else wireToggle();
})();
