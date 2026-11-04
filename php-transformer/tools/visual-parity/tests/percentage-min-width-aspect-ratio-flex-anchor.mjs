import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { wordpressButtonCss } from './wordpress-button-css.mjs';

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
// The two-line content height transfers through the ratio to the intrinsic
// width. Neither a wrapper percentage nor a single-line label reproduces it.
const css = `.col{display:flex;flex-direction:column;width:640px}
.pill{box-sizing:border-box;display:flex;justify-content:center;align-items:center;width:min-content;min-width:16%;aspect-ratio:1/0.18778;align-self:center;border:1px solid #000;padding:0 1px;text-decoration:none;font:12.75px/1.2 sans-serif}
.pill span{white-space:pre-line}`;
const fixture = '<div class="col"><p>Intro</p><a class="pill" href="/a"><span>VIEW ALL DECKS</span></a></div>';
const transformed = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''));
echo json_encode(array('serializedBlocks' => (string) ($result['serialized_blocks'] ?? ''), 'css' => implode("\\n", array_column($css, 'content'))));
`, transformerRoot, Buffer.from(`<style>${css}</style>${fixture}`).toString('base64')], { encoding: 'utf8' }));
assert.ok(transformed.serializedBlocks.includes('wp-block-button__link'), 'anchor becomes a native button');

const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 800, height: 400 } });
    const box = (element) => {
        const { x, y, width, height } = element.getBoundingClientRect();
        return { x, y, width, height };
    };
    await page.setContent(`<!doctype html><style>body{margin:0}${css}</style>${fixture}`);
    const source = await page.locator('.pill').evaluate(box);
    assert.ok(Math.abs(source.width - 173.6) < 1 && Math.abs(source.height - 32.6) < 1, 'fixture exercises multiline ratio sizing');
    await page.setContent(`<!doctype html><style>body{margin:0}${wordpressButtonCss}${transformed.css}</style>${transformed.serializedBlocks}`);
    const imported = await page.locator('.wp-block-button__link').evaluate(box);
    console.log({ source, imported });
    for (const dimension of ['x', 'y', 'width', 'height']) {
        assert.ok(Math.abs(imported[dimension] - source[dimension]) < 1, `${dimension}: imported ${imported[dimension]}, source ${source[dimension]}`);
    }
} finally {
    await browser.close();
}
console.log('Percentage min-width intrinsic aspect-ratio flex anchor geometry passed');
