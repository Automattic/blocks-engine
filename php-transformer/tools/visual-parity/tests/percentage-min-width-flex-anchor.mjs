import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { wordpressButtonCss } from './wordpress-button-css.mjs';

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

// A flex-item anchor whose `min-width` is a percentage resolves it against
// the flex container. The imported wrapper chain shrink-wraps the link, so a
// percentage restated on the link resolves against its own wrapper and
// collapses to the label width.
const sourceCss = `.col{display:flex;flex-direction:column;width:640px}
.pill{box-sizing:border-box;display:flex;justify-content:center;align-items:center;width:min-content;min-width:50%;align-self:ALIGN;border:1px solid #000;padding:4px;text-decoration:none;font:12px/1 sans-serif}`;
const fixture = '<div class="col"><p>Intro</p><a class="pill" href="/a"><span>SHOP</span></a></div>';

const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 800, height: 400 } });
    for (const align of ['flex-start', 'center']) {
        const css = sourceCss.replace('ALIGN', align);
        const transformed = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''));
echo json_encode(array('serializedBlocks' => (string) ($result['serialized_blocks'] ?? ''), 'css' => implode("\\n", array_column($css, 'content'))));
`, transformerRoot, Buffer.from(`<style>${css}</style>${fixture}`).toString('base64')], { encoding: 'utf8' }));
        assert.ok(transformed.serializedBlocks.includes('wp-block-button__link'), 'anchor becomes a native button');

        await page.setContent(`<!doctype html><style>body{margin:0}${css}</style>${fixture}`);
        const source = await page.locator('.pill').evaluate((element) => element.getBoundingClientRect().width);
        await page.setContent(`<!doctype html><style>body{margin:0}${wordpressButtonCss}${transformed.css}</style>${transformed.serializedBlocks}`);
        const imported = await page.locator('.wp-block-button__link').evaluate((element) => element.getBoundingClientRect().width);

        assert.equal(source, 320, `source width (${align}): ${source}`);
        assert.ok(Math.abs(imported - source) < 1, `align-self:${align}: imported link is ${imported}px, source ${source}px`);
        console.log(`align-self:${align}: source=${source} imported=${imported}`);
    }
} finally {
    await browser.close();
}

console.log('Percentage min-width flex anchor geometry passed');
