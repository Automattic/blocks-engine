import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./navigation-bare-anchor-alignment.php', import.meta.url)), '--json'], {encoding: 'utf8'}));
const browser = await chromium.launch({headless: true});
try {
    const page = await browser.newPage();
    // Core's duplicate list copies the host's classes. A taller action link
    // must not pull short links to the top when the source explicitly centers.
    for (const alignment of ['normal', 'center']) {
        await page.setContent(`<style>
            .wp-block-navigation{display:flex;align-items:center}
            .wp-block-navigation__container{display:flex;align-items:inherit;padding:0;margin:0;gap:20px}
            .wp-block-navigation-item{display:flex;align-items:center}
            .wp-block-navigation-item__content{line-height:20px}
            .action{padding:10px}
            ${alignment === 'center' ? fixture.centeredCss : fixture.css}
        </style><nav class="menu wp-block-navigation blocks-engine-anchor-items-navigation">
            <ul class="menu wp-block-navigation wp-block-navigation__container blocks-engine-anchor-items-navigation">
                <li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content">Short</a></li>
                <li class="wp-block-navigation-item action"><a class="wp-block-navigation-item__content">Action</a></li>
            </ul></nav>`);
        assert.equal(await page.locator('nav').evaluate(el => getComputedStyle(el).alignItems), alignment);
        const boxes = await page.locator('a').evaluateAll(els => els.map(el => el.getBoundingClientRect().y));
        assert.equal(boxes[1] - boxes[0], alignment === 'normal' ? 10 : 0, `source ${alignment} alignment`);
    }
    console.log('Bare-anchor default and authored alignment passed.');
} finally {
    await browser.close();
}
