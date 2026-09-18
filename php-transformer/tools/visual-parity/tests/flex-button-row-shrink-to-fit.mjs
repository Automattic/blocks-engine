import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { wordpressButtonCss } from './wordpress-button-css.mjs';

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

// Source: work-page filter row. Six bare <button> flex items.
// #1983 neutralized both wrappers (display:contents) so they stopped being extra
// flex items (38 → 56), but left width:fit-content on .wp-block-button — a
// contents box, so the declaration never applied. Core's
// `.wp-block-buttons .wp-block-button__link { width: 100% }` still matches
// (contents does not remove the ancestor), each BUTTON became 1280px, and the
// row stacked: 6 × ~38 + 5 × 12 gap = 286.
// Live site (Inter, production font): height 38, widths 67/116/136/146/123/168.
const labels = ['All', 'Kitchens', 'Bathrooms', 'Whole-Home', 'Outdoor', 'Commercial'];
const liveWidths = [67, 116, 136, 146, 123, 168];
const sourceFixture = `<div class="row">${labels.map((label) => `<button class="chip" type="button">${label}</button>`).join('')}</div>`;
const sourceCss = [
    'button{border:0;margin:0;background:transparent;font:inherit}',
    '.row{display:flex;flex-wrap:wrap;gap:12px;justify-content:center;font:16px/1.2 sans-serif}',
    // content-box + 8+20+8 padding + 1px border = 38px tall, independent of font.
    '.chip{box-sizing:content-box;padding:8px 20px;font-size:14px;line-height:20px;font-weight:600;letter-spacing:0.35px;text-transform:uppercase;border:1px solid transparent}',
].join('');
const transformed = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''));
echo json_encode(array('serializedBlocks' => (string) ($result['serialized_blocks'] ?? ''), 'css' => implode("\\n", array_column($css, 'content'))));
`, transformerRoot, Buffer.from(`<style>${sourceCss}</style>${sourceFixture}`).toString('base64')], { encoding: 'utf8' }));

const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 400 } });
    const measure = async (html, itemSelector) => {
        await page.setContent(html);
        return page.evaluate((selector) => {
            const row = document.querySelector('.row, .wp-block-group.row');
            const items = [...(row ? row.querySelectorAll(selector) : [])].filter((node) => getComputedStyle(node).display !== 'contents');
            const firstButton = row ? row.querySelector('.wp-block-button') : null;
            const firstLink = row ? row.querySelector('.wp-block-button__link, button.chip, button') : null;
            return {
                rowHeight: row ? Math.round(row.getBoundingClientRect().height) : 0,
                widths: items.map((item) => Math.round(item.getBoundingClientRect().width)),
                buttonDisplay: firstButton ? getComputedStyle(firstButton).display : '',
                linkDisplay: firstLink ? getComputedStyle(firstLink).display : '',
                linkWidth: firstLink ? Math.round(firstLink.getBoundingClientRect().width) : 0,
            };
        }, itemSelector);
    };

    const source = await measure(`<!doctype html><style>body{margin:0}${sourceCss}</style>${sourceFixture}`, 'button');
    const imported = await measure(
        `<!doctype html><style>body{margin:0}${wordpressButtonCss}${transformed.css}</style>${transformed.serializedBlocks}`,
        '.wp-block-button__link, button'
    );

    assert.equal(source.rowHeight, 38, `source row height: ${source.rowHeight}`);
    assert.ok(imported.rowHeight < 50, `wrapper boxes inflated the row to ${imported.rowHeight} (56 = extra flex item)`);
    assert.ok(imported.rowHeight < 200, `full-width buttons stacked the row to ${imported.rowHeight} (288 = 6×38 + 5×12)`);
    assert.equal(imported.rowHeight, 38, `imported row height ${imported.rowHeight} (live site 38; 56 = extra flex item; 288 = 6×38 + 5×12)`);
    assert.equal(imported.widths.length, 6, `imported buttons: ${imported.widths}`);
    assert.equal(imported.buttonDisplay, 'contents', `inner wrapper still generates a box: ${imported.buttonDisplay}`);
    for (const [index, width] of imported.widths.entries()) {
        assert.notEqual(width, 1280, `${labels[index]} filled the 1280px row`);
        assert.ok(
            Math.abs(width - source.widths[index]) < 8,
            `${labels[index]} width ${width} drifted from source ${source.widths[index]} (live ${liveWidths[index]}; full-row stretch would be 1280)`
        );
        assert.ok(width < 400, `${labels[index]} filled the row: ${width}`);
        assert.ok(width > 20, `${labels[index]} collapsed (#1386): ${width}`);
    }
    console.log(`Flex button row: source=${JSON.stringify(source)} imported=${JSON.stringify(imported)} liveWidths=${JSON.stringify(liveWidths)}`);
} finally {
    await browser.close();
}

console.log('Flex button row shrink-to-fit geometry passed');
