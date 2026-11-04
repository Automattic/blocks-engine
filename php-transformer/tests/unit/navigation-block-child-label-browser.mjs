// Run the PHP regression with an output path first, then pass that JSON here.
import fs from 'node:fs';
import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
// Optional second fixture: tests/contract/navigation-block-child-label-box.php.
const boxes = process.argv[3] ? JSON.parse(fs.readFileSync(process.argv[3], 'utf8')) : null;
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
            const real = getComputedStyle([...anchor.querySelectorAll('span')].find(e => e.textContent.trim() === 'My work'));
            return { position: style.position, left: style.left, top: style.top, transform: style.transform, fontSize: style.fontSize, fontWeight: style.fontWeight, realFontSize: real.fontSize, realFontWeight: real.fontWeight, x: rect.x, y: rect.y };
        });
        assert.equal(measurement.position, 'absolute');
        assert.equal(measurement.left, '47px');
        assert.equal(measurement.top, '40px');
        assert.equal(measurement.transform, 'matrix(0, 1, -1, 0, 0, 0)');
        // #3474: the chevron keeps the anchor's font; only real spans get `a span`.
        assert.equal(measurement.fontSize, '18px');
        assert.equal(measurement.fontWeight, '700');
        assert.equal(measurement.realFontSize, '10px');
        assert.equal(measurement.realFontWeight, '400');
        console.log(name, JSON.stringify(measurement));
    }
    if (boxes) {
        const page = await browser.newPage({ viewport: { width: 900, height: 800 } });
        let sourceY;
        // Core makes the content anchor block-level. Without its real display
        // rule the synthetic renderer leaves it inline and hides #4329.
        const coreCss = '.wp-block-navigation__container{margin:0;padding:0;list-style:none}.wp-block-navigation-item__content{display:block}';
        for (const [name, html] of Object.entries({ source: boxes.source, compiled: `<!doctype html><style>${coreCss}${boxes.css}</style>${boxes.native}` })) {
            await page.setContent(html);
            const geometry = await page.evaluate(() => {
                const anchor = document.querySelector('a[href="/services/"]');
                const fill = anchor.querySelector('.fill');
                const range = document.createRange();
                range.selectNodeContents(fill);
                const text = range.getBoundingClientRect();
                const slot = anchor.closest('.slot').getBoundingClientRect();
                const link = anchor.getBoundingClientRect();
                const box = fill.getBoundingClientRect();
                return { y: text.y, slot: [slot.width, slot.height], link: [link.width, link.height], fill: [box.width, box.height], dx: text.x + text.width / 2 - slot.x - slot.width / 2, dy: text.y + text.height / 2 - slot.y - slot.height / 2 };
            });
            console.log(name, 'Services', JSON.stringify(geometry));
            if (name === 'source') {
                sourceY = geometry.y;
                assert.ok(Math.abs(sourceY - 26) <= 1, 'Source Services text must sit at y 26 in the 53px slot');
            }
            assert.ok(Math.abs(geometry.y - sourceY) <= 1, 'Services text y must match source (26px)');
            assert.deepEqual(geometry.slot, [125, 53]);
            assert.deepEqual(geometry.link, [125, 53]);
            assert.deepEqual(geometry.fill, [125, 53]);
            assert.ok(Math.abs(geometry.dx) <= 1, 'Services must be horizontally centered');
            assert.ok(Math.abs(geometry.dy) <= 1, 'Services must be vertically centered');
        }
    }
} finally {
    await browser.close();
}
