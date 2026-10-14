const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function fixture() {
  const elements = new Map();
  let focused = null, micStarts = 0, cancelled = 0;
  function el(selector) {
    if (!elements.has(selector)) elements.set(selector, {
      hidden: selector === '.vfab-panel' || selector === '.vfab-confirm', value: '', handlers: {}, attrs: {},
      classList: {add(){}, remove(){}, toggle(){}},
      addEventListener(n, f) { this.handlers[n] = f; },
      setAttribute(n, v) { this.attrs[n] = v; },
      focus() { focused = selector; }, querySelector: el, appendChild(){},
    });
    return elements.get(selector);
  }
  const storage = new Map([['cms_convo', '1']]);
  const window = {
    SpeechRecognition: function () { this.start = () => { micStarts++; }; this.stop = () => {}; },
    speechSynthesis: {getVoices:()=>[], cancel:()=>{cancelled++;}},
  };
  vm.runInNewContext(fs.readFileSync(__dirname + '/../public/assets/voice.js', 'utf8'), {
    window, speechSynthesis: window.speechSynthesis,
    document: {getElementById:()=>({querySelector:el}), createElement:()=>({})},
    sessionStorage: {getItem:k=>storage.get(k), setItem:(k,v)=>storage.set(k,v), removeItem:k=>storage.delete(k)},
  });
  return {el, storage, focus:()=>focused, mic:()=>micStarts, cancelled:()=>cancelled};
}

// A leftover conversation flag with no saved history of this account: voice.js
// forgets it on load (the resume itself is checked in voiceFixture below).
const f = fixture();
assert.equal(f.mic(), 0);
assert.equal(f.el('.vfab-panel').hidden, true);
assert.equal(f.storage.has('cms_convo'), false);
console.log('PASS: A conversation flag with no saved history is forgotten on load and starts nothing');
f.el('.vfab-btn').handlers.click();
assert.equal(f.mic(), 0);
assert.equal(f.focus(), '.vfab-input');
assert.equal(f.el('.vfab-btn').attrs['aria-expanded'], 'true');
console.log('PASS: Opening the assistant focuses typing without activating the microphone');
f.el('.vfab-listen').handlers.click();
assert.equal(f.mic(), 1);
f.el('.vfab-convo').handlers.click();
assert.equal(f.storage.get('cms_convo'), '1');
let prevented = false;
f.el('.vfab-panel').handlers.keydown({key:'Escape',preventDefault(){prevented=true;}});
assert.equal(prevented, true);
assert.equal(f.el('.vfab-panel').hidden, true);
assert.equal(f.el('.vfab-btn').attrs['aria-expanded'], 'false');
assert.equal(f.focus(), '.vfab-btn');
assert.equal(f.storage.has('cms_convo'), false);
assert.ok(f.cancelled() > 0);
console.log('PASS: Escape closes the panel, ends conversation, cancels speech, and restores focus');
f.el('.vfab-btn').handlers.click();
f.el('.vfab-stop').handlers.click();
assert.equal(f.el('.vfab-panel').hidden, false);
assert.match(f.el('.vfab-status').textContent, /Speech stopped/);
console.log('PASS: Stop speaking preserves the open panel and reports the stopped state');

// A fuller page for the voice assistant: browser storage, timers, the server
// endpoint and document-level listeners are all stand-ins the test controls.
function voiceFixture({storage = new Map(), local = new Map(), uid = 7, csrf = 'synthetic-csrf', earcon = null} = {}) {
  const elements = new Map(), timers = [], requests = [], documentHandlers = {}, utterances = [];
  // What voice.js adds outside the panel (its page-level live region) and beside the
  // status line (the listening note), so both can be checked.
  const fabChildren = [], inserted = [];
  let focused = null, micStarts = 0, recognizer = null;
  function el(selector) {
    if (!elements.has(selector)) elements.set(selector, {
      hidden: selector === '.vfab-panel' || selector === '.vfab-confirm', value: '', textContent: '', handlers: {}, attrs: {}, children: [],
      classList: {add(){}, remove(){}, toggle(){}},
      addEventListener(n, f) { this.handlers[n] = f; },
      setAttribute(n, v) { this.attrs[n] = v; },
      focus() { focused = selector; }, querySelector: el, appendChild(child) { this.children.push(child); },
      parentNode: {insertBefore(node) { inserted.push(node); }},
    });
    return elements.get(selector);
  }
  const window = {
    __csrf: csrf, __voiceUid: uid, location: {origin: 'https://example.test'}, Earcon: earcon,
    SpeechRecognition: function () { recognizer = this; this.start = () => { micStarts++; }; this.stop = () => {}; },
    speechSynthesis: {getVoices: () => [{name: 'Synthetic voice', lang: 'en-US'}], cancel() {}, speak(u) { utterances.push(u); }},
  };
  const location = {pathname: '/index.php', href: ''};
  const fab = {querySelector: el, appendChild(child) { fabChildren.push(child); }};
  const document = {activeElement: null, getElementById: () => fab,
    createElement: tag => ({tagName: tag, className: '', textContent: '', children: [], attrs: {},
      appendChild(child) { this.children.push(child); }, setAttribute(n, v) { this.attrs[n] = String(v); }}),
    addEventListener(n, f) { documentHandlers[n] = f; }};
  vm.runInNewContext(fs.readFileSync(__dirname + '/../public/assets/voice.js', 'utf8'), {
    window, speechSynthesis: window.speechSynthesis, Earcon: earcon, SpeechSynthesisUtterance: function (text) { this.text = text; }, URL,
    location, document,
    sessionStorage: {getItem: k => storage.has(k) ? storage.get(k) : null, setItem: (k, v) => storage.set(k, v), removeItem: k => storage.delete(k)},
    localStorage: {getItem: k => local.has(k) ? local.get(k) : null, setItem: (k, v) => local.set(k, v)},
    setTimeout: (fn, ms) => { timers.push({fn, ms}); return timers.length; }, clearTimeout() {},
    fetch: (url, init) => new Promise(resolve => { requests.push({url, init, resolve}); }),
  });
  return {el, storage, timers, requests, documentHandlers, utterances, location, document, focus: () => focused, mic: () => micStarts, recognizer: () => recognizer,
    pageLive: () => fabChildren.find(child => child.attrs && child.attrs.role === 'status'), listenNote: () => inserted[0],
    // headers: response headers by name (ping.php sends X-CSRF-Token and X-User-Id).
    reply(body, status = 200, headers = null) {
      requests.shift().resolve({status, ok: status < 400, headers: headers ? {get: n => headers[n] || null} : undefined,
        text: () => Promise.resolve(typeof body === 'string' ? body : JSON.stringify(body))});
    }};
}
const settle = async () => { for (let i = 0; i < 4; i++) await new Promise(resolve => setImmediate(resolve)); };

(async () => {
  // A silent or unclear answer must not erase a pending write or loop the microphone.
  let quiet = voiceFixture({local: new Map([['cms_voice_replies', 'off']])});
  quiet.el('.vfab-btn').handlers.click();
  quiet.el('.vfab-input').value = 'Create a synthetic task';
  quiet.el('.vfab-send').handlers.click();
  quiet.reply({ok: true, action: 'confirm', do: 'create_task', params: {title: 'Keep this draft'}, speak: 'Say yes to save.'});
  await settle();
  quiet.el('.vfab-convo').handlers.click();
  quiet.recognizer().onerror({error: 'no-speech'});
  assert.equal(quiet.el('.vfab-confirm').hidden, false);
  assert.equal(quiet.storage.has('cms_convo'), false);
  assert.equal(quiet.requests.length, 0);
  assert.match(quiet.el('.vfab-status').textContent, /still waiting/);
  quiet.el('.vfab-input').value = 'I need more time';
  quiet.el('.vfab-send').handlers.click();
  assert.equal(quiet.el('.vfab-confirm').hidden, false);
  assert.equal(quiet.requests.length, 0);
  quiet.el('.vfab-listen').handlers.click();
  quiet.el('.vfab-panel').handlers.keydown({key: 'Tab'});
  assert.equal(quiet.recognizer().onresult, null);
  quiet.el('.vfab-no').handlers.click();
  assert.equal(quiet.el('.vfab-confirm').hidden, true);
  console.log('PASS: Silence and unclear answers preserve a pending write; keyboard navigation aborts capture; explicit decline cancels');
  // Spoken replies off: in a conversation the reply is the status line (which a
  // screen reader or braille display reads), so the microphone does not start
  // again by itself or on a timer; Alt+L in the panel continues.
  let v = voiceFixture({local: new Map([['cms_voice_replies', 'off']])});
  v.el('.vfab-btn').handlers.click();
  v.el('.vfab-convo').handlers.click();
  assert.equal(v.mic(), 1);
  v.recognizer().onresult({results: [[{transcript: 'What is on my list today?'}]]});
  v.reply({ok: true, action: 'answer', speak: 'You have three synthetic tasks.'});
  await settle();
  assert.equal(v.el('.vfab-status').textContent, 'You have three synthetic tasks.');
  assert.equal(v.mic(), 1);
  assert.equal(v.timers.length, 0);
  let prevented = false;
  v.el('.vfab-panel').handlers.keydown({altKey: true, code: 'KeyL', key: '¬', preventDefault() { prevented = true; }});
  assert.equal(prevented, true);
  assert.equal(v.mic(), 2);
  console.log('PASS: With spoken replies off, a conversation reply waits in the status line and Alt+L continues listening');

  // Answering No to a confirmation moves focus to the request box before the choice disappears.
  v = voiceFixture({local: new Map([['cms_voice_replies', 'off']])});
  v.el('.vfab-btn').handlers.click();
  v.el('.vfab-input').value = 'Add a synthetic task';
  v.el('.vfab-send').handlers.click();
  v.reply({ok: true, action: 'confirm', do: 'create_task', params: {title: 'Synthetic task'}, speak: 'Create the task "Synthetic task"?'});
  await settle();
  assert.equal(v.el('.vfab-confirm').hidden, false);
  assert.equal(v.focus(), '.vfab-yes');
  v.el('.vfab-no').handlers.click();
  assert.equal(v.focus(), '.vfab-input');
  assert.equal(v.el('.vfab-confirm').hidden, true);
  console.log('PASS: Choosing No moves focus to the request box');

  // The saved conversation belongs to the account that had it: the same account
  // sees it again after a page change; another sign-in in the tab never does.
  const first = voiceFixture({uid: 7});
  first.el('.vfab-btn').handlers.click();
  first.el('.vfab-input').value = 'Synthetic question';
  first.el('.vfab-send').handlers.click();
  assert.ok(first.storage.has('cms_convo_hist'));
  const saved = new Map([...first.storage, ['cms_convo', '1']]);
  v = voiceFixture({storage: new Map(saved), uid: 7});
  assert.equal(v.el('.vfab-log').children.length, 1);
  // The same account's conversation is offered again, never resumed by itself.
  assert.equal(v.mic(), 0);
  assert.equal(v.el('.vfab-panel').hidden, true);
  assert.match(v.el('.vfab-status').textContent, /previous conversation is available/);
  console.log('PASS: A saved conversation is offered again after a page change without opening the panel or the microphone');
  v = voiceFixture({storage: new Map(saved), uid: 8});
  assert.equal(v.storage.has('cms_convo_hist'), false);
  assert.equal(v.storage.has('cms_convo'), false);
  assert.equal(v.el('.vfab-log').children.length, 0);
  console.log("PASS: Another account's saved conversation is discarded on load and never shown");

  // Signing out forgets the conversation in this tab.
  v = voiceFixture({storage: new Map(saved), uid: 7});
  v.documentHandlers.submit({target: {action: 'https://example.test/logout.php'}});
  assert.equal(v.storage.has('cms_convo_hist'), false);
  assert.equal(v.storage.has('cms_convo'), false);
  console.log('PASS: Submitting the sign-out form forgets the saved conversation');
  // A pending confirmation is answered by what is typed: "yes" sends the
  // confirmed action itself, never a new request to interpret.
  v = voiceFixture({local: new Map([['cms_voice_replies', 'off']])});
  v.el('.vfab-btn').handlers.click();
  v.el('.vfab-input').value = 'Add a synthetic note';
  v.el('.vfab-send').handlers.click();
  v.reply({ok: true, action: 'confirm', do: 'create_note', params: {client_id: 3, text: 'Synthetic'}, speak: 'Save the note? Say yes to save.'});
  await settle();
  assert.equal(v.el('.vfab-confirm').hidden, false);
  v.el('.vfab-input').value = 'Yes.';
  v.el('.vfab-send').handlers.click();
  assert.equal(v.requests.length, 1);
  const confirmed = JSON.parse(v.requests[0].init.body);
  assert.equal(confirmed.do, 'create_note');
  assert.equal(confirmed.transcript, undefined);
  assert.equal(v.el('.vfab-confirm').hidden, true);
  console.log('PASS: A typed yes confirms the pending action and is never sent as a new request');

  // Unclear answers preserve the pending draft; an explicit negative cancels.
  v = voiceFixture({local: new Map([['cms_voice_replies', 'off']])});
  v.el('.vfab-btn').handlers.click();
  v.el('.vfab-input').value = 'Add a synthetic note';
  v.el('.vfab-send').handlers.click();
  v.reply({ok: true, action: 'confirm', do: 'create_note', params: {}, speak: 'Save the note?'});
  await settle();
  v.el('.vfab-input').value = 'what about tomorrow';
  v.el('.vfab-send').handlers.click();
  assert.equal(v.requests.length, 0);
  assert.equal(v.el('.vfab-confirm').hidden, false);
  assert.match(v.el('.vfab-status').textContent, /still waiting/);
  v.el('.vfab-input').value = 'synthetic nonsense';
  v.el('.vfab-send').handlers.click();
  assert.equal(v.requests.length, 0);
  assert.equal(v.el('.vfab-confirm').hidden, false);
  assert.match(v.el('.vfab-status').textContent, /still waiting/);
  v = voiceFixture({local: new Map([['cms_voice_replies', 'off']])});
  v.el('.vfab-btn').handlers.click();
  v.el('.vfab-input').value = 'Add a synthetic note';
  v.el('.vfab-send').handlers.click();
  v.reply({ok: true, action: 'confirm', do: 'create_note', params: {}, speak: 'Save the note?'});
  await settle();
  v.el('.vfab-input').value = "yes, no don't";
  v.el('.vfab-send').handlers.click();
  assert.equal(v.requests.length, 0);
  assert.match(v.el('.vfab-status').textContent, /cancelled/);
  console.log('PASS: Unclear answers preserve the draft and an explicit negative cancels without sending');

  // A typed request that waits for a confirmation, with spoken replies off.
  const confirming = async () => {
    const c = voiceFixture({local: new Map([['cms_voice_replies', 'off']])});
    c.el('.vfab-btn').handlers.click();
    c.el('.vfab-input').value = 'Add a synthetic note';
    c.el('.vfab-send').handlers.click();
    c.reply({ok: true, action: 'confirm', do: 'create_note', params: {client_id: 3, text: 'Synthetic'}, speak: 'Save the note?'});
    await settle();
    assert.equal(c.el('.vfab-confirm').hidden, false);
    return c;
  };
  // A yes-word inside a question is not a yes: nothing is sent and the question stays.
  for (const answer of ["Sure, what's that for?", 'okay whats that']) {
    v = await confirming();
    v.el('.vfab-input').value = answer;
    v.el('.vfab-send').handlers.click();
    assert.equal(v.requests.length, 0);
    assert.equal(v.el('.vfab-confirm').hidden, false);
  }
  console.log('PASS: A question that contains a yes-word ("Sure, what\'s that for?") does not confirm and sends nothing');

  // Escape cancels a waiting confirmation; a later "okay ..." is a new request, not a yes.
  v = await confirming();
  v.el('.vfab-panel').handlers.keydown({key: 'Escape', preventDefault() {}});
  assert.equal(v.el('.vfab-confirm').hidden, true);
  assert.match(v.el('.vfab-status').textContent, /nothing was saved/);
  assert.equal(v.requests.length, 0);
  v.el('.vfab-btn').handlers.click();
  v.el('.vfab-input').value = 'okay show my tasks';
  v.el('.vfab-send').handlers.click();
  assert.equal(v.requests.length, 1);
  const afterEscape = JSON.parse(v.requests[0].init.body);
  assert.equal(afterEscape.do, undefined);
  assert.equal(afterEscape.transcript, 'okay show my tasks');
  console.log('PASS: Escape cancels a waiting confirmation, says nothing was saved, and a later "okay" is sent as a new request');

  // A confirmed save whose reply arrives after the panel was closed is still reported and kept.
  v = await confirming();
  v.el('.vfab-input').value = 'yes';
  v.el('.vfab-send').handlers.click();
  assert.equal(v.requests.length, 1);
  v.el('.vfab-close').handlers.click();
  v.reply({ok: true, speak: 'Saved the note.'});
  await settle();
  assert.equal(v.el('.vfab-status').textContent, 'Saved the note.');
  assert.match(v.storage.get('cms_convo_hist') || '', /Saved the note\./);
  // The status line is inside the closed panel, so a screen reader hears the result
  // from the polite live region outside it, written after a short pause.
  const live = v.pageLive();
  assert.ok(live, 'voice.js adds a role=status region outside the panel');
  assert.equal(live.className, 'visually-hidden');
  const announce = v.timers[v.timers.length - 1];
  assert.equal(announce.ms, 150);
  announce.fn();
  assert.equal(live.textContent, 'Voice assistant: Saved the note.');
  // The listening note beside the status line is seen, not announced.
  assert.equal(v.listenNote().attrs['aria-hidden'], 'true');
  console.log('PASS: Closing the panel after answering yes still reports and keeps the result of the save, announced outside the panel');

  // In a spoken conversation the question is read aloud, then the microphone
  // opens again for the answer; focus is not pulled onto the Yes button.
  v = voiceFixture({});
  v.el('.vfab-btn').handlers.click();
  v.el('.vfab-convo').handlers.click();
  assert.equal(v.mic(), 1);
  v.recognizer().onstart();
  v.recognizer().onresult({results: [[{transcript: 'Add a synthetic note'}]]});
  v.recognizer().onend();
  v.reply({ok: true, action: 'confirm', do: 'create_note', params: {}, speak: 'Save the note? Say yes to save.'});
  await settle();
  const question = v.utterances[v.utterances.length - 1];
  assert.equal(question.text, 'Save the note? Say yes to save.');
  question.onend();
  assert.equal(v.mic(), 2);
  assert.notEqual(v.focus(), '.vfab-yes');
  v.recognizer().onstart();
  v.recognizer().onresult({results: [[{transcript: 'yes'}]]});
  assert.equal(JSON.parse(v.requests[0].init.body).do, 'create_note');
  console.log('PASS: In conversation mode the microphone reopens after the spoken question and a spoken yes confirms');

  // Listen while a reply is being read starts the microphone.
  v = voiceFixture({});
  v.el('.vfab-btn').handlers.click();
  v.el('.vfab-input').value = 'help';
  v.el('.vfab-send').handlers.click();
  v.reply({ok: true, action: 'answer', speak: 'A long synthetic reply.'});
  await settle();
  assert.equal(v.mic(), 0);
  v.el('.vfab-listen').handlers.click();
  assert.equal(v.mic(), 1);
  console.log('PASS: Listen while speaking starts the microphone');

  // A host error page (508) keeps the conversation; a refused token (403) ends it.
  v = voiceFixture({});
  v.el('.vfab-btn').handlers.click();
  v.el('.vfab-input').value = 'Synthetic question';
  v.el('.vfab-send').handlers.click();
  v.reply('<html>508 Resource Limit</html>', 508);
  await settle();
  assert.ok(v.storage.has('cms_convo_hist'));
  assert.ok(v.utterances.some(u => /server had a problem/.test(u.text)));
  v.el('.vfab-input').value = 'Synthetic question';
  v.el('.vfab-send').handlers.click();
  v.reply('Invalid request token.', 403);
  await settle();
  // The refused token is checked against ping.php first; a session that has ended there ends the conversation.
  assert.equal(v.requests[0].url, 'ping.php');
  v.reply('expired', 401);
  await settle();
  assert.ok(v.utterances.some(u => /session has changed/.test(u.text)));
  console.log('PASS: A 508 error page keeps the conversation and a plain-text 403 ends it');

  // After signing in again in another tab, a plain-text 403 refuses this page's old
  // token: when the same account is signed in, its current token is taken from
  // ping.php and the request is sent once more, with the conversation kept.
  const refused = async () => {
    const w = voiceFixture({});
    w.el('.vfab-btn').handlers.click();
    w.el('.vfab-input').value = 'Synthetic question';
    w.el('.vfab-send').handlers.click();
    w.reply('Invalid request token.', 403);
    await settle();
    assert.equal(w.requests[0].url, 'ping.php');
    return w;
  };
  v = await refused();
  v.reply('ok', 200, {'X-CSRF-Token': 'fresh-csrf', 'X-User-Id': '7'});
  await settle();
  assert.equal(v.requests.length, 1);
  assert.equal(v.requests[0].url, 'ai_command.php');
  assert.equal(v.requests[0].init.headers['X-CSRF-Token'], 'fresh-csrf');
  assert.equal(JSON.parse(v.requests[0].init.body).transcript, 'Synthetic question');
  v.reply({ok: true, action: 'answer', speak: 'Answered after the refresh.'});
  await settle();
  assert.ok(v.utterances.some(u => u.text === 'Answered after the refresh.'));
  assert.ok(!v.utterances.some(u => /session has changed/.test(u.text)));
  assert.match(v.storage.get('cms_convo_hist') || '', /Synthetic question[\s\S]*Answered after the refresh\./);
  console.log('PASS: A refused token of the same account is refreshed from ping.php and the request is sent once more, keeping the conversation');
  // Another account signed in since: its token is never used and nothing is sent again.
  v = await refused();
  v.reply('ok', 200, {'X-CSRF-Token': 'other-csrf', 'X-User-Id': '9'});
  await settle();
  assert.equal(v.requests.length, 0);
  assert.ok(v.utterances.some(u => /session has changed/.test(u.text)));
  console.log('PASS: When another account has signed in, a refused token is not refreshed and the session is said to have changed');
  // A second refusal after the refresh is not retried again.
  v = await refused();
  v.reply('ok', 200, {'X-CSRF-Token': 'fresh-csrf', 'X-User-Id': '7'});
  await settle();
  v.reply('Invalid request token.', 403);
  await settle();
  assert.equal(v.requests.length, 0);
  assert.ok(v.utterances.some(u => /session has changed/.test(u.text)));
  console.log('PASS: A request refused again after the token refresh is sent only once more, then the session is said to have changed');

  // With sound cues muted, "Listening" is announced first and the microphone
  // starts only after the pause; with cues on, starting does not rewrite the status.
  v = voiceFixture({earcon: {muted: true, play() {}, volume: 'normal'}});
  v.el('.vfab-btn').handlers.click();
  v.el('.vfab-listen').handlers.click();
  assert.equal(v.mic(), 0);
  assert.match(v.el('.vfab-status').textContent, /Listening/);
  v.timers[v.timers.length - 1].fn();
  assert.equal(v.mic(), 1);
  v = voiceFixture({earcon: {muted: false, play() {}, volume: 'normal'}});
  v.el('.vfab-btn').handlers.click();
  const before = v.el('.vfab-status').textContent;
  v.el('.vfab-listen').handlers.click();
  v.recognizer().onstart();
  assert.equal(v.el('.vfab-status').textContent, before);
  v.recognizer().onend();
  assert.match(v.el('.vfab-status').textContent, /microphone is off/);
  console.log('PASS: With cues muted, Listening is announced and the microphone starts after the pause');

  // A confirmed save stays on this page: the result is the reply's log entry,
  // with the saved record's page offered as a named link inside that entry.
  // Every entry says who said it in visually-hidden text.
  v = voiceFixture({local: new Map([['cms_voice_replies', 'off']])});
  v.el('.vfab-btn').handlers.click();
  v.el('.vfab-input').value = 'Add a synthetic note';
  v.el('.vfab-send').handlers.click();
  v.reply({ok: true, action: 'confirm', do: 'create_note', params: {client_id: 3, text: 'Synthetic'}, speak: 'Save the note?'});
  await settle();
  v.el('.vfab-input').value = 'yes';
  v.el('.vfab-send').handlers.click();
  v.reply({ok: true, speak: 'Saved the synthetic note.', url: '/client.php?id=3#notes', url_label: "Go to the synthetic client's notes"});
  await settle();
  assert.equal(v.location.href, '');
  let log = v.el('.vfab-log').children;
  const savedEntry = log[log.length - 1];
  const savedLink = savedEntry.children[savedEntry.children.length - 1];
  assert.equal(savedEntry.children[1].textContent, 'Saved the synthetic note.');
  assert.equal(savedLink.tagName, 'a');
  assert.equal(savedLink.href, '/client.php?id=3#notes');
  assert.equal(savedLink.textContent, "Go to the synthetic client's notes");
  console.log('PASS: A confirmed save stays on the page and offers the record as a named link inside its reply');
  assert.ok(log.length >= 3);
  for (const entry of log) {
    assert.equal(entry.children[0].className, 'visually-hidden');
    assert.equal(entry.children[0].textContent, entry.className === 'vmsg u' ? 'You: ' : 'Assistant: ');
  }
  assert.equal(log[0].children[0].textContent, 'You: ');
  assert.equal(log[1].children[0].textContent, 'Assistant: ');
  console.log('PASS: Each conversation entry starts with a visually-hidden "You: " or "Assistant: "');

  // Someone already typing when the spoken question ends keeps their place;
  // the status line says what is waiting instead of moving focus to Yes.
  v = voiceFixture({});
  v.el('.vfab-btn').handlers.click();
  v.el('.vfab-input').value = 'Add a synthetic note';
  v.el('.vfab-send').handlers.click();
  v.reply({ok: true, action: 'confirm', do: 'create_note', params: {}, speak: 'Save the note?'});
  await settle();
  v.el('.vfab-input').value = 'I am still typ';
  v.document.activeElement = v.el('.vfab-input');
  const focusBefore = v.focus();
  v.utterances[v.utterances.length - 1].onend();
  assert.equal(v.focus(), focusBefore);
  assert.notEqual(v.focus(), '.vfab-yes');
  assert.match(v.el('.vfab-status').textContent, /^Waiting for your answer/);
  console.log('PASS: Typing when the question ends keeps focus and the status says an answer is waiting');

  // A signed-out session (401) offers a sign-in link that opens in a new tab.
  v = voiceFixture({local: new Map([['cms_voice_replies', 'off']])});
  v.el('.vfab-btn').handlers.click();
  v.el('.vfab-input').value = 'Synthetic question';
  v.el('.vfab-send').handlers.click();
  v.reply({ok: false, error: 'Signed out.'}, 401);
  await settle();
  log = v.el('.vfab-log').children;
  const signIn = log[log.length - 1].children[log[log.length - 1].children.length - 1];
  assert.equal(signIn.textContent, 'Sign in again (opens in a new tab)');
  assert.equal(signIn.target, '_blank');
  assert.equal(signIn.href, '/login.php');
  assert.equal(v.location.href, '');
  console.log('PASS: A 401 reply offers "Sign in again (opens in a new tab)" and does not leave the page');
})().catch(error => { console.error(error); process.exitCode = 1; });
