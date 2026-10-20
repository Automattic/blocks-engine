// Uses the compiled native navigation attributes/CSS with Core's list-box shape.
// Run: node tests/integration/navigation-list-box-properties-browser.mjs
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const cases = JSON.parse(execFileSync('php', [new URL('navigation-list-box-properties.php', import.meta.url).pathname], {encoding:'utf8'}));
const browser = await chromium.launch({headless:true, ...(process.env.CHROME_PATH ? {executablePath:process.env.CHROME_PATH} : {})});
try {
    for (const [name, pages] of Object.entries(cases)) {
        for (const width of [390,1440]) {
            const measurements = [];
            for (const html of [pages.source,pages.candidate]) {
                const page = await browser.newPage({viewport:{width,height:900}});
                await page.setContent(html);
                measurements.push(await page.evaluate(() => ({
                    visible: [...document.querySelectorAll('a')].filter(a => a.getClientRects().length > 0).length,
                    scrollWidth: document.documentElement.scrollWidth,
                    scroller: [...document.querySelectorAll('nav,ul')].some(e => getComputedStyle(e).overflowX === 'scroll' && e.scrollWidth > e.clientWidth),
                })));
                await page.close();
            }
            const [source,candidate] = measurements;
            assert.equal(candidate.visible, source.visible, `${name} ${width}: visibility`);
            assert.equal(candidate.scrollWidth, width, `${name} ${width}: page overflow`);
            if (name === 'scroller') assert.equal(candidate.scroller, true, `${width}: internal scrolling`);
        }
    }
    console.log('Navigation list box browser visibility and scrolling passed');
} finally {await browser.close();}
