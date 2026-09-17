import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const sourceFixture = `<style>
  .stage { position: relative; width: 400px; height: 400px; }
  .pin { position: absolute; }
  .actions { display: flex; gap: 16px; justify-content: center; }
  .btn { padding: 8px 16px; background: #111; color: #fff; }
</style>
<div class="stage" style="position:relative;width:400px;height:400px">
  <button class="pin" type="button" style="position:absolute;left:50%;top:6%">North</button>
  <button class="pin" type="button" style="position:absolute;left:88%;top:28%">East</button>
  <button class="pin" type="button" style="position:absolute;left:12%;top:72%">West</button>
</div>
<div class="actions">
  <a class="btn" href="/one">One</a>
  <a class="btn" href="/two">Two</a>
</div>`;
const transformed = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''));
echo json_encode(array('serializedBlocks' => (string) ($result['serialized_blocks'] ?? ''), 'css' => implode("\\n", array_column($css, 'content'))));
`, transformerRoot, Buffer.from(sourceFixture).toString('base64')], { encoding: 'utf8' }));

const wordpressButtonCss = `.wp-block-buttons{box-sizing:border-box;display:flex;flex-wrap:wrap;gap:.5em}.wp-block-button{box-sizing:border-box}.wp-block-button__link{box-sizing:border-box;cursor:pointer;display:inline-block;padding:calc(.667em + 2px) calc(1.333em + 2px);text-align:center;word-break:break-word}`;
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 800, height: 700 } });
    await page.setContent(`<!doctype html><style>body{margin:0;font:16px/1.2 sans-serif}${sourceFixture}</style>`);
    const sourcePins = await page.locator('.pin').evaluateAll((elements) => elements.map((element) => {
        const box = element.getBoundingClientRect();
        return { left: getComputedStyle(element).left, x: box.x };
    }));
    const sourceSpread = Math.max(...sourcePins.map((pin) => pin.x)) - Math.min(...sourcePins.map((pin) => pin.x));

    await page.setContent(`<!doctype html><style>body{margin:0;font:16px/1.2 sans-serif}${wordpressButtonCss}${transformed.css}</style>${transformed.serializedBlocks}`);
    const importedPins = await page.locator('.stage .wp-block-button').evaluateAll((elements) => elements.map((element) => {
        const box = element.getBoundingClientRect();
        return {
            left: getComputedStyle(element).left,
            x: box.x,
            wrapperDisplay: element.parentElement ? getComputedStyle(element.parentElement).display : '',
            wrapperWidth: element.parentElement ? element.parentElement.getBoundingClientRect().width : 0,
        };
    }));
    const importedSpread = Math.max(...importedPins.map((pin) => pin.x)) - Math.min(...importedPins.map((pin) => pin.x));

    assert.equal(importedPins.length, 3, `imported positioned buttons: ${importedPins.length}`);
    for (const pin of importedPins) {
        assert.notEqual(pin.left, '0px', `used left collapsed against a zero-width wrapper: ${JSON.stringify(importedPins)}`);
        assert.equal(pin.wrapperDisplay, 'contents', `synthesized wrapper display: ${pin.wrapperDisplay}`);
    }
    assert.ok(sourceSpread > 200, `source x spread: ${sourceSpread}`);
    assert.ok(importedSpread > 200, `imported x spread collapsed: ${importedSpread} vs source ${sourceSpread}`);
    assert.ok(Math.abs(importedSpread - sourceSpread) < 40, `imported spread ${importedSpread} drifted from source ${sourceSpread}`);

    const group = await page.locator('.actions, .wp-block-group.actions').evaluate((element) => {
        const items = [...element.querySelectorAll('.wp-block-buttons, .wp-block-button')].filter((node) => getComputedStyle(node).display !== 'contents');
        const boxes = items.map((item) => item.getBoundingClientRect());
        return {
            display: getComputedStyle(element).display,
            gap: getComputedStyle(element).gap,
            xs: boxes.map((box) => box.x),
            wrappersAreContents: [...element.querySelectorAll('.wp-block-buttons')].every((wrapper) => getComputedStyle(wrapper).display === 'contents'),
        };
    });
    assert.equal(group.display, 'flex', `in-flow group display: ${group.display}`);
    assert.equal(group.gap, '16px', `in-flow group gap: ${group.gap}`);
    assert.equal(group.wrappersAreContents, false, 'in-flow buttons wrappers were flattened');
    assert.ok(new Set(group.xs.map((x) => Math.round(x))).size >= 2, `in-flow buttons stacked instead of aligning: ${JSON.stringify(group)}`);
    console.log(`Positioned button containing block: sourceSpread=${sourceSpread} importedSpread=${importedSpread} groupGap=${group.gap}`);
} finally {
    await browser.close();
}

console.log('Positioned button containing block geometry passed');
