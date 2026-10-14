// Run after full_schema.php with CMS_TEST_HTML_DIR set to a synthetic snapshot folder.
// Requires Playwright and installed Chrome; never connects to the application or internet.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const snapshots = process.env.CMS_TEST_HTML_DIR;
if (!snapshots) throw new Error('Set CMS_TEST_HTML_DIR to the synthetic integration snapshots.');
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const page = await browser.newPage();
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.hostname !== 'audit.invalid') return route.abort();
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
        await page.locator('#vision-size').selectOption(size);
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        const fits = await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1);
        if (!fits) problems.push(`Page overflows at ${width}px / ${size}% text`);
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
    await page.locator('#vision-size').selectOption('100');
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
    await page.locator('#vision-size').selectOption('200');
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
    assert.deepEqual(failures, []);
    console.log(`PASS: ${files.length} synthetic pages; labels, unique IDs, ARIA references; ${scenarios} reflow scenarios; skip link, keyboard table scrolling, menu state, six label palettes and print colors.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
