import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {createRequire} from 'node:module';
const require = createRequire(new URL('../../tools/visual-parity/package.json', import.meta.url));
const {chromium} = require('playwright');

// Use a real WordPress renderer, not a model of core's dynamic social SVGs.
const container = process.env.SOCIAL_SVG_WP_CONTAINER;
assert.ok(container, 'Set SOCIAL_SVG_WP_CONTAINER to a running WordPress container');
const fixture = JSON.parse(execFileSync('php', ['tests/unit/social-links-captured-svg.php', '--json'], {encoding: 'utf8'}));
const rendered = execFileSync('docker', ['exec', '-i', container, 'php', '-r', 'require "/var/www/html/wp-load.php"; echo do_blocks(stream_get_contents(STDIN));'], {input: fixture.result.serialized_blocks, encoding: 'utf8'});
const css = fixture.result.assets.filter(asset => asset.mime_type === 'text/css').map(asset => asset.content).join('\n');
const assets = new Map(fixture.result.assets.map(asset => [asset.path, asset]));
const browser = await chromium.launch({headless: true});
try {
    const page = await browser.newPage({viewport: {width: 1440, height: 900}});
    await page.route('http://social.test/**', route => {
        const path = new URL(route.request().url()).pathname.slice(1);
        const asset = assets.get(path);
        return route.fulfill({contentType: asset?.mime_type || 'text/html', body: asset?.content || `<style>${css}</style>${rendered}`});
    });
    await page.setContent(fixture.source);
    const expected = await page.locator('svg').evaluateAll(elements => elements.map(svg => ({path: svg.querySelector('path').getAttribute('d'), fill: getComputedStyle(svg.querySelector('path')).fill})));
    await page.goto('http://social.test/');
    const images = await page.locator('.bde-social-icons a img').evaluateAll(elements => elements.map(img => ({src: img.src, visible: img.getBoundingClientRect().width > 0 && img.getBoundingClientRect().height > 0, loaded: img.complete && img.naturalWidth > 0})));
    assert.equal(images.length, 3);
    for (const [index, image] of images.entries()) {
        assert.ok(image.visible && image.loaded, JSON.stringify(image));
        // Inspect the actual isolated SVG document, whose fill cannot inherit
        // the tile's blue text color across the image boundary.
        await page.goto(image.src);
        const actual = await page.locator('svg').evaluate(svg => ({path: svg.querySelector('path').getAttribute('d'), fill: getComputedStyle(svg.querySelector('path')).fill}));
        assert.deepEqual(actual, expected[index]);
        assert.equal(actual.fill, 'rgb(255, 255, 255)');
    }
    console.log('WordPress-rendered captured social SVG browser parity passed.');
} finally {
    await browser.close();
}
