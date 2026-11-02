import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = process.env.PLAYWRIGHT_MODULE ? await import(process.env.PLAYWRIGHT_MODULE) : require('playwright');
const root = new URL('../../..', import.meta.url).pathname;
const source = readFileSync(new URL('./fixtures/block-link-inline-icons.html', import.meta.url), 'utf8');
const result = JSON.parse(execFileSync('php', [root + '/tests/unit/block-link-inline-icons.php', '--json'], { encoding: 'utf8' }));
const markup = result.source_reports.compiled_site.pages[0].block_markup;
const css = result.assets.filter(a => a.kind === 'css' && a.stylesheet_target !== 'editor').map(a => a.content).join('\n');
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 390, height: 600 } });
    for (const html of [source, `<style>${css}</style>${markup}`]) {
        await page.setContent(html);
        assert.deepEqual(await page.locator('.bar__label').evaluateAll(labels => labels.map(label => getComputedStyle(label).color)), ['rgb(51, 51, 51)', 'rgb(51, 51, 51)']);
        assert.equal(await page.locator('a .icon-mail, a .icon-chat').count(), 2);
    }
} finally {
    await browser.close();
}
