// A post date the author lays out as a block (`<time class="post-date">` with
// `.post-date{display:block}`) lowers to an inline layout carrier paragraph.
// A boxless (display:contents) carrier is the RichText root but cannot be hit
// or focused, so a real click on the date in the editor canvas landed on the
// enclosing group instead of the paragraph (issue #5901). Around a block leaf
// an inline box lays out exactly like the boxless carrier, so the front end
// and the editor both keep that box: it takes a click, and the canvas shows
// the same paragraph box the front end does.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');

const root = new URL('../..', import.meta.url).pathname;
const source = '<style>.title-desc-wrapper{text-align:center;font-size:18px}.title-desc-wrapper .post-date{display:block;font-size:24px;line-height:1.125em;margin-bottom:.75em}.post-author{font-size:12px}</style>'
    + '<main><div class="title-desc-wrapper"><time class="post-date" datetime="2014-01-23">January 23, 2014</time><div class="post-author">George Zhang</div></div></main>';
const code = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, root], { input: source, encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css');
const editorCss = css.filter(asset => asset.stylesheet_target !== 'frontend').map(asset => asset.content).join('\n');
const frontEndCss = css.filter(asset => asset.stylesheet_target !== 'editor').map(asset => asset.content).join('\n');
assert.match(result.serialized_blocks, /<p class="blocks-engine-inline-layout-carrier[^"]*"><time\b/, 'the date line lowers to an inline layout carrier');

const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const carrier = page.locator('p.blocks-engine-inline-layout-carrier');
    const geometry = () => page.evaluate(() => [...document.querySelectorAll('time, .post-author')].map(node => {
        const box = node.getBoundingClientRect();
        return [box.x, box.y, box.width, box.height].map(Math.round).join(',');
    }));

    // Front end: the carrier has a box, and everything lays out as it does
    // around a boxless carrier.
    await page.setContent(`<style>${frontEndCss}</style>${result.serialized_blocks}`);
    const boxed = await geometry();
    assert.ok((await carrier.evaluate(node => node.getBoundingClientRect().width)) > 0, 'the front-end carrier has a box');
    await page.addStyleTag({ content: 'p.blocks-engine-inline-layout-carrier{display:contents!important}' });
    assert.deepEqual(await geometry(), boxed, 'the boxed carrier keeps the boxless layout');

    // Editor: core's unselected RichText root. A real mouse click must reach
    // the paragraph and place the caret before Gutenberg selects the block.
    await page.setContent(`<style>${editorCss}</style><div class="editor-styles-wrapper"><div class="wp-block-group" tabindex="0">${result.serialized_blocks}</div></div>`);
    await carrier.evaluate(node => node.setAttribute('contenteditable', 'true'));
    await carrier.click({ timeout: 2000 });
    assert.equal(await page.evaluate(() => document.activeElement?.tagName), 'P', 'an unselected carrier takes focus on a real click');
    console.log('inline layout carrier block leaf click browser passed');
} finally {
    await browser.close();
}
