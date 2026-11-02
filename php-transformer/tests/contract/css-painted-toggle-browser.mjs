import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const artifact = JSON.parse(readFileSync(process.env.CSS_PAINTED_TOGGLE_ARTIFACT, 'utf8'));
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    await page.setContent(`<style>${artifact.css}</style>${artifact.markup}`);
    for (const block of artifact.generated) {
        const script = block.view_js || block.assets['view.js'];
        if (script) await page.addScriptTag({ content: script });
    }
    const toggle = page.getByRole('button', { name: 'Open menu', exact: true });
    assert.equal(await toggle.count(), 1);
    assert.equal(await toggle.isVisible(), true);
    const icon = toggle.locator('.icon-bars');
    assert.deepEqual(await icon.evaluate(node => {
        const box = node.getBoundingClientRect();
        return [box.width, box.height, getComputedStyle(node, '::before').width, getComputedStyle(node, '::after').height];
    }), [18, 3, '18px', '3px']);
    const box = await toggle.boundingBox();
    assert.deepEqual([box.x, box.y, box.width, box.height], [334, 12, 44, 40]);
    await toggle.click();
    assert.equal(await page.locator('dialog').evaluate(node => node.open), true);
    assert.equal(await page.locator('dialog a:visible').count(), 5);
    console.log('css-painted-toggle browser: ok');
} finally {
    await browser.close();
}
