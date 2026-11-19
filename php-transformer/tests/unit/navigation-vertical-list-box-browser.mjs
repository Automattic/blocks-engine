// Generate with: php tests/unit/navigation-vertical-list-box.php /tmp/list-box.json
import fs from 'node:fs';
import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
try {
    const page = await browser.newPage();
    for (const width of [390, 768, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        for (const [name, html] of Object.entries({ source: fixture.source, compiled: `<style>${fixture.css}</style>${fixture.native}` })) {
            await page.setContent(html);
            const facts = await page.evaluate(() => {
                const box = document.querySelector('nav.wp-block-navigation') || document.querySelector('nav ul');
                const style = getComputedStyle(box);
                return {
                    display: style.display, border: style.borderTopWidth, radius: style.borderTopLeftRadius,
                    background: style.backgroundColor,
                    links: [...document.querySelectorAll('nav a')].map(link => ({
                        top: link.getBoundingClientRect().top, width: link.getBoundingClientRect().width,
                        padding: getComputedStyle(link).padding,
                    })),
                };
            });
            assert.notEqual(facts.display, 'contents');
            assert.equal(facts.border, '1px');
            assert.equal(facts.radius, '11px');
            assert.equal(facts.background, 'rgba(204, 180, 28, 0.07)');
            assert.equal(facts.links.length, 20);
            facts.links.forEach((link, index) => {
                assert.equal(link.padding, '3px 13px', `${name} at ${width}`);
                assert.ok(link.width >= 282, `${name} link fills the list width`);
                if (index) assert.ok(link.top > facts.links[index - 1].top, `${name} items stack one per row`);
            });
        }
    }
} finally {
    await browser.close();
}
console.log('Vertical list box browser regression passed at 390/768/1440');
