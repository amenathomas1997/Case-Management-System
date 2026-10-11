const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
function element(value = '') {
  return {value, hidden: false, disabled: false, textContent: '', events: {}, addEventListener(name, fn) { this.events[name] = fn; }, dispatchEvent() {}, focus() { this.focused = true; }};
}
async function main() {
  const fields = {csrf: element('synthetic-csrf'), hw_title: element('Reading practice'), hw_instructions: element('Original instructions')};
  const nodes = Object.fromEntries(['field','status','draft','generate','apply','undo','request','review'].map(k => [`[data-ai-${k}]`, element()]));
  nodes['[data-ai-field]'].value = 'instructions'; nodes['[data-ai-request]'].value = 'Use short steps';
  const form = {elements: {namedItem: name => fields[name]}};
  const panel = {dataset: {kind: 'homework', context: '{"track_id":"100"}', map: '{"title":"hw_title","instructions":"hw_instructions"}'}, closest: () => form, querySelector: s => nodes[s], setAttribute() {}};
  let request, fail = false, calls = 0, hold = false;
  const releases = [];
  const context = {document: {querySelectorAll: () => [panel]}, Event: class {}, FormData: class {forEach(fn) { Object.entries(fields).forEach(([k,v]) => fn(v.value,k)); }}, fetch: async (url, opts) => {
    calls++; request = {url, ...opts};
    if (hold) await new Promise(resolve => { releases.push(resolve); });
    return {ok: !fail, json: async () => fail ? {ok: false, error: 'Synthetic provider unavailable'} : {ok: true, text: 'Reviewed draft <script>plain text</script>'}};
  }};
  vm.runInNewContext(fs.readFileSync(__dirname + '/../public/assets/at-ai.js','utf8'), context);
  const generate = nodes['[data-ai-generate]'];
  await generate.events.click();
  assert.equal(generate.ariaDisabled, 'false');
  assert.equal(fields.hw_instructions.value, 'Original instructions');
  assert.equal(request.headers['X-CSRF-Token'], 'synthetic-csrf');
  assert.equal(JSON.parse(request.body).values.csrf, undefined);
  assert.equal(JSON.parse(request.body).values.instructions, 'Original instructions');
  assert.equal(JSON.parse(request.body).values.track_id, '100');
  assert.equal(nodes['[data-ai-draft]'].value, 'Reviewed draft <script>plain text</script>');
  nodes['[data-ai-field]'].value = 'title'; // The draft still belongs to instructions.
  nodes['[data-ai-apply]'].events.click();
  assert.equal(fields.hw_title.value, 'Reading practice');
  assert.equal(fields.hw_instructions.value, 'Reviewed draft <script>plain text</script>');
  nodes['[data-ai-undo]'].events.click();
  assert.equal(fields.hw_instructions.value, 'Original instructions');
  fail = true;
  await nodes['[data-ai-generate]'].events.click();
  assert.equal(fields.hw_instructions.value, 'Original instructions');
  assert.equal(nodes['[data-ai-status]'].textContent, 'Synthetic provider unavailable');
  assert.equal(generate.ariaDisabled, 'false');
  assert.equal(nodes['[data-ai-apply]'].disabled, true);
  console.log('PASS: AI drafting preserves text until review/apply, supports undo, sends CSRF, and handles errors without mutation');
  // While a draft is pending the button keeps focus but is announced as
  // unavailable, and a second activation sends no second request.
  fail = false; hold = true; calls = 0;
  const pending = [generate.events.click()];
  assert.equal(generate.ariaDisabled, 'true');
  pending.push(generate.events.click());
  assert.equal(calls, 1);
  releases.forEach(release => release()); await Promise.all(pending);
  assert.equal(calls, 1);
  assert.equal(generate.ariaDisabled, 'false');
  assert.equal(nodes['[data-ai-apply]'].disabled, false);
  console.log('PASS: Repeated Draft activations during a pending request are ignored and the button is re-enabled afterwards');
  // Undo restores the text from before the first Apply of a draft, and a new
  // draft applied after the user edited the field restores that edited text.
  async function freshPanel() {
    const own = {csrf: element('synthetic-csrf'), hw_instructions: element('My own text')};
    const parts = Object.fromEntries(['field','status','draft','generate','apply','request','review'].map(k => [`[data-ai-${k}]`, element()]));
    parts['[data-ai-undo]'] = element(); parts['[data-ai-undo]'].hidden = true;
    parts['[data-ai-field]'].value = 'instructions';
    const ownForm = {elements: {namedItem: name => own[name]}};
    const ownPanel = {dataset: {kind: 'homework', map: '{"instructions":"hw_instructions"}'}, closest: () => ownForm, querySelector: s => parts[s], setAttribute() {}};
    let drafts = 0;
    vm.runInNewContext(fs.readFileSync(__dirname + '/../public/assets/at-ai.js','utf8'), {document: {querySelectorAll: () => [ownPanel]}, Event: class {}, FormData: class {forEach() {}},
      fetch: async () => ({ok: true, json: async () => ({ok: true, text: 'AI draft ' + (++drafts)})})});
    await parts['[data-ai-generate]'].events.click();
    return {own, parts, click: k => parts[`[data-ai-${k}]`].events.click()};
  }
  let p = await freshPanel();
  p.click('apply'); p.click('apply');
  assert.equal(p.own.hw_instructions.value, 'AI draft 1');
  p.click('undo');
  assert.equal(p.own.hw_instructions.value, 'My own text');
  console.log('PASS: Applying the same draft twice still lets Undo restore the original text');
  p = await freshPanel();
  p.click('apply'); p.own.hw_instructions.value = 'User rewrote it';
  await p.click('generate'); p.click('apply');
  assert.equal(p.own.hw_instructions.value, 'AI draft 2');
  p.click('undo');
  assert.equal(p.own.hw_instructions.value, 'User rewrote it');
  console.log('PASS: After the user edits an applied draft, Undo of a newer draft restores the edited text');
  // The token is the one session-forms.js last refreshed (window.__csrf), else the
  // form's own field. An ended session or a lost connection each gets its own
  // message, and the form is left exactly as it was.
  async function probe(respond, extra = {}) {
    const own = {csrf: element('field-csrf'), hw_instructions: element('Kept text')};
    const parts = Object.fromEntries(['field','status','draft','generate','apply','undo','request','review'].map(k => [`[data-ai-${k}]`, element()]));
    parts['[data-ai-field]'].value = 'instructions';
    const ownForm = {elements: {namedItem: name => own[name]}};
    const ownPanel = {dataset: {kind: 'homework', map: '{"instructions":"hw_instructions"}'}, closest: () => ownForm, querySelector: s => parts[s], setAttribute() {}};
    let sent = null;
    vm.runInNewContext(fs.readFileSync(__dirname + '/../public/assets/at-ai.js','utf8'), {document: {querySelectorAll: () => [ownPanel]}, Event: class {}, FormData: class {forEach() {}},
      fetch: async (url, opts) => { sent = opts; return respond(); }, ...extra});
    await parts['[data-ai-generate]'].events.click();
    return {own, parts, sent, status: parts['[data-ai-status]'].textContent};
  }
  const drafted = async () => ({ok: true, status: 200, json: async () => ({ok: true, text: 'Fresh draft'})});
  let r = await probe(drafted, {window: {__csrf: 'fresh'}});
  assert.equal(r.sent.headers['X-CSRF-Token'], 'fresh');
  assert.equal(r.parts['[data-ai-draft]'].value, 'Fresh draft');
  r = await probe(drafted);
  assert.equal(r.sent.headers['X-CSRF-Token'], 'field-csrf');
  console.log('PASS: Drafting sends the refreshed page token when there is one, otherwise the form token');
  const unchanged = r => {
    assert.equal(r.own.hw_instructions.value, 'Kept text');
    assert.equal(r.parts['[data-ai-draft]'].value, '');
    assert.equal(r.parts['[data-ai-apply]'].disabled, true);
    assert.equal(r.parts['[data-ai-generate]'].ariaDisabled, 'false');
  };
  for (const status of [401, 403]) {
    r = await probe(async () => ({ok: false, status, json: async () => { throw new Error('not JSON'); }}));
    assert.match(r.status, /session has changed or ended/); unchanged(r);
  }
  r = await probe(async () => ({ok: true, status: 200, json: async () => { const e = new Error('Unexpected token <'); e.name = 'SyntaxError'; throw e; }}));
  assert.match(r.status, /session has changed or ended/); unchanged(r);
  console.log('PASS: A refused or ended session, or a sign-in page instead of JSON, says the session changed and leaves the form unchanged');
  r = await probe(async () => { throw new TypeError('Failed to fetch'); });
  assert.match(r.status, /could not reach the server/); unchanged(r);
  console.log('PASS: A request that cannot reach the server says so and leaves the form unchanged');
  // A draft cut off at the length limit is said to be incomplete in the status and
  // marked in the draft's label; the mark is hidden again for a complete draft.
  {
    const own = {csrf: element('synthetic-csrf'), hw_instructions: element('Kept text')};
    const parts = Object.fromEntries(['field','status','draft','generate','apply','undo','request','review'].map(k => [`[data-ai-${k}]`, element()]));
    parts['[data-ai-field]'].value = 'instructions'; parts['[data-ai-draft]'].id = 'ai-draft';
    const label = {children: [], appendChild(el) { this.children.push(el); parts['[data-ai-incomplete]'] = el; }};
    parts['label[for="ai-draft"]'] = label;
    const ownForm = {elements: {namedItem: name => own[name]}};
    const ownPanel = {dataset: {kind: 'homework', map: '{"instructions":"hw_instructions"}'}, closest: () => ownForm, querySelector: s => parts[s] ?? null, setAttribute() {}};
    const replies = [{ok: true, text: 'Cut-off draft', truncated: true}, {ok: true, text: 'Whole draft'}];
    vm.runInNewContext(fs.readFileSync(__dirname + '/../public/assets/at-ai.js','utf8'), {document: {querySelectorAll: () => [ownPanel], createElement: tag => ({tag, dataset: {}, hidden: false, textContent: ''})},
      Event: class {}, FormData: class {forEach() {}}, fetch: async () => { const body = replies.shift(); return {ok: true, status: 200, json: async () => body}; }});
    await parts['[data-ai-generate]'].events.click();
    const mark = parts['[data-ai-incomplete]'];
    assert.match(parts['[data-ai-status]'].textContent, /incomplete/);
    assert.equal(label.children.length, 1); assert.equal(mark.hidden, false); assert.match(mark.textContent, /incomplete/);
    assert.equal(own.hw_instructions.value, 'Kept text');
    await parts['[data-ai-generate]'].events.click();
    assert.doesNotMatch(parts['[data-ai-status]'].textContent, /incomplete/);
    assert.equal(label.children.length, 1); assert.equal(mark.hidden, true);
    console.log('PASS: A truncated draft is reported as incomplete in the status and its label, and the mark is hidden for a complete draft');
  }
  finished = true;
}
// A request that never settles would end the process quietly with every
// assertion after it skipped; that must count as a failure.
let finished = false;
process.on('exit', () => { if (!finished && !process.exitCode) { console.error('FAIL: the test stopped before finishing'); process.exitCode = 1; } });
main().catch(error => { console.error(error); process.exitCode = 1; });
