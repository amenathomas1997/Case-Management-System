// Run the actual language-toggle script on the referral form: answers saved when
// the language link is clicked come back on the reloaded page, once the form
// (which appears after the script) exists. The test drives the script's own save
// and restore, so it does not depend on how the answers are stored.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(__dirname + '/../public/inc/bootstrap.php', 'utf8');
const start = source.indexOf('<script>(function(){', source.indexOf('function lang_toggle()'));
const script = source.slice(start + '<script>'.length, source.indexOf('</script>', start));
const key = 'cms_lang_keep';
// The form as the server renders it: services are requested_services[] checkboxes.
const fields = [
  {name:'referral_purpose', value:'New referral', tagName:'SELECT', type:'select-one', options:[{value:'New referral'},{value:'Additional service'}]},
  {name:'requested_services[]', value:'Assessment', type:'checkbox', checked:false},
  {name:'requested_services[]', value:'Assistive technology training', type:'checkbox', checked:false},
  {name:'requested_services[]', value:'Demonstration', type:'checkbox', checked:false},
  {name:'specific_needs[]', value:'Screen readers', type:'checkbox', checked:false},
  {name:'specific_needs[]', value:'Braille devices', type:'checkbox', checked:false},
  {name:'body', value:'', tagName:'TEXTAREA', type:'textarea'},
];
fields.forEach(f => { f.dispatchEvent = () => true; });
const fresh = () => { fields[0].value = 'New referral'; fields[6].value = ''; fields.forEach(f => { if (f.type === 'checkbox') f.checked = false; }); };
let ready = false, restore, click;
const form = {querySelectorAll(selector) {
  if(selector==='input,select,textarea') return fields;
  if(selector==='input[type=radio]:checked')return [];
  const match=selector.match(/^\[name="(.*)"\]$/);return match ? fields.filter(x=>x.name===match[1]) : [];
}};
const storage = new Map();
const load = () => vm.runInNewContext(script, {
  document:{readyState:'loading',currentScript:{previousElementSibling:{querySelector:()=>({addEventListener:(event,fn)=>{click=fn;}})}},
    querySelector:()=>ready?form:null,querySelectorAll:()=>ready?[form]:[],
    addEventListener:(event,fn)=>{assert.equal(event,'DOMContentLoaded');restore=fn;}},
  sessionStorage:{getItem:k=>storage.get(k),setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},
  location:{pathname:'/community_referral.php'},window:{},Event:class {}
});
// The visitor fills in the form, then switches language.
load(); ready = true;
fields[0].value = 'Additional service'; fields[1].checked = true; fields[3].checked = true;
fields[4].checked = true; fields[5].checked = true; fields[6].value = 'Synthetic unsaved description';
click();
assert(storage.has(key), 'Clicking the language link saves the answers');
// The page reloads in the other language: the script runs before the form exists.
fresh(); ready = false; load();
assert(storage.has(key), 'Saved answers must not be consumed before the form exists');
ready = true; restore();
assert.equal(fields[0].value, 'Additional service');
assert(fields[1].checked && !fields[2].checked && fields[3].checked, 'Each requested service is restored as it was, and no other');
assert(fields[4].checked && fields[5].checked);
assert.equal(fields[6].value, 'Synthetic unsaved description');
assert(!storage.has(key));
console.log('PASS: language switching restores purpose, several requested services, multiple needs and description after form load');

// Every form on the page is saved and restored (keyed by form position and
// field name), including edits to prefilled fields and unticked boxes, while
// hidden and file inputs are never stored.
{
  function makeForm(fields) {
    return {querySelectorAll(selector) {
      if (selector === 'input,select,textarea') return fields;
      if (selector === 'input[type=radio]:checked') return fields.filter(x => x.type === 'radio' && x.checked);
      return [];
    }};
  }
  function sel(name, value, options) {
    return {name, value, tagName:'SELECT', type:'select-one', options:options.map(v => ({value:v})), dispatchEvent(){}};
  }
  function inp(name, value, type, extra) {
    return Object.assign({name, value, type, tagName: type === 'textarea' ? 'TEXTAREA' : 'INPUT', dispatchEvent(){}}, extra || {});
  }
  // Page as reloaded in the other language: server markup only.
  const practice = [inp('minutes', '', 'number'), inp('csrf', 'tok', 'hidden')];
  const referral = [
    sel('referral_purpose', '', ['', 'New referral', 'Additional service']),
    inp('specific_needs[]', 'Screen readers', 'checkbox', {checked:false}),
    inp('specific_needs[]', 'Braille devices', 'checkbox', {checked:false}),
    inp('body', '', 'textarea'),
  ];
  const info = [
    inp('p_phone', '555-0100', 'text'),
    inp('p_sr', '1', 'checkbox', {checked:true}),
    inp('document', '', 'file'),
  ];
  const forms = [makeForm(practice), makeForm(referral), makeForm(info)];
  let ready = false, restore, click;
  const saved = {p:'/portal.php', f:[
    {minutes:{c:[], v:['']}},
    {referral_purpose:{c:[], v:['Additional service']}, 'specific_needs[]':{c:['Screen readers', 'Braille devices'], v:[]}, body:{c:[], v:['Synthetic unsaved message']}},
    {p_phone:{c:[], v:['555-0199']}, p_sr:{c:[], v:[]}},
  ]};
  const storage = new Map([[key, JSON.stringify(saved)]]);
  vm.runInNewContext(script, {
    document:{readyState:'loading', currentScript:{previousElementSibling:{querySelector:() => ({addEventListener:(event, fn) => {click = fn;}})}},
      querySelectorAll:() => ready ? forms : [], addEventListener:(event, fn) => {assert.equal(event, 'DOMContentLoaded'); restore = fn;}},
    sessionStorage:{getItem:k => storage.get(k), setItem:(k, v) => storage.set(k, v), removeItem:k => storage.delete(k)},
    location:{pathname:'/portal.php'}, window:{}, Event:class {}
  });
  assert(storage.has(key), 'Saved answers must not be consumed before the forms exist');
  ready = true; restore();
  assert.equal(referral[0].value, 'Additional service');
  assert(referral[1].checked && referral[2].checked);
  assert.equal(referral[3].value, 'Synthetic unsaved message', 'a form after the first one is restored');
  assert.equal(info[0].value, '555-0199', 'an edited prefilled field keeps the edit');
  assert.equal(info[1].checked, false, 'an unticked box stays unticked');
  assert(!storage.has(key));
  click();
  const again = JSON.parse(storage.get(key));
  assert.equal(again.f.length, 3);
  assert.deepEqual(Object.keys(again.f[0]), ['minutes'], 'hidden fields are never stored');
  assert.deepEqual(Object.keys(again.f[2]).sort(), ['p_phone', 'p_sr'], 'file inputs are never stored');
  assert.deepEqual(again.f[1]['specific_needs[]'].c, ['Screen readers', 'Braille devices']);
  console.log('PASS: language switching saves and restores every form, keeping edits to prefilled fields and unticked boxes');
}
