import { chromium } from 'playwright';
const b = await chromium.launch();
for (const [name, url, act] of [
  ['home load (hero choreography)', 'http://localhost:8080/', null],
  ['shop scroll (stagger + sticky)', 'http://localhost:8080/shop/', 'scroll'],
  ['pdp scroll (purchase bar in/out)', 'http://localhost:8080/product/cortez-xmax-48/', 'scroll'],
  ['home pointer over hero (light)', 'http://localhost:8080/', 'pointer'],
]) {
  const p = await b.newPage({ viewport: { width: 1440, height: 900 } });
  await p.addInitScript(() => { window.__f = []; let last = null; const t = (ts) => { if (last !== null) window.__f.push(ts - last); last = ts; requestAnimationFrame(t); }; requestAnimationFrame(t); });
  await p.goto(url, { waitUntil: 'load' });
  if (act === 'scroll') { for (let y = 0; y <= 2400; y += 120) { await p.mouse.wheel(0, 120); await p.waitForTimeout(16); } }
  if (act === 'pointer') { for (let i = 0; i < 60; i++) { await p.mouse.move(100 + i * 20, 400 + (i % 3) * 30); await p.waitForTimeout(16); } }
  await p.waitForTimeout(1400);
  const f = await p.evaluate(() => window.__f);
  const over = (n) => f.filter(x => x > n).length;
  console.log(name.padEnd(36), "longAt=" + JSON.stringify(f.map((x,i)=>[i,Math.round(x)]).filter(a=>a[1]>20)), `frames=${f.length} max=${Math.round(Math.max(...f))}ms >20ms=${over(20)} >34ms=${over(34)}`);
  await p.close();
}
await b.close();
