const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const code = fs.readFileSync(__dirname + '/../public/assets/session-forms.js', 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
// Response headers by name, as fetch() exposes them.
const headers = h => ({get: name => h[name] ?? null});
// Real timers that never keep node running: a sent form is held for a minute
// (session-forms.js), which would otherwise hold the test run open that long.
// Each one is recorded with its delay, so a test can run it early.
function quietTimers() {
  const timers = [];
  return {timers, setTimeout(fn, ms) { const t = setTimeout(fn, ms); t.unref(); timers.push({fn, ms}); return t; }};
}
// uid: the account that rendered the page (data-uid on the timeout dialog).
function fixture(fetch, {uid = '7', requestSubmit = true} = {}) {
  let handler, message, submissions = 0, submittedBy;
  const attrs = {}, csrf = {value:'old-token'}, submitter = {name:'save_task',value:'1',
    getAttribute: n => n in attrs ? attrs[n] : null, setAttribute(n, v) { attrs[n] = String(v); }, removeAttribute(n) { delete attrs[n]; }};
  // The polite live region layout.php puts on every page.
  const live = {textContent:''}, windowHandlers = {}, clock = quietTimers();
  const files = ['synthetic.pdf'], choices = ['one','two'];
  const form = {method:'post',action:'https://example.test/tasks.php',files,choices,
    querySelector(s) { return s === '[name="csrf"]' ? csrf : message; },
    prepend(el) { message = el; },
  };
  // Like a browser, requestSubmit runs the form's inline onsubmit (when set) as part of the submit event.
  if (requestSubmit) form.requestSubmit = function (button) { if (typeof form.onsubmit === 'function') form.onsubmit(); const e = send(button); if (!e.defaultPrevented) { submissions++; submittedBy = button; } };
  const document = {
    addEventListener(_, f) { handler = f; },
    getElementById: id => id === 'timeout-warn' ? {getAttribute: name => name === 'data-uid' ? uid : null} : (id === 'session-check-live' ? live : null),
    createElement(tag) { return {tag,hidden:false,children:[],setAttribute(){},appendChild(el){this.children.push(el);},focus(){this.focused=true;}}; },
    createTextNode(text) { return {text}; },
  };
  function send(button = submitter) { const e = {target:form,submitter:button,defaultPrevented:false,preventDefault(){this.defaultPrevented=true;}}; handler(e); return e; }
  vm.runInNewContext(code, {document,window:{addEventListener(n, f) { windowHandlers[n] = f; }},fetch,AbortController,WeakSet,setTimeout:clock.setTimeout,clearTimeout});
  return {send,form,csrf,submitter,live,timers:clock.timers,fire:n=>windowHandlers[n]({type:n}),handler:e=>handler(e),message:()=>message,submissions:()=>submissions,submittedBy:()=>submittedBy};
}
// A novalidate form holding one date field, with just enough of the DOM for the
// partial-date guard.
const partial = {
  form() {
    let handler, checks = 0;
    const attrs = {'aria-describedby':'hint-start'}, notes = [];
    const field = {type:'date', id:'start', name:'start_date', disabled:false, validity:{badInput:true},
      getAttribute: n => n in attrs ? attrs[n] : null, setAttribute(n, v) { attrs[n] = String(v); }, removeAttribute(n) { delete attrs[n]; },
      hasAttribute: n => n in attrs, closest: () => null, focus() { this.focused = true; },
      insertAdjacentElement(where, el) { notes.push(el); el.parentNode = {removeChild(x) { notes.splice(notes.indexOf(x), 1); }}; }};
    const form = {method:'post', action:'https://example.test/schedule.php', noValidate:true,
      querySelector: s => s === '[name="csrf"]' ? {value:'old-token'} : null,
      querySelectorAll(s) {
        if (s === '[data-partial-for]') return notes.filter(n => 'data-partial-for' in n.attrs);
        if (s === '[data-partial-field]') return 'data-partial-field' in attrs ? [field] : [];
        return [field];
      },
      requestSubmit() {}, prepend() {}};
    const document = {addEventListener(_, f) { handler = f; }, getElementById: () => null,
      createElement: tag => ({tag, attrs:{}, setAttribute(n, v) { this.attrs[n] = v; }})};
    vm.runInNewContext(code, {document, window:{}, fetch: () => { checks++; return new Promise(() => {}); }, AbortController, WeakSet, setTimeout: quietTimers().setTimeout, clearTimeout});
    const submit = () => { const e = {target:form, submitter:null, defaultPrevented:false, preventDefault() { this.defaultPrevented = true; }}; handler(e); return e; };
    return {submit, field, attrs, notes, checks: () => checks};
  },
};
(async()=>{
  let f = fixture(()=>Promise.resolve({ok:false,status:401,headers:{get:()=>null}}));
  assert.equal(f.send().defaultPrevented,true); await flush();
  assert.equal(f.submissions(),0);
  assert.deepEqual(f.form.files,['synthetic.pdf']); assert.deepEqual(f.form.choices,['one','two']);
  assert.equal(f.message().focused,true);
  assert.equal(f.message().children[0].target,'_blank');
  console.log('PASS: Expired session retains file selections and multi-value fields with a focused sign-in recovery link');
  f = fixture(()=>Promise.resolve({ok:true,headers:headers({'X-CSRF-Token':'fresh-token','X-User-Id':'7'})}));
  f.send(); await flush();
  assert.equal(f.csrf.value,'fresh-token'); assert.equal(f.submissions(),1); assert.equal(f.submittedBy(),f.submitter);
  console.log('PASS: Valid session refreshes CSRF and submits exactly once with the original action button');
  // An inline onsubmit (such as a confirm()) already ran for the click; it must not run again on the re-submit.
  let inlineRuns = 0;
  f = fixture(()=>Promise.resolve({ok:true,headers:headers({'X-CSRF-Token':'fresh-token','X-User-Id':'7'})}));
  const inline = () => { inlineRuns++; return true; };
  f.form.onsubmit = inline;
  f.send(); await flush();
  assert.equal(f.submissions(),1); assert.equal(inlineRuns,0); assert.equal(f.form.onsubmit,inline);
  console.log('PASS: The re-submit after the session check does not run the inline onsubmit again, and restores it afterwards');
  f = fixture(()=>Promise.resolve({ok:true,headers:headers({'X-CSRF-Token':'other-token','X-User-Id':'9'})}));
  assert.equal(f.send().defaultPrevented,true); await flush();
  assert.equal(f.submissions(),0); assert.equal(f.csrf.value,'old-token');
  assert.match(f.message().textContent,/different account/); assert.equal(f.message().focused,true);
  console.log('PASS: When another account has signed in since the page opened, nothing is sent and its token is not used');
  f = fixture(()=>Promise.resolve({ok:true,headers:headers({'X-CSRF-Token':'fresh-token'})}));
  f.send(); await flush();
  assert.equal(f.submissions(),1); assert.equal(f.csrf.value,'fresh-token');
  console.log('PASS: A session check that does not name the account still submits once');
  let checked = false;
  f = fixture(()=>{ checked = true; return Promise.resolve({ok:true,headers:headers({'X-CSRF-Token':'fresh-token','X-User-Id':'7'})}); }, {requestSubmit:false});
  assert.equal(f.send().defaultPrevented,false); await flush();
  assert.equal(checked,false); assert.equal(f.submissions(),0);
  console.log('PASS: Without requestSubmit the browser submits the form itself, unchecked');
  f = fixture(()=>Promise.reject(new Error('offline')));
  f.send(); await flush(); assert.equal(f.submissions(),0); assert.match(f.message().textContent,/connection/);
  console.log('PASS: Offline check preserves the form and exposes recovery');
  // Count the session checks: without the pending guard the second click would
  // start a second check (and a second submission once both settle).
  const checks = [];
  f = fixture(()=>new Promise(r=>{checks.push(r);}));
  f.send(); f.send();
  assert.equal(checks.length,1);
  checks.forEach(r=>r({ok:true,headers:headers({'X-CSRF-Token':'fresh-token','X-User-Id':'7'})})); await flush();
  assert.equal(f.submissions(),1);
  console.log('PASS: Repeated clicks during session check do not duplicate submission');
  // Once a checked form has really been sent, a second press posts nothing while the
  // server works on it: no second check or submission, the button is marked
  // unavailable (it keeps focus) and the live region says it is still sending.
  let pings = 0;
  f = fixture(()=>{ pings++; return Promise.resolve({ok:true,headers:headers({'X-CSRF-Token':'fresh-token','X-User-Id':'7'})}); });
  f.send(); await flush();
  assert.equal(f.submissions(),1); assert.equal(f.submitter.getAttribute('aria-disabled'),'true'); assert.equal(f.live.textContent,'Sending, please wait…');
  assert.equal(f.send().defaultPrevented,true); await flush();
  assert.equal(pings,1); assert.equal(f.submissions(),1); assert.equal(f.live.textContent,'Still sending, please wait…');
  console.log('PASS: A form already sent is held: a second press sends nothing, the button is marked unavailable and the live region says it is sending');
  // Coming back to the page (Back), or a minute passing, releases it.
  f.fire('pageshow');
  assert.equal(f.submitter.getAttribute('aria-disabled'),null); assert.equal(f.live.textContent,'');
  f.send(); await flush();
  assert.equal(pings,2); assert.equal(f.submissions(),2);
  f.timers.filter(t=>t.ms===60000).pop().fn();
  assert.equal(f.submitter.getAttribute('aria-disabled'),null); assert.equal(f.live.textContent,'');
  f.send(); await flush();
  assert.equal(pings,3); assert.equal(f.submissions(),3);
  console.log('PASS: Showing the page again, or a minute passing, releases a held form so it can be sent again');
  // The sign-out form is never held for a session check; a submission a page's own
  // handler already stopped is left to that handler.
  checked = false;
  f = fixture(()=>{ checked = true; return Promise.resolve({ok:true,headers:headers({'X-CSRF-Token':'fresh-token','X-User-Id':'7'})}); });
  f.form.action = 'https://example.test/logout.php';
  assert.equal(f.send().defaultPrevented,false); await flush();
  assert.equal(checked,false);
  console.log('PASS: Signing out is sent directly, without a session check');
  f = fixture(()=>{ checked = true; return Promise.resolve({ok:true,headers:headers({'X-CSRF-Token':'fresh-token','X-User-Id':'7'})}); });
  // The fixture's send() always starts with defaultPrevented false, so this event is built here.
  f.handler({target:f.form,submitter:f.submitter,defaultPrevented:true,preventDefault(){}}); await flush();
  assert.equal(checked,false); assert.equal(f.submissions(),0);
  console.log('PASS: A submission a page handler already stopped is left to that handler');

  // A novalidate form with a half-typed date is not sent (no session check, nothing
  // lost as a blank date): the field is marked invalid, described by a note, and
  // focused. Once the date is complete, the note goes and the field's own
  // description comes back.
  const d = partial.form();
  assert.equal(d.submit().defaultPrevented,true);
  assert.equal(d.checks(),0);
  assert.equal(d.attrs['aria-invalid'],'true');
  assert.equal(d.attrs['aria-describedby'],'hint-start err-start-partial');
  assert.equal(d.notes.length,1); assert.equal(d.notes[0].id,'err-start-partial'); assert.match(d.notes[0].textContent,/complete date/);
  assert.equal(d.field.focused,true);
  d.field.validity.badInput = false;
  assert.equal(d.submit().defaultPrevented,true);
  assert.equal(d.notes.length,0);
  assert.equal(d.attrs['aria-describedby'],'hint-start');
  assert.equal('aria-invalid' in d.attrs,false);
  assert.equal(d.checks(),1);
  console.log('PASS: A half-typed date stops a novalidate form, is described on its field and focused; completing it restores the field and sends');
})().catch(e=>{console.error(e);process.exitCode=1;});
