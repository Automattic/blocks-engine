import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./viewport-entrance-card-links.php', import.meta.url)), '--browser-fixture'], {encoding: 'utf8'}));
const browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_PATH});
try {
    const page = await browser.newPage();
    const measure = () => page.locator('.grid a').evaluateAll(cards => cards.map(card => {
        const box = card.getBoundingClientRect();
        const label = getComputedStyle(card.firstElementChild);
        return {width: box.width, height: box.height, display: label.display, fontSize: label.fontSize, titleFontSize: getComputedStyle(card.lastElementChild).fontSize};
    }));
    for (const width of [1440, 390]) {
        await page.setViewportSize({width, height: 900});
        await page.setContent(fixture.source);
        const source = await measure();
        await page.setContent(`<html class="motion-ready"><style>${fixture.css}</style><body>${fixture.candidate}</body></html>`);
        assert.deepEqual(await measure(), source);
        assert.equal(await page.locator('[data-blocks-engine-capture-viewport-entrance]').count(), 3);
        assert.equal(source[0].display, 'block');
        assert.equal(source[0].fontSize, '9px');
        assert.equal(source[0].titleFontSize, '12px');
    }
} finally {
    await browser.close();
}
