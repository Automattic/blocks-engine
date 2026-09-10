import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const sourceFixture = `<style>
  :root { --scaling-factor: 1448px; --scrollbar-width: 8px; }
  .cta { box-sizing:border-box; display:block; height:auto; min-height:max(.5px,.0362559 * (var(--scaling-factor) - var(--scrollbar-width))); padding:12px 24px; background:#173b64; color:#fff; }
  @media (max-width:600px) { :root { --scaling-factor:399.164682px; } .cta { height:max(.5px,.1175977 * (var(--scaling-factor) - var(--scrollbar-width))); min-height:0; } }
</style><main><a class="cta" href="/quote">GET A QUOTE</a></main>`;
const transformed = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''));
echo json_encode(array('serializedBlocks' => (string) ($result['serialized_blocks'] ?? ''), 'css' => implode("\\n", array_column($css, 'content'))));
`, transformerRoot, Buffer.from(sourceFixture).toString('base64')], { encoding: 'utf8' }));

// WordPress core button CSS that competes with carried author declarations.
const wordpressButtonCss = `.wp-block-buttons{box-sizing:border-box}.wp-block-button{box-sizing:border-box}.wp-block-button__link{box-sizing:border-box;cursor:pointer;display:inline-block;min-height:10px;padding:calc(.667em + 2px) calc(1.333em + 2px);text-align:center;word-break:break-word}`;
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 400 } });
    await page.setContent(`<!doctype html><style>body{margin:0;font:16px/1.2 sans-serif}${wordpressButtonCss}${transformed.css}</style>${transformed.serializedBlocks}`);
    const geometry = () => page.locator('.wp-block-buttons, .wp-block-button, .wp-block-button__link').evaluateAll((elements) => elements.map((element) => element.getBoundingClientRect().height));

    const desktop = await geometry();
    assert.ok(Math.abs(desktop[0] - 52.2085) < 0.02, `desktop outer minimum height: ${desktop[0]}`);
    assert.ok(desktop.every((height) => Math.abs(height - desktop[0]) < 0.02), `desktop carriers share the authored minimum: ${desktop}`);

    await page.setViewportSize({ width: 600, height: 400 });
    const mobile = await geometry();
    assert.ok(Math.abs(mobile[0] - 46) < 0.02, `mobile explicit math height: ${mobile[0]}`);
    assert.ok(mobile.every((height) => Math.abs(height - mobile[0]) < 0.02), `mobile carriers fill the authored height: ${mobile}`);
} finally {
    await browser.close();
}

console.log('Button height ownership geometry passed');
