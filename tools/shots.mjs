/**
 * Screenshots for the README and the announcement, on stock Flarum's default
 * theme: VR_BASE=http://guestgate.localhost:8098 node tools/shots.mjs <tokens.json> [forumDir]
 *
 * tokens.json maps a username to a remember token minted on that forum, so
 * no password is typed. forumDir (for sqlite3) lets the meter shot set a
 * one-discussion allowance for its own run and put the old one back.
 */
import puppeteer from '../../convoro2/node_modules/puppeteer-core/lib/puppeteer/puppeteer-core.js';
import { mkdir } from 'node:fs/promises';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = process.env.VR_BASE || 'http://guestgate.localhost:8098';
const OUT = `${dirname(fileURLToPath(import.meta.url))}/../screenshots`;
const TOKENS = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const DB = process.argv[3] ? `${process.argv[3]}/storage/flarum.sqlite` : null;
const DESKTOP = { width: 1280, height: 900, deviceScaleFactor: 2 };
const PHONE = { width: 390, height: 844, deviceScaleFactor: 3, isMobile: true, hasTouch: true };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

await mkdir(OUT, { recursive: true });
const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--hide-scrollbars', '--force-color-profile=srgb'] });
const errors = [];

async function context(user, viewport = DESKTOP) {
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('response', (r) => r.status() >= 400 && !r.url().endsWith('favicon.ico') && errors.push(`${r.status()} ${r.url()}`));
  await page.setViewport(viewport);
  await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
  if (user) await page.setCookie({ name: 'flarum_remember', value: TOKENS[user], domain: new URL(BASE).hostname, path: '/', httpOnly: true });
  return page;
}

async function open(page, path, wait) {
  await page.goto(BASE + path, { waitUntil: 'networkidle0' });
  if (wait) await page.waitForSelector(wait, { timeout: 10000 });
  await sleep(800);
}

/** Screenshot from the top of `from` down `height` CSS pixels. */
async function shot(page, name, from, height) {
  const top = from ? await page.$eval(from, (el) => el.getBoundingClientRect().top + window.scrollY) : 0;
  await page.screenshot({ path: `${OUT}/${name}.png`, clip: { x: 0, y: Math.max(0, top - 16), width: page.viewport().width, height } });
  console.log('shot', name);
}

const sql = (q) => DB && execFileSync('sqlite3', [DB, q]);

// 1. A guest on a discussion: first post with its image locked, replies held back.
let page = await context(null);
await open(page, '/d/1', '.VelvetRope-prompt');
await page.evaluate(() => window.app.alerts.clear());
await shot(page, 'discussion', '.DiscussionPage-stream', 1050);

// 2. The members-only tag, seen by a member outside its group.
page = await context('jonas');
await open(page, '/d/3', '.VelvetRope-prompt');
await shot(page, 'supporters', '.DiscussionPage-stream', 820);

// 3. A guest past their allowance: the lead-in. One free discussion for this run.
sql("update ggs_settings set value='1' where key='ernestdefoe-velvet-rope.meter_limit'");
page = await context(null);
await open(page, '/d/2', '.PostStream-item');
await page.evaluate(() => window.m.route.set('/d/1'));
await page.waitForSelector('.VelvetRope-post--lead', { timeout: 10000 });
await sleep(800);
await page.evaluate(() => window.app.alerts.clear());
await shot(page, 'meter', '.DiscussionPage-stream', 760);
sql("update ggs_settings set value='3' where key='ernestdefoe-velvet-rope.meter_limit'");

// 4. The low-allowance notice.
page = await context(null);
await open(page, '/d/2', '.PostStream-item');
await page.evaluate(() => window.m.route.set('/d/3'));
await page.waitForSelector('.Alert', { timeout: 10000 });
await sleep(600);
await page.screenshot({ path: `${OUT}/notice.png`, clip: { x: 0, y: page.viewport().height - 160, width: page.viewport().width, height: 160 } });
console.log('shot notice');

// 5. The admin page: a rule and its live preview, and the meter.
page = await context('admin');
await open(page, '/admin#/extension/ernestdefoe-velvet-rope', '.VelvetRope-rule');
await shot(page, 'admin', '.VelvetRope-intro', 1250);
await shot(page, 'admin-meter', '.VelvetRope-heading', 1200);

// 6. On a phone.
page = await context(null, PHONE);
await open(page, '/d/1', '.VelvetRope-prompt');
await page.evaluate(() => window.app.alerts.clear());
await page.evaluate(() => document.querySelector('.PostStream-item:nth-child(2)')?.scrollIntoView());
await sleep(500);
await page.screenshot({ path: `${OUT}/phone.png` });
console.log('shot phone');

console.log('errors', JSON.stringify(errors));
await browser.close();
