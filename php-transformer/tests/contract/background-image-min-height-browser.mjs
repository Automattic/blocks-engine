import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./background-image-min-height.php', import.meta.url)), '--browser'], { encoding: 'utf8' }));
const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
try {
    const page = await browser.newPage();
    // Supply the reproduction's intrinsic image size without a network dependency.
    await page.route('**/hero.jpg', route => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="800"/>' }));
    for (const width of [1440, 768]) {
        await page.setViewportSize({ width, height: 1000 });
        const boxes = [];
        for (const role of ['source', 'native']) {
            await page.setContent('<base href="https://example.test/">' + fixture[role].replace(/\{\{wordpress-site-plan:asset:[^}]+\}\}/g, 'hero.jpg'));
            await page.locator('img').evaluateAll(images => Promise.all(images.map(image => image.decode())));
            boxes.push(await page.evaluate(() => {
                const host = document.querySelector('.hero');
                const box = host.getBoundingClientRect();
                const image = host.querySelector('img');
                return { height: box.height, below: document.querySelector('p').getBoundingClientRect().top, imageHeight: image?.getBoundingClientRect().height };
            }));
        }
        assert.equal(boxes[0].height, 417);
        assert.equal(boxes[1].height, 417, 'image intrinsic ratio must not size its host');
        assert.equal(boxes[1].imageHeight, 417, 'paint fills the min-height-sized host');
        assert.equal(boxes[1].below, boxes[0].below, 'following content stays in place');
    }
} finally {
    await browser.close();
}
console.log('Min-height background image browser contract passed.');
