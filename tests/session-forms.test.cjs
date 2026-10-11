const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const code = fs.readFileSync(__dirname + '/../public/assets/session-forms.js', 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
// Response headers by name, as fetch() exposes them.
const headers = h => ({get: name => h[name] ?? null});
// uid: the account that rendered the page (data-uid on the timeout dialog).
function fixture(fetch, {uid = '7', requestSubmit = true} = {}) {
  let handler, message, submissions = 0, submittedBy;
  const csrf = {value:'old-token'}, submitter = {name:'save_task',value:'1'};
  const files = ['synthetic.pdf'], choices = ['one','two'];
  const form = {method:'post',action:'https://example.test/tasks.php',files,choices,
    querySelector(s) { return s === '[name="csrf"]' ? csrf : message; },
    prepend(el) { message = el; },
  };
  // Like a browser, requestSubmit runs the form's inline onsubmit (when set) as part of the submit event.
  if (requestSubmit) form.requestSubmit = function (button) { if (typeof form.onsubmit === 'function') form.onsubmit(); const e = send(button); if (!e.defaultPrevented) { submissions++; submittedBy = button; } };
  const document = {
    addEventListener(_, f) { handler = f; },
    getElementById: id => id === 'timeout-warn' ? {getAttribute: name => name === 'data-uid' ? uid : null} : null,
    createElement(tag) { return {tag,hidden:false,children:[],setAttribute(){},appendChild(el){this.children.push(el);},focus(){this.focused=true;}}; },
    createTextNode(text) { return {text}; },
  };
  function send(button = submitter) { const e = {target:form,submitter:button,defaultPrevented:false,preventDefault(){this.defaultPrevented=true;}}; handler(e); return e; }
  vm.runInNewContext(code, {document,window:{},fetch,AbortController,WeakSet,setTimeout,clearTimeout});
  return {send,form,csrf,submitter,message:()=>message,submissions:()=>submissions,submittedBy:()=>submittedBy};
}
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
})().catch(e=>{console.error(e);process.exitCode=1;});
