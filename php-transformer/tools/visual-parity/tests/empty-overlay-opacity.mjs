import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const fixture = JSON.parse(execFileSync('php', ['-r', String.raw`
require $argv[1] . '/vendor/autoload.php';
$html = '<style>.tile{position:relative;width:40px;height:40px;background:#f0a050}.neutral-overlay{position:absolute;inset:0;background:#000}</style><main>';
foreach (array('.35', '.65', '0') as $alpha) {
    $html .= '<div class="tile"><div class="neutral-overlay" style="opacity:' . $alpha . '"></div></div>';
}
$html .= '</main>';
$result = (new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform($html)->toArray();
$css = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
echo json_encode(array('source' => $html, 'markup' => $result['serialized_blocks'], 'css' => $css));
`, root], { encoding: 'utf8' }));

const browser = await chromium.launch();
try {
  const page = await browser.newPage({ viewport: { width: 600, height: 400 } });
  const observations = [];
  for (const html of [fixture.source, `<style>${fixture.css}</style>${fixture.markup}`]) {
    await page.setContent(html);
    const pixels = [];
    const tiles = page.locator('.tile');
    for (let index = 0; index < await tiles.count(); index++) {
      const image = (await tiles.nth(index).screenshot()).toString('base64');
      pixels.push(await page.evaluate(async (base64) => {
        const bitmap = await createImageBitmap(await (await fetch(`data:image/png;base64,${base64}`)).blob());
        const canvas = document.createElement('canvas');
        canvas.width = bitmap.width;
        canvas.height = bitmap.height;
        const context = canvas.getContext('2d');
        context.drawImage(bitmap, 0, 0);
        return [...context.getImageData(Math.floor(bitmap.width / 2), Math.floor(bitmap.height / 2), 1, 1).data];
      }, image));
    }
    observations.push({ pixels, opacity: await page.locator('.neutral-overlay').evaluateAll(elements => elements.map(element => getComputedStyle(element).opacity)) });
  }
  assert.deepEqual(observations[0].opacity, ['0.35', '0.65', '0']);
  assert.deepEqual(observations[1], observations[0], 'native class-painted empty overlays retain inline alpha and composited pixels');
} finally {
  await browser.close();
}
console.log('Empty overlay inline opacity and composited pixel parity passed');
