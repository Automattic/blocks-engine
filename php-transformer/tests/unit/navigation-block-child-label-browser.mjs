// Run the PHP regression with an output path first, then pass that JSON here.
import fs from 'node:fs';
import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
    for (const [name, html] of Object.entries({ source: fixture.source, compiled: `<style>${fixture.css}</style>${fixture.native}` })) {
        await page.setContent(html);
        const measurement = await page.evaluate(() => {
            const anchor = document.querySelector('a[href="/g/"]');
            const glyph = [...anchor.querySelectorAll('p,span')].find(e => e.textContent.trim() === '>');
            const style = getComputedStyle(glyph);
            const rect = glyph.getBoundingClientRect();
            return { position: style.position, left: style.left, top: style.top, transform: style.transform, x: rect.x, y: rect.y };
        });
        assert.equal(measurement.position, 'absolute');
        assert.equal(measurement.left, '47px');
        assert.equal(measurement.top, '40px');
        assert.equal(measurement.transform, 'matrix(0, 1, -1, 0, 0, 0)');
        console.log(name, JSON.stringify(measurement));
    }
} finally {
    await browser.close();
}
