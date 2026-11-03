import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {createRequire} from 'node:module';
const require = createRequire(new URL('../../tools/visual-parity/package.json', import.meta.url));
const {chromium} = require('playwright');

// Exercise the saved blocks through real WordPress, not a model renderer.
const container = process.env.SOCIAL_SVG_WP_CONTAINER;
assert.ok(container, 'Set SOCIAL_SVG_WP_CONTAINER to a running WordPress container');
const fixture = JSON.parse(execFileSync('php', ['tests/unit/social-links-captured-svg.php', '--json'], {encoding: 'utf8'}));
const rendered = execFileSync('docker', ['exec', '-i', container, 'php', '-r', 'require "/var/www/html/wp-load.php"; echo do_blocks(stream_get_contents(STDIN));'], {input: fixture.result.serialized_blocks, encoding: 'utf8'});
const css = fixture.result.assets.filter(asset => asset.mime_type === 'text/css').map(asset => asset.content).join('\n');
const browser = await chromium.launch({headless: true});
try {
    const page = await browser.newPage({viewport: {width: 1440, height: 900}});
    const measure = () => page.locator('a').evaluateAll(elements => elements.map(a => {
        const svg = a.querySelector('svg');
        const path = svg.querySelector('path');
        const rect = svg.getBoundingClientRect();
        return {href: a.getAttribute('href'), label: a.getAttribute('aria-label'), path: path.getAttribute('d'), fill: getComputedStyle(path).fill, stroke: getComputedStyle(path).stroke, width: rect.width, height: rect.height};
    }));
    await page.setContent(fixture.source);
    const expected = await measure();
    await page.locator('.footer-social-link').hover();
    const hover = (await measure())[3].stroke;
    assert.notEqual(hover, expected[3].stroke);
    await page.setContent(`<style>${css}</style>${rendered}`);
    await page.mouse.move(1000, 800);
    const actual = await measure();
    assert.deepEqual(actual, expected);
    for (const glyph of actual.slice(0, 3)) {
        assert.equal(glyph.fill, 'rgb(255, 255, 255)');
        assert.equal(glyph.width, 12);
        assert.equal(glyph.height, 12);
    }
    await page.locator('.footer-social-link').hover();
    assert.equal((await measure())[3].stroke, hover);
    await page.mouse.move(1000, 800);
    await page.locator('.footer-social-link').focus();
    assert.equal((await measure())[3].stroke, hover);
    console.log('WordPress-rendered social link artwork, size, hover and focus parity passed.');
} finally {
    await browser.close();
}
