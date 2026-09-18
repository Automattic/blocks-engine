import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const root = new URL('../../..', import.meta.url).pathname;
const source = `<style>
.text-gray-900{color:rgb(17 24 39)}
.bg-white{background-color:rgb(255 255 255)}
.dark\\:text-gray-100:where([data-mode=dark],[data-mode=dark] *){color:rgb(243 244 246)}
.dark\\:bg-\\[radial-gradient\\(circle_at_top\\,\\#202124_0\\%\\,\\#151619_48\\%\\,\\#101113_100\\%\\)\\]:where([data-mode=dark],[data-mode=dark] *){background-image:radial-gradient(circle at top,#202124,#151619 48%,#101113)}
</style>
<body class="bg-white text-gray-900 dark:text-gray-100 dark:bg-[radial-gradient(circle_at_top,#202124_0%,#151619_48%,#101113_100%)]">
<p class="text-gray-900 dark:text-gray-100">Hello</p>
<span class="bg-white">Mark</span>
</body>`;
const result = JSON.parse(execFileSync('php', ['-r', 'require $argv[1] . "/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());', root, Buffer.from(source).toString('base64')], { encoding: 'utf8' }));
const css = (result.assets ?? []).filter((asset) => asset.kind === 'css').map((asset) => asset.content ?? '').join('\n');
const html = String(result.serialized_blocks ?? '').replace(/<!--[\s\S]*?-->/g, '');

const readBody = (page) => page.evaluate(() => {
  const style = getComputedStyle(document.body);
  return { color: style.color, backgroundColor: style.backgroundColor, backgroundImage: style.backgroundImage };
});

const browser = await chromium.launch({ headless: true });
try {
  const sourceLight = await browser.newPage({ viewport: { width: 1280, height: 900 }, colorScheme: 'light' });
  await sourceLight.setContent(source);
  const liveLight = await readBody(sourceLight);
  await sourceLight.close();

  const importedLight = await browser.newPage({ viewport: { width: 1280, height: 900 }, colorScheme: 'light' });
  await importedLight.setContent(`<style>${css}</style>${html}`);
  const importLight = await readBody(importedLight);
  await importedLight.close();

  assert.equal(liveLight.color, 'rgb(17, 24, 39)', 'source light color');
  assert.equal(liveLight.backgroundColor, 'rgb(255, 255, 255)', 'source light background-color');
  assert.equal(liveLight.backgroundImage, 'none', 'source light background-image');
  assert.equal(importLight.color, liveLight.color, 'imported light color is unchanged');
  assert.equal(importLight.backgroundColor, liveLight.backgroundColor, 'imported light background-color is unchanged');
  assert.equal(importLight.backgroundImage, liveLight.backgroundImage, 'imported light background-image is unchanged');

  const importedDark = await browser.newPage({ viewport: { width: 1280, height: 900 }, colorScheme: 'dark' });
  await importedDark.setContent(`<style>${css}</style>${html}`);
  const importDark = await readBody(importedDark);
  await importedDark.close();

  assert.equal(importDark.color, 'rgb(243, 244, 246)', 'imported dark color');
  assert.equal(importDark.backgroundColor, 'rgb(255, 255, 255)', 'imported dark background-color remains the light fill under the gradient');
  assert.match(importDark.backgroundImage, /radial-gradient/i, 'imported dark background-image carries the gradient');
} finally {
  await browser.close();
}

console.log('Color-scheme projection browser regression passed');
