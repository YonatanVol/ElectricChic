// Page audit: screenshots, console errors, overflow, touch targets, badge/card parity,
// and a frame-gap measurement while the pointer sweeps the page.
//   npm run audit:pages -- <baseUrl> <outDir> [pages...]
// Needs Playwright: npm install (once), which also fetches Chromium.
import { chromium } from 'playwright';
import fs from 'node:fs';

const [,, base = 'http://localhost:8080', out = './build/audit', ...pagesArg] = process.argv;
const pages = pagesArg.length ? pagesArg : ['/', '/shop/', '/product/cortez-xmax-48/', '/product/cortez-10x/', '/cart/', '/checkout/', '/my-account/', '/?s=max&post_type=product', '/nothing-here/'];
fs.mkdirSync(out, { recursive: true });

const browser = await chromium.launch();
const results = [];

for (const vp of [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }]) {
  const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, isMobile: !!vp.isMobile, hasTouch: !!vp.hasTouch, deviceScaleFactor: vp.deviceScaleFactor || 1, locale: 'he-IL' });
  for (const path of pages) {
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', e => errors.push('pageerror: ' + e.message));
    const t0 = Date.now();
    await page.goto(base + path, { waitUntil: 'load' });
    const loadMs = Date.now() - t0;
    await page.evaluate(async () => { const h = document.documentElement.scrollHeight; for (let y = 0; y < h; y += 500) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 60)); } window.scrollTo(0, 0); await new Promise(r => setTimeout(r, 700)); });
    const slug = (vp.name + path).replace(/[^a-z0-9]+/gi, '_');
    await page.screenshot({ path: `${out}/${slug}.png`, fullPage: true });

    const metrics = await page.evaluate(() => {
      const de = document.documentElement;
      const overflow = de.scrollWidth - de.clientWidth;
      const small = [];
      for (const el of document.querySelectorAll('a, button, input, select, [role=button]')) {
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) continue;
        const cs = getComputedStyle(el);
        if (cs.visibility === 'hidden' || cs.display === 'none') continue;
        if (r.height < 24 || r.width < 24) small.push({ tag: el.tagName, cls: el.className?.toString().slice(0, 60), text: (el.textContent || '').trim().slice(0, 30), w: Math.round(r.width), h: Math.round(r.height) });
      }
      const cards = document.querySelectorAll('li.wc-block-product').length;
      const badges = document.querySelectorAll('li.wc-block-product .ec-avail').length;
      return { overflow, small: small.slice(0, 12), smallCount: small.length, cards, badges, title: document.title };
    });

    // Frame gaps during a pointer sweep across the page.
    const gaps = await page.evaluate(async () => {
      const frames = [];
      let last = performance.now();
      let stop = false;
      const tick = (t) => { frames.push(t - last); last = t; if (!stop) requestAnimationFrame(tick); };
      requestAnimationFrame(tick);
      await new Promise(r => setTimeout(r, 900));
      stop = true;
      frames.shift();
      const max = Math.max(...frames);
      const over = frames.filter(f => f > 34).length;
      return { frames: frames.length, max: Math.round(max), over34: over };
    });
    // Sweep pointer while the measurement above is NOT running is useless; so measure again with motion.
    const sweep = page.evaluate(async () => {
      const frames = [];
      let last = performance.now();
      let stop = false;
      const tick = (t) => { frames.push(t - last); last = t; if (!stop) requestAnimationFrame(tick); };
      requestAnimationFrame(tick);
      await new Promise(r => setTimeout(r, 1200));
      stop = true;
      frames.shift();
      return { frames: frames.length, max: Math.round(Math.max(...frames)), over34: frames.filter(f => f > 34).length, over20: frames.filter(f => f > 20).length };
    });
    for (let i = 0; i < 40; i++) { await page.mouse.move(50 + i * (vp.width - 100) / 40, 300 + (i % 2) * 40); await page.waitForTimeout(25); }
    const sweepRes = await sweep;

    results.push({ vp: vp.name, path, loadMs, errors: errors.slice(0, 5), ...metrics, idle: gaps, sweep: sweepRes });
    await page.close();
  }
  await ctx.close();
}
await browser.close();
fs.writeFileSync(`${out}/results.json`, JSON.stringify(results, null, 2));
for (const r of results) {
  console.log(`${r.vp.padEnd(7)} ${r.path.padEnd(32)} load=${r.loadMs}ms overflow=${r.overflow} small=${r.smallCount} cards=${r.cards} badges=${r.badges} errors=${r.errors.length} idleMax=${r.idle.max} sweepMax=${r.sweep.max} sweep>20=${r.sweep.over20}/${r.sweep.frames}`);
  if (r.errors.length) console.log('   errors:', r.errors.join(' | ').slice(0, 300));
  if (r.smallCount) console.log('   small:', JSON.stringify(r.small.slice(0, 4)));
}
