// An authored `p:empty{display:none}` reaches the editor through a
// `[data-empty="true"]` variant, so an empty source paragraph stays hidden as
// it is saved. While an author replaces a paragraph's text, its contenteditable
// DOM is briefly `:empty` (and then `data-empty`): the authored rule hid the
// focused RichText, the typed text never landed, and its block toolbar
// anchored to a zero box over the canvas top, where it covered the header
// logo's image block (issue #5920).
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');

const root = new URL('../..', import.meta.url).pathname;
const source = '<style>.desc-wrapper p{margin:20px auto}.desc-wrapper p:empty{display:none}</style>'
    + '<main><div class="desc-wrapper"><p>Sustainability</p><p></p><p>Starts with you</p></div></main>';
const code = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, root], { input: source, encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
// The repair is editor-only: front-end stylesheets keep the authored rule as written.
const frontEndCss = result.assets.filter(asset => asset.kind === 'css' && asset.stylesheet_target !== 'editor').map(asset => asset.content).join('\n');
assert.ok(!/contenteditable|:focus/.test(frontEndCss), `front-end CSS carries no editor repair: ${frontEndCss}`);

const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.setContent(`<style>${css}</style><div class="editor-styles-wrapper">${result.serialized_blocks}</div>`);
    // Mirror core's RichText: each paragraph is contenteditable and carries
    // `data-empty` while it holds no text.
    await page.locator('.desc-wrapper p').evaluateAll(nodes => nodes.forEach(node => {
        node.setAttribute('contenteditable', 'true');
        node.setAttribute('data-empty', String('' === node.textContent));
    }));
    const display = index => page.locator('.desc-wrapper p').nth(index).evaluate(node => getComputedStyle(node).display);

    assert.equal(await display(1), 'none', 'an empty source paragraph stays hidden in the editor, as saved');
    assert.equal(await display(0), 'block', 'a paragraph with text is shown');

    // The author selects the first paragraph's text and replaces it: the DOM
    // empties before core's store (and `data-empty`) catches up.
    const edited = page.locator('.desc-wrapper p').first();
    await edited.focus();
    await edited.evaluate(node => { node.textContent = ''; });
    assert.equal(await display(0), 'block', 'an editable paragraph whose DOM is momentarily empty stays shown');
    await edited.evaluate(node => node.setAttribute('data-empty', 'true'));
    assert.equal(await display(0), 'block', 'the focused paragraph the author cleared stays shown');
    const box = await edited.boundingBox();
    assert.ok(box && box.width > 0 && box.height > 0, `the focused empty paragraph keeps a box for its caret and toolbar: ${JSON.stringify(box)}`);
    await page.keyboard.type('Retyped');
    assert.equal(await edited.textContent(), 'Retyped', 'typed text lands in the focused paragraph');

    // Left empty and blurred, it matches the saved `:empty` rule again.
    await edited.evaluate(node => { node.textContent = ''; node.setAttribute('data-empty', 'true'); node.blur(); });
    assert.equal(await display(0), 'none', 'a blurred empty paragraph is hidden as it will be saved');
    console.log('focused empty rich text editor browser passed');
} finally {
    await browser.close();
}
