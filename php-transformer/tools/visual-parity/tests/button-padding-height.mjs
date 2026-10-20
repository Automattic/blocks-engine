import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { wordpressButtonCss } from './wordpress-button-css.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const source = readFileSync(path.join(root, 'tests/fixtures/button-padding-height.html'), 'utf8');
const transformed = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''));
echo json_encode(array('html' => $result['serialized_blocks'], 'css' => implode("\\n", array_column($css, 'content'))));
`, root, Buffer.from(source).toString('base64')], { encoding: 'utf8' }));
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 600 } });
    const measure = async (html, controls) => {
        await page.setContent(html);
        return page.evaluate((selector) => {
            const heights = [...document.querySelectorAll(selector)].map((element) => element.getBoundingClientRect().height);
            const trustTop = [...document.querySelectorAll('span, p')].find((element) => element.textContent === 'Fast turnaround').getBoundingClientRect().top;
            return { heights, trustTop };
        }, controls);
    };
    const before = await measure(source, '.row, button');
    const after = await measure(`<!doctype html><style>${wordpressButtonCss}${transformed.css}</style>${transformed.html}`, '.row, .wp-block-buttons, .wp-block-button, .wp-block-button__link');
    console.log(`Padded button geometry: source=${JSON.stringify(before)} transformed=${JSON.stringify(after)}`);
    assert.ok(before.heights.every((height) => Math.abs(height - 48) < 0.1));
    assert.ok(after.heights.every((height) => Math.abs(height - 48) < 0.1), 'row and all button carriers must contain the padded 48px control');
    assert.ok(Math.abs(after.trustTop - before.trustTop) < 0.1, 'following content must retain its source position');
} finally {
    await browser.close();
}
console.log('Padded button height geometry passed');
