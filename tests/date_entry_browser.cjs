// Synthetic browser-only test; never connects to production or submits client data.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const path=require('node:path');
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try {
  const page=await browser.newPage();
  await page.setContent('<form novalidate><label for="date">Session date</label><input id="date" name="date" type="date" required min="2026-01-01" max="2026-12-31" value="2026-10-14"><label for="time">Start time</label><input id="time" type="time" value="14:30"><label for="stamp">Appointment</label><input id="stamp" type="datetime-local" value="2026-10-14T14:30"><button>Save</button></form>');
  await page.addScriptTag({path:path.resolve(__dirname,'../public/assets/date-entry.js')});
  await page.evaluate(()=>{window.saved=0;document.addEventListener('submit',e=>{if(!e.defaultPrevented)window.saved++;e.preventDefault();});});
  await page.getByRole('button',{name:'Type date instead: Session date',exact:true}).click();
  assert.equal(await page.locator('#date').getAttribute('type'),'text');assert.equal(await page.locator('#date').inputValue(),'2026-10-14');
  await page.locator('#date').fill('2026-02-30');await page.getByRole('button',{name:'Save',exact:true}).click();
  assert.equal(await page.evaluate(()=>window.saved),0);assert.equal(await page.locator('#date').getAttribute('aria-invalid'),'true');assert.equal(await page.evaluate(()=>document.activeElement.id),'date');
  await page.getByRole('button',{name:'Use date picker instead: Session date',exact:true}).click();
  assert.equal(await page.locator('#date').inputValue(),'2026-02-30');assert.equal(await page.locator('#date').getAttribute('type'),'text');
  for(const bad of ['','2027-01-01','10/14/2026']){await page.locator('#date').fill(bad);await page.getByRole('button',{name:'Save',exact:true}).click();assert.equal(await page.evaluate(()=>window.saved),0);}
  await page.locator('#date').fill('2026-11-02');await page.getByRole('button',{name:'Save',exact:true}).click();assert.equal(await page.evaluate(()=>window.saved),1);
  await page.getByRole('button',{name:'Use date picker instead: Session date',exact:true}).click();assert.equal(await page.locator('#date').inputValue(),'2026-11-02');assert.equal(await page.locator('#date').getAttribute('type'),'date');
  await page.getByRole('button',{name:'Type time instead: Start time',exact:true}).click();await page.locator('#time').fill('25:00');await page.getByRole('button',{name:'Save',exact:true}).click();assert.equal(await page.evaluate(()=>window.saved),1);
  await page.locator('#time').fill('09:15');await page.getByRole('button',{name:'Type date and time instead: Appointment',exact:true}).click();assert.equal(await page.locator('#stamp').inputValue(),'2026-10-14T14:30');
  await page.locator('#stamp').fill('2026-10-15T09:15');await page.getByRole('button',{name:'Save',exact:true}).click();assert.equal(await page.evaluate(()=>window.saved),2);
  console.log('PASS: Real browser date/time text entry, labels, focus, required/range/calendar validation, preservation and picker restoration.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
