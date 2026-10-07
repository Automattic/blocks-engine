#!/usr/bin/env node
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { chromium } from '../tools/visual-parity/node_modules/playwright/index.mjs';

const evidence = process.env.NICK_THEME_EVIDENCE_DIR;
const baseUrl = process.env.THEME_ACCEPTANCE_WP_URL;
const postId = Number(process.env.NICK_THEME_REIMPORT_POST_ID);
if (!evidence || !baseUrl || !Number.isInteger(postId)) throw new Error('Nick SSI reimport frontend acceptance environment is incomplete.');
const source = JSON.parse(await readFile(`${ evidence }/nick-live-browser-evidence.json`, 'utf8'));
const capturedArtifact = JSON.parse(await readFile(`${ evidence }/nick-site-artifact.json`, 'utf8'));
const ownerStylesheetPath = `/${ source.stylesheet_asset.path.replace(/^\/+/, '') }`;
const ownerStylesheetCss = capturedArtifact.runtime_declarations[0].payload.ownership.stylesheet_evidence[0].content;
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ colorScheme: 'light' });
const page = await context.newPage();
const errors = [];
const requests = [];
page.on('pageerror', (error) => errors.push(error.message));
page.on('request', (request) => requests.push(request.url()));
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
    const nonControlScope = await page.locator('footer').evaluate((footer, evidence) => {
        const escaped = evidence.footerClasses.map((className) => className.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
        const probe = escaped.map((className, index) => {
            const match = evidence.css.match(new RegExp(`(?:^|})\\.${ className }\\{[^}]*margin-top:[^}]+\\}`));
            return match ? { className: evidence.footerClasses[index], rule: match[0].replace(/^}/, '') } : null;
        }).find(Boolean);
        if (!probe) return { error: 'No original owner-CSS margin rule targets a class on the real exported footer.' };
        const rules = [];
        for (const sheet of document.styleSheets) {
            try {
                for (const rule of sheet.cssRules) {
                    if (rule.selectorText?.split(',').some((selector) => selector.trim() === `.${ probe.className }`)
                        && rule.style?.getPropertyValue('margin-top')) rules.push({ href: sheet.href, text: rule.cssText });
                }
            } catch {}
        }
        const style = getComputedStyle(footer);
        const rect = footer.getBoundingClientRect();
        return {
            footer_class: footer.className,
            source_css_probe: probe,
            margin_top: style.marginTop,
            margin_bottom: style.marginBottom,
            rect: { x: rect.x, y: rect.y, width: rect.width, height: rect.height },
            linked_stylesheets: [...document.styleSheets].map((sheet) => sheet.href).filter(Boolean),
            mt8_rules: rules,
        };
    }, { footerClasses: await page.locator('footer').getAttribute('class').then((value) => (value || '').split(/\s+/)), css: ownerStylesheetCss });
    assert.equal(nonControlScope.error, undefined, 'the captured owner stylesheet contains a real margin rule for a class on the exported footer outside the control group');
    assert.deepEqual(nonControlScope.mt8_rules, [], 'the detached original-source non-control geometry rule is not in the reimported theme active cascade');
    assert.equal(requests.some((url) => url.includes(ownerStylesheetPath)), false, 'the detached original stylesheet asset was not requested as an active resource');
    assert.deepEqual(errors, [], 'the SSI export/reimport frontend has no uncaught browser errors');
    const result = {
        page_id: postId,
        runtime_asset: source.runtime_asset,
        labels: await controls.evaluateAll((nodes) => nodes.map((node) => node.getAttribute('aria-label'))),
        preference: await page.evaluate(() => localStorage.getItem('theme')),
        root_class: await page.locator('html').getAttribute('class'),
        noncontrol_scope: nonControlScope,
        owner_stylesheet_requested: requests.some((url) => url.includes(ownerStylesheetPath)),
        errors,
    };
    await writeFile(`${ evidence }/nick-ssi-reimport-browser-result.json`, JSON.stringify(result, null, 2) + '\n');
    await page.screenshot({ path: `${ evidence }/nick-ssi-reimport-frontend.png`, fullPage: true });
    console.log('PASS: SSI export/reimport preserves and operates the live Nick controls in the new theme');
} finally {
    await browser.close();
}
