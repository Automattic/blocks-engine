import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress block-library/style.min.css');
const core = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./social-profile-flex-cluster.php', import.meta.url)), '--json'], {encoding: 'utf8'}));
const browser = await chromium.launch({headless: true});
try {
    const page = await browser.newPage({viewport: {width: 390, height: 800}});
    const measure = () => page.locator('.follow > .links').evaluate(cluster => {
        const anchors = [...cluster.querySelectorAll('a')].map(a => a.getBoundingClientRect());
        return {height: cluster.getBoundingClientRect().height, anchor: anchors[0].height, width: anchors[0].width, spacing: anchors[1].x - anchors[0].x};
    });
    await page.setContent(fixture.source);
    const source = await measure();
    // Social children are dynamic. Model Core's li/anchor box topology using
    // the saved child attributes (the browser probe needs no WordPress DB).
    const candidateMarkup = fixture.candidate.replace(/<!-- wp:social-link (\{.*?\}) \/-->/g, (_, json) => {
        const attrs = JSON.parse(json);
        const anchor = attrs.metadata.blocksEngineSocialAnchor;
        return `<li class="wp-social-link wp-social-link-${attrs.service} wp-block-social-link"><a class="wp-block-social-link-anchor ${anchor.class}" href="${attrs.url}"></a></li>`;
    });
    await page.setContent(`<style>${fixture.css}</style>${candidateMarkup}`);
    // Core loaded last must not restore inline-block on the generated items.
    await page.addStyleTag({content: core});
    const candidate = await measure();
    for (const box of [source, candidate]) {
        assert.ok(Math.abs(box.spacing - box.width - 7.5) < 0.05, JSON.stringify(box));
        assert.ok(Math.abs(box.height - box.anchor) < 0.05, JSON.stringify(box));
    }
    assert.deepEqual(candidate, source);
    console.log('Social profile flex cluster browser passed.');
} finally {
    await browser.close();
}
