import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const sourceFixture = `<style>
  .stage { position: relative; width: 540px; height: 540px; }
  .place { position: absolute; translate: -50% -50%; }
  .pin { padding: 8px 16px; background: #111; color: #fff; border: 0; }
</style>
<div class="stage" style="position:relative;width:540px;height:540px">
  <div class="place" style="left:50%;top:50%;width:80px;height:80px">Hub</div>
  <button class="pin place" type="button" style="left:50%;top:6%">North label</button>
  <button class="pin place" type="button" style="left:88%;top:28%">East</button>
  <button class="pin place" type="button" style="left:12%;top:72%">West<br>two lines</button>
</div>`;
const transformed = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''));
echo json_encode(array('serializedBlocks' => (string) ($result['serialized_blocks'] ?? ''), 'css' => implode("\\n", array_column($css, 'content'))));
`, transformerRoot, Buffer.from(sourceFixture).toString('base64')], { encoding: 'utf8' }));

const wordpressButtonCss = `.wp-block-buttons{box-sizing:border-box;display:flex;flex-wrap:wrap;gap:.5em}.wp-block-button{box-sizing:border-box;display:inline-block}.wp-block-button__link{box-sizing:border-box;cursor:pointer;display:inline-block;padding:calc(.667em + 2px) calc(1.333em + 2px);text-align:center;word-break:break-word}`;
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 800, height: 700 } });
    await page.setContent(`<!doctype html><style>body{margin:0;font:16px/1.2 sans-serif}${wordpressButtonCss}${transformed.css}</style>${transformed.serializedBlocks}`);
    const measurements = await page.locator('.wp-block-button').evaluateAll((wrappers) => wrappers.map((wrapper) => {
        const link = wrapper.querySelector('.wp-block-button__link');
        const outer = wrapper.getBoundingClientRect();
        const inner = link ? link.getBoundingClientRect() : null;
        return {
            size: [Math.round(outer.width), Math.round(outer.height)],
            delta: inner
                ? [Math.round(inner.x - outer.x), Math.round(inner.y - outer.y)]
                : null,
            wrapperTranslate: getComputedStyle(wrapper).translate,
            linkTranslate: link ? getComputedStyle(link).translate : null,
        };
    }));
    assert.equal(measurements.length, 3, `imported positioned buttons: ${measurements.length}`);
    for (const measured of measurements) {
        assert.deepEqual(measured.delta, [0, 0], `inner link drifted from wrapper: ${JSON.stringify(measured)}`);
        assert.equal(measured.wrapperTranslate, '-50% -50%', `wrapper translate: ${measured.wrapperTranslate}`);
        assert.ok(measured.size[0] < 540, `wrapper stretched to the stage instead of its label: ${JSON.stringify(measured)}`);
        assert.ok(
            measured.linkTranslate === 'none' || measured.linkTranslate === '0px' || measured.linkTranslate === '',
            `inner link re-applied placement: ${measured.linkTranslate}`
        );
    }
    console.log(`Positioned button translate once: ${JSON.stringify(measurements)}`);
} finally {
    await browser.close();
}

console.log('Positioned button translate-once geometry passed');
