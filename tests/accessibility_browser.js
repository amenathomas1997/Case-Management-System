// Run after full_schema.php with CMS_TEST_HTML_DIR set to a synthetic snapshot folder.
// Requires Playwright and installed Chrome; never connects to the application or internet.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const snapshots = process.env.CMS_TEST_HTML_DIR;
if (!snapshots) throw new Error('Set CMS_TEST_HTML_DIR to the synthetic integration snapshots.');
// Text size waits for the choice to rest (owner rule 2); leaving the list applies it at once.
async function setTextSize(page, size) {
  const select = page.locator('#vision-size');
  await select.selectOption(size);
  await select.dispatchEvent('blur');
}
// Runs in the page: buttons whose words split across lines and lists narrower than their choice.
function squeezeProbe() {
  const out = [];
  const visible = e => e.getClientRects().length && getComputedStyle(e).visibility !== 'hidden' && !e.closest('[aria-hidden=true],dialog:not([open])');
  for (const b of document.querySelectorAll('button, .btn')) {
    if (!visible(b)) continue;
    const walker = document.createTreeWalker(b, NodeFilter.SHOW_TEXT);
    for (let n; (n = walker.nextNode());) {
      if (n.parentElement.closest('.visually-hidden')) continue;
      const words = /\S{2,}/g; let m;
      while ((m = words.exec(n.data))) {
        const r = document.createRange(); r.setStart(n, m.index); r.setEnd(n, m.index + m[0].length);
        const lines = new Set([...r.getClientRects()].filter(x => x.width > 0).map(x => Math.round(x.top)));
        if (lines.size > 1) out.push(`Button word split across lines: "${m[0]}" in "${b.textContent.trim().replace(/\s+/g, ' ').slice(0, 60)}"`);
      }
    }
  }
  const ctx = document.createElement('canvas').getContext('2d');
  for (const s of document.querySelectorAll('select')) {
    if (s.multiple || s.size > 1 || !visible(s)) continue;
    const o = s.options[s.selectedIndex];
    if (!o || !o.text.trim()) continue;
    const cs = getComputedStyle(s);
    ctx.font = `${cs.fontStyle} ${cs.fontWeight} ${cs.fontSize} ${cs.fontFamily}`;
    // The chosen text, the padding and borders, and the drop-down arrow.
    const need = ctx.measureText(o.text.trim()).width + parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight)
      + parseFloat(cs.borderLeftWidth) + parseFloat(cs.borderRightWidth) + (cs.appearance === 'none' ? 0 : 16);
    // A list as wide as the space it is in has nowhere to grow (a long choice on a phone);
    // one squeezed narrower than both its choice and that space has been crushed.
    const box = s.parentElement, bs = getComputedStyle(box);
    const room = box.clientWidth - parseFloat(bs.paddingLeft) - parseFloat(bs.paddingRight);
    const has = s.getBoundingClientRect().width;
    if (has + 2 < Math.min(need, room)) out.push(`List too narrow for its choice: #${s.id || s.name} is ${Math.round(has)}px for "${o.text.trim().slice(0, 40)}" (${Math.round(need)}px)`);
  }
  return out;
}
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const page = await browser.newPage();
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.hostname !== 'audit.invalid') return route.abort();
      // A save that returns to a part of the page: /__redirect/page.html?to=id loads a fresh
      // /page.html#id, as following redirect('…#id') does. (Chrome does not follow a 303
      // given by an intercepted request, so the step is made by location.replace.)
      const back = url.pathname.match(/^\/__redirect\/([a-z0-9_-]+\.html)$/);
      if (back) return route.fulfill({ contentType: 'text/html', body: '<!doctype html><script>location.replace(' + JSON.stringify('/' + back[1] + '#' + (url.searchParams.get('to') || '')) + ')</script>' });
      const asset = url.pathname.match(/^\/assets\/([a-z0-9-]+\.(?:css|js))$/);
      const snapshot = url.pathname.match(/^\/([a-z0-9_-]+\.html)$/);
      const file = asset ? path.join(root, 'public/assets', asset[1]) : snapshot ? path.join(snapshots, snapshot[1]) : null;
      if (!file || !fs.existsSync(file)) return route.abort();
      return route.fulfill({ path: file, contentType: file.endsWith('.html') ? 'text/html' : file.endsWith('.css') ? 'text/css' : 'application/javascript' });
    });
    const files = fs.readdirSync(snapshots).filter(name => name.endsWith('.html'));
    assert.ok(files.length >= 60, 'Expected the integration page snapshots, not an empty scan.');
    let scenarios = 0;
    const failures = [];
    for (const file of files) {
      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto('https://audit.invalid/' + file);
      await page.locator('.vision-panel > summary').click();
      await page.locator('.vision-options > summary').click();
      const problems = await page.evaluate(() => {
        const visible = e => !!e.getClientRects().length && getComputedStyle(e).visibility !== 'hidden';
        const out = [], ids = [...document.querySelectorAll('[id]')].map(e => e.id);
        for (const id of new Set(ids)) if (ids.filter(i => i === id).length > 1) out.push('Duplicate ID: ' + id);
        for (const e of document.querySelectorAll('input:not([type=hidden]),select,textarea,button')) {
          if (!visible(e) || e.closest('[aria-hidden=true]')) continue;
          const name = e.getAttribute('aria-label') || (e.getAttribute('aria-labelledby') || '').split(/\s+/).map(id => document.getElementById(id)?.textContent || '').join(' ').trim()
            || [...(e.labels || [])].map(l => l.textContent).join('').trim() || (e.tagName === 'BUTTON' ? e.textContent.trim() : '')
            || (['submit', 'button', 'reset'].includes(e.type) ? e.value : '') || e.title;
          if (!name) out.push('Unlabelled control: ' + e.outerHTML.slice(0,180));
        }
        for (const e of document.querySelectorAll('[aria-describedby],[aria-labelledby]')) {
          for (const attr of ['aria-describedby','aria-labelledby']) {
            for (const id of (e.getAttribute(attr) || '').split(/\s+/).filter(Boolean)) {
              if (!document.getElementById(id)) out.push('Missing ' + attr + ' target: ' + id);
            }
          }
        }
        return out;
      });
      for (const width of [320, 1280]) for (const size of ['100', '200']) {
        await page.setViewportSize({ width, height: 900 });
        await setTextSize(page, size);
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        const fits = await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1);
        if (!fits) problems.push(`Page overflows at ${width}px / ${size}% text`);
        // Controls never squeeze (1.4.10, 1.4.4): no word inside a button is split across
        // lines, and no list is narrower than the choice it shows.
        if (size === '100') problems.push(...(await page.evaluate(squeezeProbe)).map(p => `${p} at ${width}px`));
        scenarios++;
      }
      failures.push(...problems.map(problem => file + ': ' + problem));
      if ((files.indexOf(file) + 1) % 10 === 0) console.log('Checked ' + (files.indexOf(file) + 1) + ' pages');
    }
    // Check actual keyboard behavior on the rendered referral form, including its
    // horizontally scrolling table. The browser handles focus and scrolling here.
    await page.goto('https://audit.invalid/referrals.html');
    await page.locator('.vision-panel > summary').click();
      await page.locator('.vision-options > summary').click();
    await page.setViewportSize({ width: 320, height: 900 });
    await setTextSize(page, '100');
    await page.keyboard.press('Tab');
    // Focus starts on a display preference after using the picker, so explicitly
    // focus the skip link before activating its real keyboard behavior.
    await page.locator('.skip-link').focus();
    await page.keyboard.press('Enter');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'main', 'Skip link focuses main content');
    const region = page.locator('.table-scroll').first();
    await region.focus();
    assert.equal(await region.getAttribute('tabindex'), '0', 'Overflowing table is keyboard focusable');
    await page.keyboard.press('ArrowRight');
    await page.waitForFunction(() => document.querySelector('.table-scroll').scrollLeft > 0);
    await page.locator('#nav-toggle').click();
    assert.equal(await page.locator('#nav-toggle').getAttribute('aria-expanded'), 'true');
    await page.locator('#nav-toggle').click();
    assert.equal(await page.locator('#nav-toggle').getAttribute('aria-expanded'), 'false');
    await page.goto('https://audit.invalid/equipment_labels.html');
    await page.locator('.vision-panel > summary').click();
      await page.locator('.vision-options > summary').click();
    await setTextSize(page, '200');
    for (const palette of ['light', 'dark', 'black', 'white', 'yellow', 'cream']) {
      await page.locator(`[data-palette="${palette}"]`).click();
      const ratio = await page.locator('.label-text a').first().evaluate(link => {
        const channels = color => color.match(/[\d.]+/g).slice(0, 3).map(Number);
        const luminance = color => channels(color).map(c => c / 255).map(c => c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4).reduce((n, c, i) => n + c * [.2126, .7152, .0722][i], 0);
        let surface = link;
        while (surface.parentElement && getComputedStyle(surface).backgroundColor === 'rgba(0, 0, 0, 0)') surface = surface.parentElement;
        const ink = luminance(getComputedStyle(link).color), bg = luminance(getComputedStyle(surface).backgroundColor);
        return (Math.max(ink, bg) + .05) / (Math.min(ink, bg) + .05);
      });
      assert.ok(ratio >= 4.5, `Equipment link contrast in ${palette}: ${ratio}`);
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Enlarged labels fit in ' + palette);
    }
    await page.locator('.label-sheet').screenshot({ path: path.join(snapshots, 'equipment-labels-200.png') });
    await page.emulateMedia({ media: 'print' });
    assert.equal(await page.locator('.label').first().evaluate(e => getComputedStyle(e).backgroundColor), 'rgb(255, 255, 255)', 'Print labels retain a white surface');
    assert.equal(await page.locator('.label-text a').first().evaluate(e => getComputedStyle(e).color), 'rgb(0, 0, 0)', 'Print label links retain black text');
    await page.emulateMedia({ media: 'screen' });
    assert.deepEqual(failures, []);
    console.log(`PASS: ${files.length} synthetic pages; labels, unique IDs, ARIA references; ${scenarios} reflow scenarios with no split button words or clipped lists; skip link, keyboard table scrolling, menu state, six label palettes and print colors.`);
    await page.setViewportSize({ width: 1280, height: 900 });

    // A1: after a save that redirects to a #fragment, the result message or error summary
    // has focus once the page has loaded (the browser's own fragment scroll used to take
    // it away), and it was focused only once, so it is read once.
    const read = name => fs.readFileSync(path.join(snapshots, name), 'utf8');
    const message = f => read(f).includes('id="flash-messages"') || (read(f).includes('id="form-error-summary"') && read(f).includes('cmsFocusAfterLoad'));
    const withFlash = files.filter(f => read(f).includes('id="flash-messages"')).slice(0, 4);
    const withSummary = files.filter(f => read(f).includes('id="form-error-summary"') && read(f).includes('cmsFocusAfterLoad(f)')).slice(0, 4);
    // Pages whose fragment target the browser focuses itself (a tabindex=-1 heading, a
    // schedule row header th#s-N): the message must still keep focus.
    const withFocusable = files.filter(f => message(f) && /<th[^>]* id="s-\d+"|<h[2-4][^>]*tabindex="-1"|<h[2-4][^>]* id="[^"]+"[^>]*tabindex="-1"/.test(read(f))).slice(0, 6);
    assert.ok(withFlash.length && withSummary.length, 'Expected snapshots with a result message and with an error summary');
    await page.addInitScript(() => {
      window.__focusLog = [];
      document.addEventListener('focusin', e => window.__focusLog.push(e.target.id || e.target.tagName), true);
    });
    let redirects = 0, focusableTargets = 0;
    const jobs = [];
    for (const file of [...new Set([...withFlash, ...withSummary, ...withFocusable])]) {
      await page.goto('https://audit.invalid/' + file);
      // Targets anywhere in main outside the message: one the browser focuses itself, and a plain one.
      const picked = await page.evaluate(() => {
        const main = document.getElementById('main'), msg = document.getElementById('form-error-summary') || document.getElementById('flash-messages');
        const all = [...main.querySelectorAll('h2[id],h3[id],section[id],summary[id],table[id],form[id],th[id],[id][tabindex="-1"]')].filter(e => e !== msg && !msg.contains(e)
          && !e.closest('[hidden],details:not([open]),.visually-hidden,[role=status],[role=alertdialog]') && e.getClientRects().length);
        const focusable = all.find(e => (e.tabIndex === -1 && e.hasAttribute('tabindex')) || e.tagName === 'SUMMARY');
        const plain = all.find(e => /^H[2-4]$/.test(e.tagName) && !e.hasAttribute('tabindex')) || all[all.length - 1];
        return [focusable && focusable.id, plain && plain.id].filter(Boolean);
      });
      for (const target of [...new Set(picked)]) jobs.push([file, target]);
    }
    for (const [file, target] of jobs) {
      await page.goto('https://audit.invalid/__redirect/' + file + '?to=' + encodeURIComponent(target));
      await page.waitForURL(u => u.pathname === '/' + file && u.hash === '#' + target, { waitUntil: 'load' });
      await page.waitForTimeout(250);
      const state = await page.evaluate(() => ({ active: document.activeElement && document.activeElement.id, hash: location.hash, log: window.__focusLog,
        expected: document.getElementById('form-error-summary') ? 'form-error-summary' : 'flash-messages' }));
      assert.equal(state.hash, '#' + target, file + ': the redirect kept its fragment');
      assert.equal(state.active, state.expected, `${file}: after the redirect to #${target}, focus is on the ${state.expected} (focus went to: ${state.log.join(', ')})`);
      assert.equal(state.log.filter(id => id === state.expected).length, 1, `${file}: the message is focused once (${state.log.join(', ')})`);
      redirects++;
      if (state.log[0] === target) focusableTargets++;
    }
    assert.ok(redirects >= 2, 'Checked redirects with a fragment');
    assert.ok(focusableTargets >= 2, `Checked redirects whose target the browser focuses itself (${focusableTargets})`);

    // Owner rule 2: Text size is applied and announced once, when the choice rests.
    await page.goto('https://audit.invalid/' + files[0]);
    await page.locator('.vision-panel > summary').click();
    await page.locator('.vision-options > summary').click();
    await setTextSize(page, '100');
    await page.waitForTimeout(100);
    await page.evaluate(() => { window.__said = []; new MutationObserver(() => { const t = document.getElementById('vision-live').textContent; if (t) window.__said.push(t); }).observe(document.getElementById('vision-live'), { childList: true, characterData: true, subtree: true }); });
    await page.locator('#vision-size').selectOption('125');
    await page.locator('#vision-size').selectOption('150');
    assert.equal(await page.evaluate(() => document.documentElement.getAttribute('data-vision-size')), '100', 'Text size is not applied on each change');
    await page.waitForTimeout(900);
    const said = await page.evaluate(() => ({ size: document.documentElement.getAttribute('data-vision-size'), said: window.__said }));
    assert.equal(said.size, '150', 'Text size is applied once the choice rests');
    assert.equal(said.said.length, 1, 'Text size is announced once: ' + said.said.join(' | '));
    assert.ok(/150/.test(said.said[0]));

    // A2: the unsaved-changes dialog. Clicks and sends are stopped at the window, after the
    // page's own handlers, so the snapshot stays loaded.
    const spanish = files.filter(f => /<html lang="es"/.test(read(f)) && read(f).includes('class="lang-toggle"') && /<main[\s\S]*<form method="post"[\s\S]*<input type="text"/.test(read(f)));
    assert.ok(spanish.length, 'Expected a Spanish form snapshot with the language switch');
    await page.goto('https://audit.invalid/' + spanish[0]);
    await page.evaluate(() => { window.addEventListener('click', e => e.preventDefault()); window.addEventListener('submit', e => e.preventDefault()); });
    const field = page.locator('main form[method="post"] input[type="text"]:visible').first();
    await field.fill('V430-SHARED typed');
    await page.locator('.lang-toggle a').click();
    assert.equal(await page.evaluate(() => document.querySelector('dialog.unsaved-dialog').open), false, 'The language switch, which keeps every field, does not ask about unsaved changes');
    // A chosen file cannot be carried across the switch, so then it does ask.
    const fileInput = page.locator('main form[method="post"] input[type="file"]').first();
    if (await fileInput.count()) {
      await fileInput.setInputFiles({ name: 'v430-shared.txt', mimeType: 'text/plain', buffer: Buffer.from('synthetic') });
      await page.locator('.lang-toggle a').click();
      assert.equal(await page.evaluate(() => document.querySelector('dialog.unsaved-dialog').open), true, 'The language switch asks when a chosen file would be lost');
      await page.keyboard.press('Escape');
      await fileInput.setInputFiles([]);
    }
    await page.evaluate(() => { const a = document.createElement('a'); a.href = 'privacy.php'; a.id = 'v430-away'; a.textContent = 'away'; document.getElementById('main').appendChild(a); });
    await page.locator('#v430-away').click();
    const dialogState = await page.evaluate(() => { const d = document.querySelector('dialog.unsaved-dialog'); return { open: d.open, lang: d.lang, text: d.innerText, focused: document.activeElement.textContent }; });
    assert.ok(dialogState.open && dialogState.lang === 'es' && /Cambios sin guardar/.test(dialogState.text) && /Salir sin guardar/.test(dialogState.text) && dialogState.focused === 'Seguir editando',
      'On a Spanish page the unsaved-changes dialog is Spanish: ' + JSON.stringify(dialogState));
    assert.ok(!/Unsaved|Save the form|Keep editing/.test(dialogState.text), 'No English is left in the Spanish dialog');
    await page.keyboard.press('Escape');
    // Sending a message after only picking a time in another form does not ask.
    const booking = files.filter(f => read(f).includes('name="slot"') && /<textarea/.test(read(f)));
    if (booking.length) {
      await page.goto('https://audit.invalid/' + booking[0]);
      await page.evaluate(() => { window.addEventListener('click', e => e.preventDefault()); window.addEventListener('submit', e => e.preventDefault()); });
      const slot = page.locator('select[name="slot"]').first();
      const values = await slot.evaluate(s => [...s.options].map(o => o.value).filter(Boolean));
      if (values.length) await slot.selectOption(values[0]);
      const sent = await page.evaluate(() => {
        const box = [...document.querySelectorAll('main form[method="post"] textarea')].find(t => !t.closest('form').querySelector('[name="slot"]'));
        if (!box) return null;
        box.value = 'V430-SHARED message'; box.dispatchEvent(new Event('input', { bubbles: true }));
        const form = box.closest('form'); form.requestSubmit();
        return document.querySelector('dialog.unsaved-dialog').open;
      });
      if (sent !== null) assert.equal(sent, false, 'Sending a message after choosing a time in the booking list does not ask about unsaved changes');
    }
    console.log(`PASS: ${redirects} redirects with a #fragment (${focusableTargets} to a target the browser focuses) leave focus on the message, focused once; Text size applies and speaks once; the language switch asks only when it would lose a chosen file; the unsaved-changes dialog is Spanish on Spanish pages; a send after a list choice does not ask.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
