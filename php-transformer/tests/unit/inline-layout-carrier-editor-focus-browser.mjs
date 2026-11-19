// A lone inline element before a block (a post's `<time>` date line) lowers to
// a paragraph carrier that is `display:contents`, so its inline content lays
// out in the source's own box. In the editor that paragraph is the RichText
// root, and a boxless element cannot take focus: selecting its text to
// replace it left focus outside, and the typed text never reached the block,
// so the saved page kept the old date (issue #5797).
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');

const root = new URL('../..', import.meta.url).pathname;
const source = '<style>.title-desc-wrapper{text-align:center}.post-author{font-size:12px}</style>'
    + '<main><div class="title-desc-wrapper"><time class="published" datetime="2014-01-23">January 23, 2014</time><p class="post-author">George Zhang</p></div></main>';
const code = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, root], { input: source, encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css');
const editorCss = css.filter(asset => asset.stylesheet_target !== 'frontend').map(asset => asset.content).join('\n');
const frontEndCss = css.filter(asset => asset.stylesheet_target !== 'editor').map(asset => asset.content).join('\n');
assert.match(result.serialized_blocks, /<p class="blocks-engine-inline-layout-carrier"><time\b/, 'the date line lowers to an inline layout carrier');

const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const carrier = page.locator('p.blocks-engine-inline-layout-carrier');
    const display = () => carrier.evaluate(node => getComputedStyle(node).display);

    // Front end: the carrier stays boxless.
    await page.setContent(`<style>${frontEndCss}</style>${result.serialized_blocks}`);
    assert.equal(await display(), 'contents', 'the front-end carrier keeps the source layout');

    // Editor: mirror core's RichText, which makes the paragraph contenteditable
    // and marks it `is-selected` once the caret is placed in it.
    await page.setContent(`<style>${editorCss}</style><div class="editor-styles-wrapper">${result.serialized_blocks}</div>`);
    await carrier.evaluate(node => node.setAttribute('contenteditable', 'true'));
    assert.equal(await display(), 'contents', 'an unselected carrier keeps the front-end layout in the canvas');
    await carrier.evaluate(node => node.classList.add('is-selected'));
    await carrier.evaluate(node => node.focus());
    assert.equal(await page.evaluate(() => document.activeElement?.tagName), 'P', 'the selected carrier takes focus');
    await carrier.fill('Import loop edit');
    assert.equal(await carrier.textContent(), 'Import loop edit', 'typed text lands in the carrier');
    console.log('inline layout carrier editor focus browser passed');
} finally {
    await browser.close();
}
