/**
 * A guest's walk through the forum as a browser does it (single-page
 * navigation, cookies, the alert): GG_BASE=http://velvetrope.localhost:8098 node tools/guest-flow.mjs
 * Prints what the guest saw on each discussion, and any console errors.
 */
import puppeteer from '../../convoro2/node_modules/puppeteer-core/lib/puppeteer/puppeteer-core.js';

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = process.env.GG_BASE || 'http://velvetrope.localhost:8098';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new' });
const context = await browser.createBrowserContext();
const page = await context.newPage();
const errors = [];
page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
page.on('pageerror', (e) => errors.push(String(e)));
page.on('response', (r) => r.status() >= 400 && errors.push(r.status() + ' ' + r.url()));

await page.goto(BASE + '/', { waitUntil: 'networkidle0' });

const ids = (process.env.GG_IDS || '1,2,3').split(',');
for (const id of ids) {
  // Single-page navigation, as a click on the discussion list does.
  await page.evaluate((id) => window.m.route.set('/d/' + id), id);
  await page.waitForSelector('.PostStream-item', { timeout: 8000 });
  await sleep(1200);
  const seen = await page.evaluate(() => ({
    title: document.querySelector('.DiscussionHero-title')?.textContent,
    gated: [...document.querySelectorAll('.VelvetRope-post')].map((p) => p.className.match(/VelvetRope-post--(\w+)/)?.[1]),
    prompts: [...document.querySelectorAll('.VelvetRope-prompt-title')].map((p) => p.textContent),
    locks: [...document.querySelectorAll('.VelvetRope-lock')].map((l) => l.textContent.trim() + (l.title ? ` [${l.title}]` : '')),
    alert: document.querySelector('.AlertManager .Alert')?.textContent?.trim() || null,
  }));
  console.log(id, JSON.stringify(seen));
  await page.evaluate(() => window.app.alerts.clear());
}

const cookies = await context.cookies();
console.log('cookie', cookies.find((c) => c.name.endsWith('velvet_rope'))?.value);
console.log('errors', JSON.stringify(errors));
await browser.close();
