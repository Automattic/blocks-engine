import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';

// A builder social link whose glyph carries its own paint, distinct from the
// anchor's text color (GoDaddy: `.c1-2z{color:#969696}` on the link,
// `.c1-60{color:#e2e2e2}` on its fill="currentColor" svg). Core's color reset
// on `.wp-block-social-link-anchor svg` must not repaint the converted glyph
// with the anchor's color.
const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress block-library/style.min.css');
const core = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const compile = fileURLToPath(new URL('./social-links-glyph-paint.php', import.meta.url));
const fixture = JSON.parse(execFileSync('php', [compile], {encoding: 'utf8'}));
// Core's dynamic social-link render: the item carries the icon color, the
// anchor its transported source attributes, the svg is core's service glyph.
const iconColor = fixture.candidate.match(/"iconColorValue":"([^"]+)"/)?.[1];
const candidate = fixture.candidate.replace(/<!-- wp:social-link (\{.*?\}) \/-->/g, (_, json) => {
    const attrs = JSON.parse(json);
    const anchor = attrs.metadata.blocksEngineSocialAnchor;
    return `<li style="color:${iconColor}" class="wp-social-link wp-social-link-${attrs.service} wp-block-social-link ${attrs.className}"><a class="wp-block-social-link-anchor ${anchor.class}" href="${attrs.url}"><svg width="24" height="24" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg><span class="wp-block-social-link-label screen-reader-text">${attrs.label}</span></a></li>`;
});
const browser = await chromium.launch({headless: true});
try {
    const page = await browser.newPage();
    await page.setContent(fixture.source);
    const source = await page.locator('svg').evaluate(svg => getComputedStyle(svg).fill);
    await page.setContent(`<style>${core}</style><style>${fixture.css}</style>${candidate}`);
    const copy = await page.locator('svg').evaluate(svg => getComputedStyle(svg).fill);
    assert.equal(source, 'rgb(226, 226, 226)');
    assert.equal(copy, source, 'Core social glyph keeps the source glyph paint');
    console.log('Social glyph paint browser parity passed.');
} finally {
    await browser.close();
}
