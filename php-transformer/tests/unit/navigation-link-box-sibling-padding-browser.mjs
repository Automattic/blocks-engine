// Generate the fixture with navigation-link-box-sibling-padding.php <output.json>.
import fs from 'node:fs';
import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
try {
    const page = await browser.newPage({ viewport: { width: 1000, height: 800 } });
    let source;
    for (const [name, html] of Object.entries({ source: fixture.source, compiled: `<!doctype html><style>${fixture.css}</style>${fixture.native}` })) {
        await page.setContent(html);
        const measurements = await page.evaluate(() => [...document.querySelectorAll('nav li')].map(item => {
            const box = item.querySelector('.box');
            const sep = item.querySelector('.sep');
            return { boxPadding: getComputedStyle(box).padding, spacerPadding: getComputedStyle(sep).padding, spacerWidth: sep.getBoundingClientRect().width, x: box.querySelector('a').getBoundingClientRect().x };
        }));
        console.log(name, JSON.stringify(measurements));
        if (name === 'source') source = measurements;
        assert.equal(measurements.length, 3);
        measurements.forEach((item, index) => {
            assert.equal(item.boxPadding, '10px 12px');
            assert.equal(item.spacerPadding, '0px');
            assert.equal(item.spacerWidth, 2);
            assert.ok(Math.abs(item.x - source[index].x) <= 1, 'Link x must match source within 1px');
        });
    }
} finally {
    await browser.close();
}
