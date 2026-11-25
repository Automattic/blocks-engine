// A flex-row label (icon + day name) lowers to a boxless inline layout carrier
// paragraph. Its box resets were `!important`, so padding the user set on that
// paragraph through the editor's spacing control saved but never rendered:
// the block ignored its own style control (issue #7227). The carrier stays
// boxless at import; once the block's own controls set spacing or a border,
// those settings win and the carrier takes a box to show them.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');

const root = new URL('../..', import.meta.url).pathname;
const source = '<style>.row{display:flex;justify-content:space-between;gap:1rem}.flex{display:flex}.items-center{align-items:center}.gap-3{gap:.75rem}.icon{width:1rem;height:1rem}.muted{color:rgb(21 40 31 / .85)}</style>'
    + '<main><div class="row"><span class="flex items-center gap-3 muted"><svg viewBox="0 0 24 24" class="icon"><circle cx="12" cy="12" r="10"></circle></svg>Monday</span><span>9 AM</span></div></main>';
const code = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, root], { input: source, encoding: 'utf8' }));
const frontEndCss = result.assets.filter(asset => asset.kind === 'css' && asset.stylesheet_target !== 'editor').map(asset => asset.content).join('\n');
assert.match(result.serialized_blocks, /<p class="blocks-engine-inline-layout-carrier">/, 'the row label lowers to an inline layout carrier');

// What core saves when the editor sets the paragraph's top padding.
const edited = result.serialized_blocks.replace('<p class="blocks-engine-inline-layout-carrier">', '<p class="blocks-engine-inline-layout-carrier" style="padding-top:23px">');

const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const box = () => page.locator('p.blocks-engine-inline-layout-carrier').evaluate((node) => {
        const style = getComputedStyle(node);
        return { display: style.display, paddingTop: style.paddingTop, height: node.getBoundingClientRect().height };
    });

    await page.setContent(`<style>* { padding: 7px; margin: 5px }</style><style>${frontEndCss}</style>${result.serialized_blocks}`);
    const imported = await box();
    assert.equal(imported.display, 'contents', 'the imported carrier stays boxless');
    assert.equal(imported.paddingTop, '0px', 'the imported carrier keeps its reset over other spacing');

    await page.setContent(`<style>* { padding: 7px; margin: 5px }</style><style>${frontEndCss}</style>${edited}`);
    const styled = await box();
    assert.equal(styled.paddingTop, '23px', 'the padding control styles the carrier');
    assert.notEqual(styled.display, 'contents', 'the styled carrier has a box that shows its padding');
    assert.ok(styled.height >= 23, 'the padding renders');
    const label = await page.locator('p.blocks-engine-inline-layout-carrier').evaluate(node => getComputedStyle(node).marginTop);
    assert.equal(label, '0px', 'a margin the block did not set stays reset');
    console.log('inline layout carrier box controls browser passed');
} finally {
    await browser.close();
}
