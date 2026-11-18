import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

// An authored `p:empty{display:none}` must not drop an edit in the editor:
// replacing a paragraph's text empties it for a moment, and a hidden element
// loses focus, so the typed text never reached the block (issue #5797).
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const directory = mkdtempSync(join(tmpdir(), 'empty-hidden-richtext-'));
let browser;
try {
    const artifact = join(directory, 'fixture.json');
    execFileSync('php', [fileURLToPath(new URL('./empty-hidden-richtext-edit.php', import.meta.url))], {
        env: { ...process.env, EMPTY_HIDDEN_RICHTEXT_ARTIFACT: artifact },
    });
    const { markup, frontend, editor } = JSON.parse(readFileSync(artifact, 'utf8'));
    browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
    const page = await browser.newPage();

    // Front end: the authored empty-state rule still applies.
    await page.setContent(`<!doctype html><style>${frontend}</style><body>${markup}</body>`);
    const paragraph = page.locator('p').first();
    await paragraph.evaluate(e => { e.textContent = ''; });
    assert.equal(await paragraph.evaluate(e => getComputedStyle(e).display), 'none', 'Front end hides an empty paragraph');

    // Editor: the same paragraph is a RichText root.
    await page.setContent(`<!doctype html><style>${editor}</style><body><div class="editor-styles-wrapper">${markup}</div></body>`);
    const editable = page.locator('p').first();
    const resting = page.locator('p').nth(1);
    await page.locator('p').evaluateAll(nodes => nodes.forEach(node => { node.contentEditable = 'true'; }));
    await resting.evaluate(e => { e.textContent = ''; });
    assert.equal(await resting.evaluate(e => getComputedStyle(e).display), 'none', 'An empty paragraph at rest keeps the authored state');

    await editable.click();
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.press('Backspace');
    assert.equal(await page.evaluate(() => document.activeElement?.tagName), 'P', 'The emptied RichText root keeps focus');
    await page.keyboard.type('Import loop edit');
    assert.equal(await page.locator('p', { hasText: 'Import loop edit' }).count(), 1, 'Typed text reaches the emptied paragraph');
} finally {
    await browser?.close();
    rmSync(directory, { recursive: true, force: true });
}
console.log('Empty hidden RichText edit browser test passed.');
