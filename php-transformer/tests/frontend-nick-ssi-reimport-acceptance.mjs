#!/usr/bin/env node
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { chromium } from '../tools/visual-parity/node_modules/playwright/index.mjs';

const evidence = process.env.NICK_THEME_EVIDENCE_DIR;
const baseUrl = process.env.THEME_ACCEPTANCE_WP_URL;
const postId = Number(process.env.NICK_THEME_REIMPORT_POST_ID);
if (!evidence || !baseUrl || !Number.isInteger(postId)) throw new Error('Nick SSI reimport frontend acceptance environment is incomplete.');
const source = JSON.parse(await readFile(`${ evidence }/nick-live-browser-evidence.json`, 'utf8'));
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ colorScheme: 'light' });
const page = await context.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
try {
    await page.goto(baseUrl, { waitUntil: 'networkidle' });
    await page.evaluate(() => localStorage.removeItem('theme'));
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto(`${ baseUrl }/?page_id=${ postId }`, { waitUntil: 'networkidle' });
    const controls = page.getByRole('button', { name: /^(Light|System|Dark) theme$/ });
    assert.equal(await controls.count(), 3, 'the SSI export/reimport frontend retains the actual Nick control group');
    assert.deepEqual(await controls.evaluateAll((nodes) => nodes.map((node) => node.getAttribute('aria-label'))), ['Light theme', 'System theme', 'Dark theme']);
    assert.deepEqual(await controls.evaluateAll((nodes) => nodes.map((node) => node.querySelector('svg')?.getAttribute('class') || '')), source.unchanged_source_controls.map((control) => control.icon_class));

    await controls.nth(1).click();
    await page.waitForFunction(() => localStorage.getItem('theme') === 'system' && document.documentElement.classList.contains('light'));
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.waitForFunction(() => localStorage.getItem('theme') === 'system' && document.documentElement.classList.contains('dark'));
    await controls.nth(0).click();
    await page.waitForFunction(() => localStorage.getItem('theme') === 'light' && document.documentElement.classList.contains('light'));
    await page.emulateMedia({ colorScheme: 'dark' });
    assert.equal(await page.evaluate(() => document.documentElement.classList.contains('light')), true, 'explicit Light remains authoritative in the reimported site');
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForFunction(() => localStorage.getItem('theme') === 'light' && document.documentElement.classList.contains('light'));
    await controls.nth(2).focus();
    await page.keyboard.press('Enter');
    await page.waitForFunction(() => localStorage.getItem('theme') === 'dark' && document.documentElement.classList.contains('dark'));
    await page.emulateMedia({ colorScheme: 'light' });
    assert.equal(await page.evaluate(() => document.documentElement.classList.contains('dark')), true, 'explicit Dark remains authoritative in the reimported site');
    await controls.nth(1).click();
    await page.waitForFunction(() => localStorage.getItem('theme') === 'system');
    await page.emulateMedia({ colorScheme: 'light' });
    await page.waitForFunction(() => localStorage.getItem('theme') === 'system' && document.documentElement.classList.contains('light'));
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.waitForFunction(() => localStorage.getItem('theme') === 'system' && document.documentElement.classList.contains('dark'));
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForFunction(() => localStorage.getItem('theme') === 'system' && document.documentElement.classList.contains('dark'));
    assert.deepEqual(errors, [], 'the SSI export/reimport frontend has no uncaught browser errors');
    const result = {
        page_id: postId,
        runtime_asset: source.runtime_asset,
        labels: await controls.evaluateAll((nodes) => nodes.map((node) => node.getAttribute('aria-label'))),
        preference: await page.evaluate(() => localStorage.getItem('theme')),
        root_class: await page.locator('html').getAttribute('class'),
        errors,
    };
    await writeFile(`${ evidence }/nick-ssi-reimport-browser-result.json`, JSON.stringify(result, null, 2) + '\n');
    await page.screenshot({ path: `${ evidence }/nick-ssi-reimport-frontend.png`, fullPage: true });
    console.log('PASS: SSI export/reimport preserves and operates the live Nick controls in the new theme');
} finally {
    await browser.close();
}
