const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(__dirname + '/../public/assets/navigation.js', 'utf8');
function fixture(restored = false) {
  const events = {}, win = {}, controls = {};
  const field = {name: 'body', type: 'textarea', value: 'Original', disabled: false};
  const button = {name: 'add_note', type: 'submit', disabled: false};
  let submitted = 0, visited = '', focused = null;
  const form = {elements: [field, button], querySelector: () => button, requestSubmit: b => {assert.equal(b, button); submitted++;}};
  const other = {requestSubmit: () => { submitted++; }};
  const menu = {classList: {add(){}}, focus() {focused = 'menu';}};
  const dialog = {open: false, setAttribute(){}, showModal(){this.open=true;}, close(){this.open=false;},
    addEventListener(n,f){events['dialog:'+n]=f;}, querySelector(s){return controls[s] ||= {hidden:false, addEventListener(n,f){events[s+':'+n]=f;},focus(){focused=s;}};}};
  const doc = {activeElement: {focus(){focused='origin';}}, referrer: '',
    querySelectorAll(s){return s.startsWith('main form') ? [form] : [];},
    getElementById(id){return id==='main-nav'?menu:null;},
    createElement(){return dialog;}, body:{appendChild(){}},addEventListener(n,f){events[n]=f;}};
  const loc = {origin:'https://example.test',href:'https://example.test/client.php?id=1',pathname:'/client.php',search:'?id=1',hash:'',assign(u){visited=u;}};
  const window = {cmsRestoredFields: restored ? ['add_note'] : [],addEventListener(n,f){win[n]=f;},confirm(){return false;}};
  vm.runInNewContext(source,{document:doc,window,location:loc,Map,Set,Array,JSON,URL,Promise});
  function event(target) { return {target,button:0,defaultPrevented:false,preventDefault(){this.defaultPrevented=true;}}; }
  function link(href,attrs=[]) {const a={href,target:'',hasAttribute:n=>attrs.includes(n)};return {closest:()=>a};}
  return {events,win,controls,field,button,form,other,dialog,event,link,visited:()=>visited,submitted:()=>submitted,focus:()=>focused};
}
(async()=>{
  let f=fixture();
  let e=f.event({});f.win.beforeunload(e);assert.equal(e.defaultPrevented,false);
  f.field.value='Changed';e=f.event({});f.win.beforeunload(e);assert.equal(e.defaultPrevented,true);
  f.field.value='Original';e=f.event({});f.win.beforeunload(e);assert.equal(e.defaultPrevented,false);
  f.field.value='Changed';e=f.event(f.link('https://example.test/index.php'));f.events.click(e);
  assert.equal(e.defaultPrevented,true);assert.equal(f.dialog.open,true);assert.equal(f.visited(),'');assert.equal(f.focus(),'[data-stay]');
  f.events['[data-stay]:click']();assert.equal(f.dialog.open,false);assert.equal(f.field.value,'Changed');assert.equal(f.focus(),'origin');
  f.events.click(f.event(f.link('https://example.test/index.php')));f.events['[data-save]:click']();
  assert.equal(f.submitted(),1);assert.equal(f.visited(),'');assert.equal(f.field.value,'Changed');
  f.events.click(f.event(f.link('https://example.test/index.php')));f.events['[data-leave]:click']();
  assert.equal(f.visited(),'https://example.test/index.php');
  f.win.pageshow();e=f.event({});f.win.beforeunload(e);assert.equal(e.defaultPrevented,true);
  f=fixture();f.form.querySelector=()=>null;f.button.textContent='Save reviewed changes';f.field.value='Edited';f.events.click(f.event(f.link('https://example.test/index.php')));assert.equal(f.controls['[data-save]'].hidden,false);f.events['[data-save]:click']();assert.equal(f.submitted(),1);
  f=fixture();f.form.querySelector=()=>null;f.button.textContent='Send invitation';f.field.value='Edited';f.events.click(f.event(f.link('https://example.test/index.php')));assert.equal(f.controls['[data-save]'].hidden,true);
  f=fixture(true);e=f.event({});f.win.beforeunload(e);assert.equal(e.defaultPrevented,true);
  f=fixture();f.field.value='Changed';e=f.event(f.form);f.events.submit(e);f.win.beforeunload(f.event({}));
  assert.equal(e.defaultPrevented,false);
  e.defaultPrevented=true;await Promise.resolve();let unload=f.event({});f.win.beforeunload(unload);assert.equal(unload.defaultPrevented,true);
  f=fixture();f.field.value='Changed';e=f.event(f.other);f.events.submit(e);assert.equal(e.defaultPrevented,true);assert.equal(f.dialog.open,true);
  f.events['[data-leave]:click']();unload=f.event({});f.win.beforeunload(unload);assert.equal(unload.defaultPrevented,true);
  f.events.click(f.event(f.link('https://example.test/index.php')));
  f.events['dialog:cancel'](f.event({}));assert.equal(f.dialog.open,false);assert.equal(f.field.value,'Changed');
  f.events.click(f.event(f.link('https://example.test/client.php?id=1#main-nav',['data-cms-menu'])));assert.equal(f.focus(),'menu');assert.equal(f.dialog.open,false);
  console.log('PASS: Unsaved edits, restored drafts, save, stay, discard, session refusals, other-form submits, browser exits and navigation focus');
})().catch(e=>{console.error(e);process.exitCode=1;});
