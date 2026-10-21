// Apply preferences before paint; wire native controls after the document loads.
(function () {
  'use strict';
  var root = document.documentElement, key = 'cms_low_vision';
  var defaults = {palette:'auto', size:'100', spacing:false, width:false, links:false, motion:false, lists:false};
  var palettes = ['auto','light','dark','black','white','yellow','cream'];
  var sizes = ['100','125','150','175','200'];
  var prefs = Object.assign({}, defaults);
  // Preferences an earlier version saved under another prefix ("<prefix>_low_vision", and before
  // that "<prefix>_theme" / "<prefix>_textsize") are picked up once, so nobody loses their display.
  function earlier(suffix) {
    for (var i = 0; i < localStorage.length; i++) {
      var k = localStorage.key(i);
      if (k && k !== key && k.slice(-suffix.length - 1) === '_' + suffix) return localStorage.getItem(k);
    }
    return null;
  }
  try {
    var stored = JSON.parse(localStorage.getItem(key) || earlier('low_vision') || 'null');
    if (stored && typeof stored === 'object') {
      if (palettes.includes(stored.palette)) prefs.palette = stored.palette;
      if (sizes.includes(String(stored.size))) prefs.size = String(stored.size);
      ['spacing','width','links','motion','lists'].forEach(function (k) { prefs[k] = stored[k] === true; });
    } else {
      var theme = earlier('theme');
      if (theme === 'light' || theme === 'dark') prefs.palette = theme;
      if (earlier('textsize') === 'large') prefs.size = '125';
    }
  } catch (e) {}
  var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  function apply() {
    root.setAttribute('data-theme', ['dark','black','yellow'].includes(prefs.palette) || (prefs.palette === 'auto' && mq && mq.matches) ? 'dark' : 'light');
    root.setAttribute('data-vision-palette', prefs.palette);
    root.setAttribute('data-vision-size', prefs.size);
    ['spacing','width','links','motion','lists'].forEach(function (k) { root.setAttribute('data-vision-' + k, String(prefs[k])); });
    fit();
  }
  // Media queries measure the viewport at the browser's own text size, not this
  // one, so mark the page narrow when the enlarged layout would not fit (760px and
  // 480px at 100%): styles.css then uses the narrow-screen layouts, as zoom would.
  function fit() {
    var scale = Number(prefs.size) / 100, w = window.innerWidth || root.clientWidth || 0;
    root.setAttribute('data-vision-narrow', String(scale > 1 && w > 0 && w / scale < 760));
    root.setAttribute('data-vision-xnarrow', String(scale > 1 && w > 0 && w / scale < 480));
  }
  apply();
  if (window.addEventListener) window.addEventListener('resize', fit);
  if (mq && mq.addEventListener) mq.addEventListener('change', function () { if (prefs.palette === 'auto') apply(); });
  document.addEventListener('DOMContentLoaded', function () {
    var bar = document.getElementById('low-vision-bar');
    if (!bar) return;
    bar.hidden = bar.dataset.accountHidden === 'true';
    var live = document.getElementById('vision-live');
    var size = document.getElementById('vision-size');
    function paint() {
      bar.querySelectorAll('[data-palette]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.palette === prefs.palette)); });
      bar.querySelectorAll('[data-vision-toggle]').forEach(function (b) { b.setAttribute('aria-pressed', String(prefs[b.dataset.visionToggle])); });
      size.value = prefs.size;
    }
    function update(what) {
      apply(); paint();
      try { localStorage.setItem(key, JSON.stringify(prefs)); } catch (e) {}
      // Cleared first, then written: an unchanged live message is often not repeated.
      live.textContent = '';
      var later = typeof setTimeout === 'function' ? setTimeout : function (f) { f(); };
      later(function () { live.textContent = (what ? what + '. ' : '') + bar.dataset.saved; }, 50);
    }
    bar.querySelectorAll('[data-palette]').forEach(function (b) { b.addEventListener('click', function () { prefs.palette = b.dataset.palette; update((b.textContent || '').trim()); }); });
    // Messages in the page language (layout.php renders them with __()); English otherwise.
    function say(template, fill, fallback) { return String(template || fallback).replace(/\{(\w+)\}/g, function (m, k) { return k in fill ? fill[k] : m; }); }
    bar.querySelectorAll('[data-vision-toggle]').forEach(function (b) { b.addEventListener('click', function () { var k = b.dataset.visionToggle; prefs[k] = !prefs[k]; update(say(prefs[k] ? bar.dataset.onMsg : bar.dataset.offMsg, {name: (b.textContent || '').trim()}, prefs[k] ? '{name} on' : '{name} off')); }); });
    // Quiet combo box (owner rule 2): in Chrome on Windows every arrow press on the closed
    // list is a change. The size is applied and announced once, when the choice has rested
    // for 700 ms, or at once when the list is left or Enter is pressed.
    var sizeTimer = null;
    function commitSize() {
      if (sizeTimer !== null && typeof clearTimeout === 'function') clearTimeout(sizeTimer);
      sizeTimer = null;
      if (!sizes.includes(size.value) || size.value === prefs.size) return;
      prefs.size = size.value; update(say(bar.dataset.sizeMsg, {size: size.value}, 'Text size {size}%'));
    }
    size.addEventListener('change', function () {
      if (typeof setTimeout !== 'function') { commitSize(); return; }
      if (sizeTimer !== null) clearTimeout(sizeTimer);
      sizeTimer = setTimeout(commitSize, 700);
    });
    size.addEventListener('blur', commitSize);
    size.addEventListener('keydown', function (e) { if (e.key === 'Enter') commitSize(); });
    document.getElementById('vision-reset').addEventListener('click', function () { prefs = Object.assign({}, defaults); update(); });
    paint();
  });
})();
