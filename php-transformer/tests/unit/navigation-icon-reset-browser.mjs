import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./navigation-icon-reset-fixture.php', import.meta.url))], {encoding: 'utf8'}));
const browser = await chromium.launch({headless: true});
try {
    const page = await browser.newPage();
    await page.setContent(`<style>${fixture.css}</style>${fixture.candidate}`);
    // Site-wide projection may increase reset specificity and reorder assets.
    await page.addStyleTag({content: ':root .wp-block-navigation .wp-block-navigation__container>.wp-block-navigation-item:nth-child(1) :where(.wp-block-navigation-item__content):not(.be-c-0):not(.be-c-0):not(.be-c-0):not(.be-c-0){background:initial;display:block;width:100%;height:auto}'});
    const artwork = await page.locator('a').evaluate(a => ({
        image: getComputedStyle(a).backgroundImage,
        size: getComputedStyle(a).backgroundSize,
        repeat: getComputedStyle(a).backgroundRepeat,
        display: getComputedStyle(a).display,
        width: getComputedStyle(a).width,
        height: getComputedStyle(a).height,
    }));
    assert.match(artwork.image, /^url\("data:image\/svg\+xml,/, 'anchor resets must not erase replacement SVG artwork');
    assert.equal(artwork.size, 'contain');
    assert.equal(artwork.repeat, 'no-repeat');
    assert.equal(artwork.display, 'inline-block');
    assert.equal(artwork.width, '24px');
    assert.equal(artwork.height, '24px');
    console.log('Navigation replacement artwork survives projected anchor resets.');
} finally {
    await browser.close();
}
