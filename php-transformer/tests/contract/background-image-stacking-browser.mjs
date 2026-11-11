import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./background-image-stacking.php', import.meta.url))], { encoding: 'utf8' }));
const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
try {
    const page = await browser.newPage({ viewport: { width: 600, height: 400 } });
    for (const role of ['source', 'native']) {
        await page.setContent(fixture[role]);
        if (role === 'native') {
            assert.equal(await page.locator('figure.blocks-engine-background-image img').count(), 1, 'background remains an editable image');
        }
        const hit = await page.evaluate(() => {
            const label = [...document.querySelectorAll('*')].find(element => element.childElementCount === 0 && element.textContent.trim() === 'Tavistock');
            const rect = label.getBoundingClientRect();
            const top = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
            return { visible: rect.width > 0 && rect.height > 0, labelOnTop: top === label || label.contains(top), top: top?.tagName };
        });
        assert.ok(hit.visible && hit.labelOnTop, `${role}: label must paint over its background: ${JSON.stringify(hit)}`);
    }
} finally {
    await browser.close();
}
console.log('Background image stacking browser contract passed.');
