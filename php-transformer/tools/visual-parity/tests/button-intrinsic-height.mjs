import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { wordpressButtonCss } from './wordpress-button-css.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const source = readFileSync(path.join(root, 'tests/fixtures/button-intrinsic-height.html'), 'utf8');
const output = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''));
echo json_encode(array('html' => $result['serialized_blocks'], 'css' => implode("\\n", array_column($css, 'content'))));
`, root, Buffer.from(source).toString('base64')], { encoding: 'utf8' }));
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 600 } });
    const measure = async (html, selector) => {
        await page.setContent(html);
        return page.locator(selector).evaluateAll(elements => elements.map(element => {
            const box = element.getBoundingClientRect();
            const row = element.closest('.row').getBoundingClientRect();
            return { height: box.height, center: box.top + box.height / 2 - (row.top + row.height / 2) };
        }));
    };
    const expected = await measure(source, 'button');
    // Include core's height rule explicitly: display:contents wrappers do not
    // stop it matching the link, which is now a direct item of the source row.
    const actual = await measure(`<!doctype html><style>${wordpressButtonCss}.wp-block-button__link{height:100%}${output.css}</style>${output.html}`, '.wp-block-button__link');
    console.log({ expected, actual });
    assert.equal(expected.length, 3);
    assert.equal(actual.length, expected.length, 'all source controls must survive conversion');
    expected.forEach((control, index) => {
        assert.ok(Math.abs(actual[index].height - control.height) < 0.1, `control ${index} must retain source height`);
        assert.ok(Math.abs(actual[index].center - control.center) < 0.1, `control ${index} must retain source centering`);
    });
    assert.equal(expected[0].height, 17);
    assert.equal(expected[1].height, 17);
    assert.equal(expected[2].height, 55, 'authored percentage height must still stretch');
} finally {
    await browser.close();
}
console.log('Intrinsic and stretched button geometry passed');
