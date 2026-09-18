import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { wordpressButtonCss } from './wordpress-button-css.mjs';

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

// Source: work-page filter row. Six bare <button> flex items. Wrapping each in
// core/buttons made the wrapper the flex item (38 → 56). Neutralizing both
// wrappers without transferring width:fit-content made each button 1280px
// (56 → 286).
const labels = ['All', 'Kitchens', 'Bathrooms', 'Whole-Home', 'Outdoor', 'Commercial'];
const sourceFixture = `<div class="row">${labels.map((label) => `<button class="chip" type="button">${label}</button>`).join('')}</div>`;
const sourceCss = `button{border:0;margin:0;background:transparent;font:inherit}.row{display:flex;flex-wrap:wrap;gap:12px;justify-content:center;font:16px/1.2 sans-serif}.chip{padding:8px 20px;font-size:14px;line-height:20px;font-weight:600;letter-spacing:0.35px}`;
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
            return {
                rowHeight: row ? row.getBoundingClientRect().height : 0,
                widths: items.map((item) => Math.round(item.getBoundingClientRect().width)),
            };
        }, itemSelector);
    };

    const source = await measure(`<!doctype html><style>body{margin:0}${sourceCss}</style>${sourceFixture}`, 'button');
    const imported = await measure(
        `<!doctype html><style>body{margin:0}${wordpressButtonCss}${transformed.css}</style>${transformed.serializedBlocks}`,
        '.wp-block-button__link, button'
    );

    // Live site (work filter row, production font) measured 38px tall with
    // shrink-to-fit widths 67/116/136/146/123/168. This fixture uses the same
    // padding/line-height/gap contract and asserts imported == source.
    // Wrappers as flex items grew the live row 38 → 56; dropped shrink-to-fit
    // made each button 1280px and stacked the row 56 → 286.
    assert.ok(source.rowHeight >= 36 && source.rowHeight <= 42, `source row height: ${source.rowHeight}`);
    assert.ok(
        Math.abs(imported.rowHeight - source.rowHeight) < 2,
        `flex button row height ${imported.rowHeight} drifted from source ${source.rowHeight}`
    );
    assert.ok(imported.rowHeight < 50, `row inflated by wrapper boxes: ${imported.rowHeight}`);
    assert.ok(imported.rowHeight < 200, `row stacked full-width buttons: ${imported.rowHeight}`);
    assert.equal(imported.widths.length, 6, `imported buttons: ${imported.widths}`);
    for (const [index, width] of imported.widths.entries()) {
        assert.ok(
            Math.abs(width - source.widths[index]) < 8,
            `${labels[index]} width ${width} drifted from source ${source.widths[index]} (full-row stretch would be 1280)`
        );
        assert.ok(width < 400, `${labels[index]} filled the row: ${width}`);
    }
    console.log(`Flex button row: source=${JSON.stringify(source)} imported=${JSON.stringify(imported)}`);
} finally {
    await browser.close();
}

console.log('Flex button row shrink-to-fit geometry passed');
