const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const code = fs.readFileSync(__dirname + '/../public/assets/session-timeout.js', 'utf8');

function fixture(fetch) {
  let now = 0, currentFetch = fetch;
  const handlers = {}, timers = new Map(); let nextTimer = 0;
  const elements = {}, events = [], fetches = [], armed = [];
  const document = {
    activeElement: null, body: {isConnected: true},
    getElementById: id => elements[id],
    addEventListener(name, fn) { (handlers[name] ??= []).push(fn); },
    dispatchEvent(e) { events.push(e.type); }
  };
  function element(id, hidden = false) {
    return elements[id] = {
      id, hidden, disabled: false, isConnected: true, dataset: {warnMs: '100'}, attributes: {}, handlers: {},
      focus() { document.activeElement = this; },
      addEventListener(name, fn) { this.handlers[name] = fn; },
      setAttribute(name, value) { this.attributes[name] = value; },
      closest() { return this.hidden || (id === 'login-link' && elements['tw-expired'].hidden) ? this : null; }
    };
  }
  const original = element('original'); original.focus();
  const dialog = element('timeout-warn', true), stay = element('tw-stay'), dismiss = element('tw-dismiss');
  const error = element('tw-error', true), description = element('tw-desc'), expired = element('tw-expired', true), link = element('login-link');
  dialog.contains = el => [stay, dismiss, link].includes(el);
  dialog.querySelectorAll = () => [stay, dismiss, link];
  expired.querySelector = () => link;
  const main = element('main');
  vm.runInNewContext(code, {document, fetch: (...args) => { fetches.push(args[0]); return currentFetch(...args); }, AbortController, Date: {now: () => now}, Array, Number,
    Event: class { constructor(type) { this.type = type; } },
    setTimeout(fn, ms) { const id = ++nextTimer; timers.set(id, {fn, ms}); if (ms === 100) armed.push(id); return id; },
    clearTimeout(id) { timers.delete(id); }});
  function key(name, shiftKey = false) {
    let prevented = false;
    for (const fn of handlers.keydown) fn({key: name, shiftKey, preventDefault() { prevented = true; }});
    return prevented;
  }
  function runTimer(ms) { [...timers.values()].find(t => t.ms === ms).fn(); }
  // The most recently scheduled timer with this delay (a later warning).
  function runLatest(ms) { [...timers.values()].reverse().find(t => t.ms === ms).fn(); }
  return {dialog, stay, dismiss, error, description, expired, link, original, main, document, events, fetches, armed, key, runTimer, runLatest,
    useFetch(fn) { currentFetch = fn; }, activity(ms) { now = ms; for (const fn of handlers.input) fn({}); },
    fire(name) { for (const fn of handlers[name] || []) fn({type: name}); }};
}
const flush = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  let activityCalls = 0;
  let f = fixture(() => { activityCalls++; return Promise.resolve({ok: true}); });
  f.activity(1500); await flush();
  assert.equal(activityCalls, 1);
  console.log('PASS: Short sessions send activity keepalives before the old four-minute threshold');

  f = fixture(() => Promise.reject(new Error('offline')));
  f.runTimer(100); f.stay.handlers.click(); await flush();
  assert.equal(f.error.hidden, false);
  f.key('Escape');
  assert.equal(f.dialog.hidden, true); assert.equal(f.document.activeElement, f.original);
  console.log('PASS: Offline error is exposed; Escape dismisses locally and restores focus');

  f = fixture(() => new Promise(() => {}));
  f.runTimer(100); f.stay.handlers.click(); f.dismiss.handlers.click();
  assert.equal(f.dialog.hidden, true);
  assert.equal(f.document.activeElement, f.original);
  console.log('PASS: A stalled request cannot block dismissal');

  f = fixture((_, options) => new Promise((resolve, reject) => options.signal.addEventListener('abort', () => reject(new Error('aborted')))));
  f.runTimer(100); f.stay.handlers.click(); f.runTimer(10000); await flush();
  assert.equal(f.error.hidden, false); f.key('Escape'); assert.equal(f.dialog.hidden, true);
  console.log('PASS: Request deadline exposes recovery without trapping focus');

  f = fixture(() => Promise.resolve({ok: true}));
  f.runTimer(100); f.stay.handlers.click(); await flush();
  assert.equal(f.dialog.hidden, true); assert.equal(f.document.activeElement, f.original);
  assert.deepEqual(f.events, []);
  console.log('PASS: Successful keepalive dismisses and restores focus');

  f = fixture(() => Promise.resolve({ok: false, status: 401}));
  f.runTimer(100); f.stay.handlers.click(); await flush();
  assert.equal(f.stay.hidden, true); assert.equal(f.expired.hidden, false);
  assert.equal(f.document.activeElement, f.link);
  assert.equal(f.dialog.attributes['aria-describedby'], 'tw-expired');
  assert.equal(f.key('Tab'), true); assert.equal(f.document.activeElement, f.dismiss);
  assert.equal(f.key('Tab', true), true); assert.equal(f.document.activeElement, f.link);
  f.key('Escape'); assert.equal(f.dialog.hidden, true);
  // voice.js listens for this to forget the ended session's conversation.
  assert.deepEqual(f.events, ['cms:session-ended']);
  console.log('PASS: Expired state has a working focus cycle and local exit, and tells the page the session ended');

  f = fixture(() => Promise.resolve({ok: false, status: 500}));
  f.runTimer(100); f.stay.handlers.click(); await flush();
  assert.equal(f.error.hidden, false); assert.equal(f.expired.hidden, true);
  assert.deepEqual(f.events, []);
  console.log('PASS: Server failures are distinguished from expired sessions');

  // A later warning starts clean: no connection error or expired notice is left
  // over from the earlier one once the session has been kept alive again.
  f = fixture(() => Promise.reject(new Error('offline')));
  f.runTimer(100); f.stay.handlers.click(); await flush();
  assert.equal(f.error.hidden, false);
  f.dismiss.handlers.click();
  f.useFetch(() => Promise.resolve({ok: true})); f.activity(5000); await flush();
  f.runLatest(100);
  assert.equal(f.dialog.hidden, false); assert.equal(f.error.hidden, true);
  console.log('PASS: A later warning does not repeat an earlier connection error');

  f = fixture(() => Promise.resolve({ok: false, status: 401}));
  f.runTimer(100); f.stay.handlers.click(); await flush();
  assert.equal(f.expired.hidden, false);
  f.dismiss.handlers.click();
  f.useFetch(() => Promise.resolve({ok: true})); f.activity(5000); await flush();
  f.runLatest(100);
  assert.equal(f.expired.hidden, true); assert.equal(f.stay.hidden, false); assert.equal(f.description.hidden, false);
  assert.equal(f.dialog.attributes['aria-describedby'], 'tw-desc'); assert.equal(f.document.activeElement, f.stay);
  console.log('PASS: After signing in again, a later warning offers Stay signed in with its description');

  // "Return to my work" closes at once, pings the server, and re-arms the warning when it answers.
  f = fixture(() => Promise.resolve({ok: true}));
  f.runTimer(100);
  const armedBefore = f.armed.length;
  f.dismiss.handlers.click(); await flush();
  assert.equal(f.dialog.hidden, true);
  assert.deepEqual(f.fetches, ['ping.php']);
  assert.equal(f.armed.length, armedBefore + 1);
  console.log('PASS: Return to my work pings the server and re-arms the warning');

  // A warning that opened with nothing focused (browse mode) returns to the main content.
  f = fixture(() => Promise.resolve({ok: true}));
  f.document.activeElement = f.document.body;
  f.runTimer(100); f.dismiss.handlers.click();
  assert.equal(f.document.activeElement, f.main);
  console.log('PASS: Closing a warning opened with nothing focused moves focus to the main content');

  // Activity the server already saw (cms:activity) re-arms the warning without a ping.
  f = fixture(() => Promise.resolve({ok: true}));
  const armedAtLoad = f.armed.length;
  f.fire('cms:activity');
  assert.equal(f.armed.length, armedAtLoad + 1);
  assert.deepEqual(f.fetches, []);
  console.log('PASS: cms:activity re-arms the hidden warning without calling the server');
})().catch(error => { console.error(error); process.exitCode = 1; });
