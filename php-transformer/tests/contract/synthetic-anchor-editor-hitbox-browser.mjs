import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { markup, css } = JSON.parse(readFileSync(process.env.SYNTHETIC_ANCHOR_EDITOR_ARTIFACT, 'utf8'));
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    for (const editor of [false, true]) {
        await page.setContent(`<!doctype html><style>${css}</style><body class="${editor ? 'editor-styles-wrapper' : ''}">${markup}</body>`);
        const carrier = page.locator('p:has(>a[href="/"])');
        assert.ok(await carrier.evaluate(e => e.getBoundingClientRect().width > 0), 'The native paragraph has a box on both frontend and editor');
        if (!editor) continue;
        await carrier.evaluate(e => {
            e.contentEditable = 'true';
            e.addEventListener('click', event => { event.preventDefault(); e.dataset.clicked = 'yes'; });
        });
        await carrier.click({ timeout: 2000 });
        assert.equal(await carrier.getAttribute('data-clicked'), 'yes', 'The RichText carrier, not its enclosing Group, receives the click');
        assert.equal(await carrier.evaluate(e => document.activeElement === e), true, 'Native RichText can receive focus');
    }
} finally {
    await browser.close();
}
