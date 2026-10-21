const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const code = fs.readFileSync(__dirname+'/../public/assets/low-vision.js','utf8');
function fixture(saved, blocked=false, legacy={}, accountHidden=false) {
  const attrs={}, events={}, store=new Map(Object.entries(legacy)); let osChange;
  if(saved!==undefined) store.set('cms_low_vision',saved);
  function element(dataset={}) { return {dataset,attrs:{},events:{},value:'',setAttribute(k,v){this.attrs[k]=v;},addEventListener(k,f){this.events[k]=f;}}; }
  const palettes=['auto','light','dark','black','white','yellow','cream'].map(p=>element({palette:p}));
  const toggles=['spacing','width','links','motion','lists'].map(k=>element({visionToggle:k}));
  const size=element(), reset=element(), live=element();
  const bar={hidden:true,dataset:{saved:'Display updated.',accountHidden:String(accountHidden)},querySelectorAll:s=>s==='[data-palette]'?palettes:toggles};
  const mq={matches:false,addEventListener(_,f){osChange=f;}};
  vm.runInNewContext(code,{window:{matchMedia:()=>mq},document:{documentElement:{setAttribute(k,v){attrs[k]=v;}},addEventListener(k,f){events[k]=f;},getElementById:id=>({'low-vision-bar':bar,'vision-live':live,'vision-size':size,'vision-reset':reset}[id])},localStorage:{get length(){if(blocked)throw Error();return store.size;},key(i){return [...store.keys()][i]??null;},getItem(k){if(blocked)throw Error();return store.get(k)||null;},setItem(k,v){if(blocked)throw Error();store.set(k,v);}}});
  events.DOMContentLoaded();
  return {attrs,store,bar,palettes,toggles,size,reset,live,mq,os:()=>osChange()};
}
let f=fixture();
assert.equal(f.bar.hidden,false); assert.equal(f.attrs['data-theme'],'light');
f.mq.matches=true;f.os(); assert.equal(f.attrs['data-theme'],'dark');
f.palettes.find(b=>b.dataset.palette==='yellow').events.click();
assert.equal(f.attrs['data-vision-palette'],'yellow');
assert.equal(f.palettes.filter(b=>b.attrs['aria-pressed']==='true').length,1);
f.size.value='200';f.size.events.change();f.toggles[0].events.click();
let restored=fixture(f.store.get('cms_low_vision'));
assert.equal(restored.attrs['data-vision-size'],'200');assert.equal(restored.attrs['data-vision-spacing'],'true');
console.log('PASS: Presets, OS theme changes, selected states, text size and spacing persist');
f.reset.events.click();assert.equal(f.attrs['data-vision-size'],'100');assert.equal(f.attrs['data-vision-spacing'],'false');assert.equal(f.attrs['data-vision-palette'],'auto');
console.log('PASS: Reset restores every default without reloading');
f=fixture('{broken');assert.equal(f.attrs['data-vision-size'],'100');
f=fixture(JSON.stringify({palette:'bad',size:'9999',spacing:'false'}));assert.equal(f.attrs['data-vision-size'],'100');assert.equal(f.attrs['data-vision-palette'],'auto');assert.equal(f.attrs['data-vision-spacing'],'false');
console.log('PASS: Corrupt and invalid saved preferences fall back safely');
f=fixture(undefined,true);f.toggles[2].events.click();assert.equal(f.attrs['data-vision-links'],'true');
console.log('PASS: Toolbar remains usable when browser storage is blocked');
f=fixture(undefined,false,{old_theme:'dark',old_textsize:'large'});assert.equal(f.attrs['data-theme'],'dark');assert.equal(f.attrs['data-vision-size'],'125');
console.log('PASS: Existing theme and larger-text preferences migrate');
f=fixture(undefined,false,{old_low_vision:JSON.stringify({size:'175',palette:'cream'})});assert.equal(f.attrs['data-vision-size'],'175');assert.equal(f.attrs['data-vision-palette'],'cream');
console.log('PASS: Preferences saved under an earlier prefix carry over');

f=fixture(JSON.stringify({size:'150',palette:'yellow'}),false,{},true);
assert.equal(f.bar.hidden,true);assert.equal(f.attrs['data-vision-size'],'150');assert.equal(f.attrs['data-vision-palette'],'yellow');
f.reset.events.click();assert.equal(f.bar.hidden,true);
console.log('PASS: Account visibility hides the toolbar without disabling preferences or allowing reset to override it');
