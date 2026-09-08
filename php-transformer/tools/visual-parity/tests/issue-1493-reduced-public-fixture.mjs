import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

// Reduced public fixture for #1493, not the unavailable /runs/run3-source full import.
const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const sourceFixture = `
  <style>
    .screen-reader-hint { display: none; }
    .overflow-menu { height: 0; overflow: hidden; }
    .aria-hidden-overflow { height: 0; overflow: hidden; }
    .css-panel { display: none; visibility: hidden; height: 0; overflow: hidden; }
    .css-trigger:hover + .css-panel, .css-trigger:focus + .css-panel { display: block; visibility: visible; height: auto; overflow: visible; }
    .aria-panel { display: none; visibility: hidden; height: 0; overflow: hidden; }
    .height-only { display: none; }
    .height-trigger:hover + .height-only { height: auto; }
    .collapsed-visibility { visibility: hidden; }
    .visibility-trigger:hover + .collapsed-visibility { visibility: collapse; }
  </style>
  <main>
    <p class="screen-reader-hint">Permanent accessibility hint</p>
    <p id="permanent-overflow" class="overflow-menu">Permanent overflow item</p>
    <p id="aria-hidden-overflow" class="aria-hidden-overflow" aria-hidden="true">Retained hidden overflow content</p>
    <div class="css-trigger">CSS reveal</div>
    <section id="css-panel" class="css-panel"><p>CSS-revealed panel content</p></section>
    <button aria-controls="aria-panel" aria-expanded="false">ARIA reveal</button>
    <section id="aria-panel" class="aria-panel"><p>ARIA-revealed panel content</p></section>
    <div class="height-trigger">Height only</div>
    <p id="height-only" class="height-only">Permanent display-hidden content</p>
    <div class="visibility-trigger">Visibility collapse</div>
    <p id="collapsed-visibility" class="collapsed-visibility">Permanent visibility-hidden content</p>
  </main>`;
const transformed = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$fixture = <<<'HTML'
${sourceFixture}
HTML;
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($fixture)->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''));
echo json_encode(array(
    'serializedBlocks' => (string) ($result['serialized_blocks'] ?? ''),
    'css' => implode("\\n", array_column($css, 'content')),
    'afterAuthorCss' => implode("\\n", array_column(array_filter($css, static fn(array $asset): bool => 'after-author' === ($asset['stylesheet_placement'] ?? '')), 'content')),
));
`, transformerRoot], { encoding: 'utf8' }));

assert.match(transformed.afterAuthorCss, /#css-panel\{display:revert!important;height:auto!important;overflow:visible!important;visibility:visible!important\}/);
assert.match(transformed.afterAuthorCss, /#aria-panel\{display:revert!important;height:auto!important;overflow:visible!important;visibility:visible!important\}/);
assert.doesNotMatch(transformed.afterAuthorCss, /screen-reader-hint[^{}]*\{[^}]*display:revert/);
assert.doesNotMatch(transformed.afterAuthorCss, /overflow-menu[^{}]*\{[^}]*height:auto/);
assert.doesNotMatch(transformed.afterAuthorCss, /#height-only\{[^}]*display:revert/);
assert.doesNotMatch(transformed.afterAuthorCss, /#collapsed-visibility\{[^}]*visibility:visible/);
assert.doesNotMatch(transformed.afterAuthorCss, /#aria-hidden-overflow\{[^}]*height:auto/);

const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await page.setContent(`<!doctype html><style>body{margin:0;font:16px/1.4 sans-serif}${transformed.css}</style>${transformed.serializedBlocks}`);
    const geometry = async (selector) => page.locator(selector).evaluate((element) => {
        const rect = element.getBoundingClientRect();
        const style = getComputedStyle(element);
        return { display: style.display, visibility: style.visibility, height: rect.height };
    });
    assert.deepEqual(await geometry('.screen-reader-hint'), { display: 'none', visibility: 'visible', height: 0 });
    assert.deepEqual(await geometry('#permanent-overflow'), { display: 'block', visibility: 'visible', height: 0 });
    assert.deepEqual(await geometry('#aria-hidden-overflow'), { display: 'block', visibility: 'visible', height: 0 });
    assert.deepEqual(await geometry('#height-only'), { display: 'none', visibility: 'visible', height: 0 });
    const permanentlyInvisible = await geometry('#collapsed-visibility');
    assert.equal(permanentlyInvisible.display, 'block');
    assert.equal(permanentlyInvisible.visibility, 'hidden');
    assert.ok(permanentlyInvisible.height > 0, 'visibility:hidden remains non-painted even when it retains layout geometry');

    for (const selector of ['#css-panel', '#aria-panel']) {
        const revealed = await geometry(selector);
        assert.equal(revealed.display, 'block');
        assert.equal(revealed.visibility, 'visible');
        assert.ok(revealed.height > 0, `${selector} is statically revealed by emitted repair CSS`);
    }
    await page.screenshot({ path: '/var/folders/lr/c_cmmt7s0592m4njz99v5yb40000gn/T/opencode/issue-1493-reduced-public-fixture-1280x900.png', fullPage: true });
} finally {
    await browser.close();
}

console.log('Issue #1493 reduced public fixture geometry passed');
