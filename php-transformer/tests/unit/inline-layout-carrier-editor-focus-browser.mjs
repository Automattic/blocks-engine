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
// The author styles the date itself as a block (Squarespace's `.post-date`),
// so the carrier's only child is block-level: an inline carrier would hold a
// block inside an inline editing host, where replacing the selected text
// deletes the date and drops the typed text (the whole-site /news-bedford/).
const source = '<style>.title-desc-wrapper{text-align:center}.title-desc-wrapper .post-date{display:block;font-style:italic}.post-author{font-size:12px}</style>'
    + '<main><div class="title-desc-wrapper"><time class="published post-date" datetime="2014-01-23">January 23, 2014</time><p class="post-author">George Zhang</p></div></main>';
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
    const frontendMarkup = await page.locator('body').innerHTML();
    // Even if an editor asset is loaded for a preview, saved markup must not
    // acquire a new box. This also catches accidentally unscoped repairs.
    await page.addStyleTag({ content: editorCss });
    assert.equal(await display(), 'contents', 'editor repairs do not restyle the frontend');
    assert.equal(await page.locator('body').innerHTML(), frontendMarkup, 'the repair leaves saved markup untouched');

    // Editor: mirror core's RichText before selection, with the paragraph
    // itself as the contenteditable root.
    await page.setContent(`<style>${editorCss}</style><div class="editor-styles-wrapper">${result.serialized_blocks}</div>`);
    await carrier.evaluate(node => node.setAttribute('contenteditable', 'true'));
    const ordinary = page.locator('p.post-author');
    await ordinary.evaluate(node => node.setAttribute('contenteditable', 'true'));
    assert.equal(await ordinary.evaluate(node => getComputedStyle(node).display), 'block', 'ordinary editable paragraphs keep their block layout');
    assert.equal(await display(), 'block', 'an unselected editable carrier is a block container, whatever its content displays as');
    // Direct replacement must work without first manufacturing Gutenberg's
    // selection class: the initial focus is what delivers input to RichText.
    await carrier.evaluate(node => {
        node.addEventListener('input', () => {
            if (node.ownerDocument.activeElement === node) node.dataset.savedContent = node.textContent;
        });
    });
    await carrier.fill('Import loop edit');
    assert.equal(await page.evaluate(() => document.activeElement?.tagName), 'P', 'direct replacement focuses the unselected carrier');
    assert.equal(await carrier.getAttribute('data-saved-content'), 'Import loop edit', 'focused input reaches the editor state listener');
    assert.equal(await carrier.textContent(), 'Import loop edit', 'typed text lands in the carrier');

    // Replacing the text of an already selected carrier, as an editor does
    // after clicking into it, keeps the typed text too.
    await page.setContent(`<style>${editorCss}</style><div class="editor-styles-wrapper">${result.serialized_blocks}</div>`);
    await carrier.evaluate(node => node.setAttribute('contenteditable', 'true'));
    await carrier.click();
    await carrier.evaluate(node => node.classList.add('is-selected'));
    await carrier.fill('Selected carrier edit');
    assert.equal(await carrier.textContent(), 'Selected carrier edit', 'typed text replaces the selected carrier text');
    console.log('inline layout carrier editor focus browser passed');
} finally {
    await browser.close();
}
