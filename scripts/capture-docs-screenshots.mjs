#!/usr/bin/env node
/**
 * Захват скриншотов UI-экранов для встраивания в docs/manager/*.md и пр.
 *
 * Логинится как docs-bot (роль head_of_sales — видит все заявки, имеет
 * полный manager-UI + дашборд РОПа), ходит по списку URL, сохраняет
 * PNG в public/docs/screenshots/. Шифт+перезапуск UI через
 * `npm run build` НЕ требуется — это static-инструмент.
 *
 * Использование:
 *   DOCS_USER_PASSWORD=<pwd> node scripts/capture-docs-screenshots.mjs
 *
 * Опциональные env:
 *   DOCS_BASE_URL     — default https://mzcorp.ru
 *   DOCS_USER_EMAIL   — default docs-bot@myzip.ru
 *   CHROMIUM_PATH     — default /usr/bin/chromium-browser
 *   DOCS_OUT_DIR      — default ./public/docs/screenshots
 */

import puppeteer from 'puppeteer-core';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';

const BASE = process.env.DOCS_BASE_URL || 'https://mzcorp.ru';
const EMAIL = process.env.DOCS_USER_EMAIL || 'docs-bot@myzip.ru';
const PASSWORD = process.env.DOCS_USER_PASSWORD;
const CHROMIUM = process.env.CHROMIUM_PATH || '/usr/bin/chromium-browser';
const OUT_DIR = resolve(process.env.DOCS_OUT_DIR || './public/docs/screenshots');

if (!PASSWORD) {
    console.error('error: DOCS_USER_PASSWORD env var required');
    process.exit(1);
}

// Список захватов. URL — относительно BASE. `wait` — доп. ms после
// networkidle для settle Livewire/Alpine. `crop` — { width, height }
// для viewport-screenshot (по умолчанию 1440×900).
const captures = [
    {
        name: 'requests-pool',
        url: '/dashboard/requests?bucket=active',
        label: 'Список заявок · общий вид',
        wait: 2000,
    },
    {
        name: 'request-detail',
        url: '/dashboard/requests/1928',
        label: 'Карточка заявки · общий вид',
        wait: 2500,
    },
    {
        name: 'request-positions',
        url: '/dashboard/requests/1928?tab=items',
        label: 'Вкладка «Позиции»',
        wait: 2500,
    },
    {
        name: 'dashboard',
        url: '/dashboard',
        label: 'Дашборд РОПа',
        wait: 2000,
    },
];

await mkdir(OUT_DIR, { recursive: true });

const browser = await puppeteer.launch({
    executablePath: CHROMIUM,
    headless: 'new',
    args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage', // /dev/shm small на VPS
        '--disable-gpu',
        '--no-zygote',
    ],
});
const page = await browser.newPage();
await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 2 });

// ─── Login ──────────────────────────────────────────────────────────
console.log(`→ login as ${EMAIL}`);
await page.goto(`${BASE}/login`, { waitUntil: 'networkidle2' });
await page.type('input[name=email]', EMAIL);
await page.type('input[name=password]', PASSWORD);
await Promise.all([
    page.click('button[type=submit]'),
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30_000 }),
]);

const finalUrl = page.url();
if (finalUrl.includes('/login')) {
    console.error(`error: login redirected back to /login (${finalUrl}) — wrong password?`);
    await browser.close();
    process.exit(2);
}
console.log(`✓ logged in, landing: ${finalUrl}`);

// ─── Captures ───────────────────────────────────────────────────────
for (const c of captures) {
    const target = `${BASE}${c.url}`;
    process.stdout.write(`→ ${c.name} (${c.label}) `);
    try {
        await page.goto(target, { waitUntil: 'networkidle2', timeout: 30_000 });
    } catch (e) {
        // networkidle2 иногда никогда не наступает из-за wire:poll —
        // фоллбэк на domcontentloaded.
        await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    }
    if (c.wait) {
        await new Promise(r => setTimeout(r, c.wait));
    }
    const out = `${OUT_DIR}/${c.name}.png`;
    await page.screenshot({ path: out, fullPage: false });
    console.log(`✓ ${out}`);
}

await browser.close();
console.log('done.');
