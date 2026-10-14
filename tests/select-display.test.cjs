const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
let enabled = false, update;
function control(size, count, selectedIndex, multiple = false) {
  return {attrs: size === null ? {} : {size: String(size)}, options: Array(count).fill({}), selectedIndex, multiple, isConnected:true,
    get size(){return Number(this.attrs.size || 0);},set size(v){this.attrs.size=String(v);},
    getAttribute(n){return this.attrs[n] ?? null;},setAttribute(n,v){this.attrs[n]=v;},removeAttribute(n){delete this.attrs[n];}};
}
const plain=control(null,10,4), explicit=control(1,2,-1), list=control(8,12,3), multi=control(4,8,2,true);
const controls=[plain,explicit,list,multi];
const root={getAttribute(){return String(enabled);}};
class Observer {constructor(callback){update=callback;}observe(){}}
vm.runInNewContext(fs.readFileSync(__dirname+'/../public/assets/select-display.js','utf8'), {
  document:{documentElement:root,querySelectorAll(){return controls;}},window:{MutationObserver:Observer},MutationObserver:Observer,Map,Math
});
assert.equal(plain.size,0);
enabled=true;update();
assert.equal(plain.size,6);assert.equal(plain.selectedIndex,4);
assert.equal(explicit.size,2);assert.equal(explicit.selectedIndex,-1);
assert.equal(list.size,8);assert.equal(multi.size,4);
plain.selectedIndex=7;update();assert.equal(plain.selectedIndex,7);
const added=control(null,3,1);controls.push(added);update();assert.equal(added.size,3);assert.equal(added.selectedIndex,1);
plain.options=[];plain.selectedIndex=-1;update();assert.equal(plain.size,2);assert.equal(plain.selectedIndex,-1);
enabled=false;update();
assert.equal(plain.getAttribute('size'),null);assert.equal(explicit.getAttribute('size'),'1');assert.equal(added.getAttribute('size'),null);
assert.equal(plain.selectedIndex,-1);assert.equal(explicit.selectedIndex,-1);assert.equal(added.selectedIndex,1);
console.log('PASS: Expanded dropdowns preserve choices, empty selections, dynamic controls and existing listboxes; disabling restores original sizes.');
