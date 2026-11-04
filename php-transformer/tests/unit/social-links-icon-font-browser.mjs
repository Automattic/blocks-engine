import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress block-library/style.min.css');
const core = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./social-links-icon-font.php', import.meta.url)), '--json'], {encoding: 'utf8'}));
// Use the same dynamic Core topology as the social-profile flex browser probe,
// including a service SVG so failing to suppress it changes the pixel paint.
const candidate = fixture.candidate.replace(/<!-- wp:social-link (\{.*?\}) \/-->/g, (_, json) => {
    const attrs = JSON.parse(json);
    const anchor = attrs.metadata.blocksEngineSocialAnchor;
    return `<li class="wp-social-link wp-social-link-${attrs.service} wp-block-social-link ${attrs.className}"><a class="wp-block-social-link-anchor ${anchor.class}" href="${attrs.url}"><svg width="24" height="24"><path d="M0 0h24v24H0z"/></svg><span class="wp-block-social-link-label screen-reader-text">${attrs.label}</span></a></li>`;
});
const browser = await chromium.launch({headless: true});
try {
    const page = await browser.newPage({deviceScaleFactor: 1});
    for (const width of [390, 768, 1440]) {
        await page.setViewportSize({width, height: 800});
        await page.setContent(fixture.source);
        const source = await page.locator('a').all();
        const paints = [];
        for (const anchor of source) paints.push(await anchor.screenshot());
        await page.setContent(`<style>${fixture.css}</style>${candidate}`);
        await page.addStyleTag({content: core + '.screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(1px,1px,1px,1px);clip-path:inset(50%);white-space:nowrap}'});
        const anchors = await page.locator('a').all();
        assert.equal(anchors.length, 3);
        for (let i = 0; i < anchors.length; i++) {
            const glyph = await anchors[i].evaluate(a => {
                const style = getComputedStyle(a, '::before');
                return {width: style.width, height: style.height, size: style.fontSize};
            });
            assert.deepEqual(glyph, {width: '19px', height: '19px', size: '18.75px'});
            assert.deepEqual(await anchors[i].screenshot(), paints[i], `Source pixel paint at ${width}, glyph ${i}`);
        }
    }
    console.log('Social icon-font browser parity passed.');
} finally {
    await browser.close();
}
