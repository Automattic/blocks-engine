import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { chromium } from 'playwright';

const root = new URL('../../..', import.meta.url).pathname;
const evidencePath = process.env.BE_2431_EVIDENCE || join(tmpdir(), 'be-2431-nested-responsive-font-size.json');
const widths = [390, 768, 1440];
const css = [
  '@layer theme, base, components, utilities;',
  ':root{--heading-wide:3.75rem}',
  '@layer utilities{',
  '.heading-base{font-size:2.75rem;line-height:1.1}',
  '.md\\:text-6xl{@media (width >= 48rem){font-size:var(--heading-wide)}}',
  '.heading-wide{@media (width >= 64rem){font-size:4.5rem}}',
  '}',
  '.ordered{font-size:20px;line-height:1.2;@media (min-width:600px){font-size:40px;line-height:1.5}font-size:30px}',
  '@layer low, high;',
  '.layered{font-size:16px;line-height:1;@layer low{font-size:40px}@media (min-width:700px){font-size:22px}@supports (display:flex){font-size:24px}@layer high{font-size:48px}font-size:18px;@media (min-width:1200px){font-size:36px;line-height:1.25}}',
  '.important-later{font-size:30px;line-height:1;@media (min-width:600px){font-size:40px !important}font-size:22px}',
  '.important-base{font-size:20px !important;line-height:1.2;@media (min-width:600px){font-size:50px;line-height:2}}',
  '.layer-important{font-size:16px;line-height:1;@layer high{font-size:40px !important}@media (min-width:700px){@layer low{font-size:28px !important}}}',
].join('');
const source = `<!doctype html><html><head><style>${css}</style></head><body><main>`
  + '<h1 class="heading-base md:text-6xl heading-wide">Responsive</h1>'
  + '<h1 class="ordered">Ordered</h1>'
  + '<h1 class="layered">Layered</h1>'
  + '<h1 class="heading-base md:text-6xl heading-wide" style="font-size:2rem">Inline</h1>'
  + '<h1 class="important-later">Important later</h1>'
  + '<h1 class="important-base">Important base</h1>'
  + '<h1 class="layer-important">Layer important</h1>'
  + '</main></body></html>';
const code = 'require $argv[1] . "/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, root, Buffer.from(source).toString('base64')], { encoding: 'utf8' }));
const authorCss = (result.assets || []).map((asset) => asset.content || '').join('\n');
const engineCss = (result.assets || []).filter((asset) => asset.source === 'engine-support').map((asset) => asset.content || '').join('\n');
const markup = String(result.serialized_blocks || '');
const pageHtml = `<!doctype html><html><head><style>html{font-size:16px}h1{font-size:inherit}${authorCss}</style></head><body>${markup}</body></html>`;
const carrierHtml = `<!doctype html><html><head><style>html{font-size:16px}#beat-normal{font-size:12px}${engineCss}</style></head><body>${markup}</body></html>`;
const near = (actual, expected) => Math.abs(parseFloat(actual) - expected) < 0.6;
const read = (page, index) => page.locator('h1').nth(index).evaluate((element) => {
  const style = getComputedStyle(element);
  return { fontSize: style.fontSize, lineHeight: style.lineHeight, inline: element.getAttribute('style') || '' };
});

const evidence = { widths: {}, markup, authorCss };
const browser = await chromium.launch({ headless: true });
try {
  const page = await browser.newPage();
  await page.setContent(pageHtml);
  for (const width of widths) {
    await page.setViewportSize({ width, height: 800 });
    evidence.widths[width] = {
      responsive: await read(page, 0),
      ordered: await read(page, 1),
      layered: await read(page, 2),
      inline: await read(page, 3),
      importantLater: await read(page, 4),
      importantBase: await read(page, 5),
      layerImportant: await read(page, 6),
    };
  }
  const carrier = await browser.newPage();
  await carrier.setContent(carrierHtml);
  evidence.carrier = {};
  for (const width of widths) {
    await carrier.setViewportSize({ width, height: 800 });
    evidence.carrier[width] = {};
    for (const [label, index] of [['importantLater', 4], ['importantBase', 5], ['layerImportant', 6]]) {
      await carrier.locator('h1').nth(index).evaluate((element) => { element.id = 'beat-normal'; });
      evidence.carrier[width][label] = await read(carrier, index);
      await carrier.locator('h1').nth(index).evaluate((element) => { element.id = ''; });
    }
  }
  await carrier.close();
  writeFileSync(evidencePath, JSON.stringify(evidence, null, 2));
  const expectSize = (label, sample, width, font, line) => {
    assert.ok(near(sample.fontSize, font), `${label} font-size at ${width}px expected ${font}px, got ${sample.fontSize}`);
    assert.ok(near(sample.lineHeight, line), `${label} line-height at ${width}px expected ${line}px, got ${sample.lineHeight}`);
  };
  expectSize('responsive', evidence.widths[390].responsive, 390, 44, 48.4);
  expectSize('responsive', evidence.widths[768].responsive, 768, 60, 66);
  expectSize('responsive', evidence.widths[1440].responsive, 1440, 72, 79.2);
  assert.equal(evidence.widths[390].responsive.inline.includes('font-size'), false, 'responsive heading must not freeze an inline font-size');
  for (const width of widths) {
    expectSize('ordered', evidence.widths[width].ordered, width, 30, width < 600 ? 36 : 45);
  }
  expectSize('layered', evidence.widths[390].layered, 390, 18, 18);
  expectSize('layered', evidence.widths[768].layered, 768, 18, 18);
  expectSize('layered', evidence.widths[1440].layered, 1440, 36, 45);
  for (const width of widths) {
    assert.ok(near(evidence.widths[width].inline.fontSize, 32), `inline font-size at ${width}px expected 32px, got ${evidence.widths[width].inline.fontSize}`);
    assert.equal(evidence.widths[width].inline.inline.includes('font-size:2rem'), true, 'explicit inline font-size stays on the heading');
  }
  for (const width of widths) {
    expectSize('important-later', evidence.widths[width].importantLater, width, width < 600 ? 22 : 40, width < 600 ? 22 : 40);
    expectSize('important-base', evidence.widths[width].importantBase, width, 20, width < 600 ? 24 : 40);
    expectSize('layer-important', evidence.widths[width].layerImportant, width, width < 700 ? 40 : 28, width < 700 ? 40 : 28);
    if (width >= 600) {
      assert.ok(near(evidence.carrier[width].importantLater.fontSize, 40), `carrier important-later at ${width}px got ${evidence.carrier[width].importantLater.fontSize}`);
    }
    assert.ok(near(evidence.carrier[width].importantBase.fontSize, 20), `carrier important-base at ${width}px got ${evidence.carrier[width].importantBase.fontSize}`);
    assert.ok(near(evidence.carrier[width].layerImportant.fontSize, width < 700 ? 40 : 28), `carrier layer-important at ${width}px got ${evidence.carrier[width].layerImportant.fontSize}`);
  }
  console.log(JSON.stringify({ evidence: evidencePath, widths: evidence.widths }));
} finally {
  await browser.close();
}
