import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

// Use the public ArtifactCompiler contract's exact reduced page and assets.
const root = new URL('../../..', import.meta.url).pathname;
const { source, result } = JSON.parse(execFileSync('php', [`${root}/tests/contract/two-column-section-grid-media-text.php`, '--json'], { encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
// Include the native figure margin: generated support CSS must cancel it
// without disturbing the source grid's tracks or gutters.
const target = `<!doctype html><style>figure{margin:1em 40px}${css}</style>${result.serialized_blocks}`;
const browser = await chromium.launch({ headless: true });
try {
  for (const width of [390, 520, 521, 768, 850, 851, 1440]) {
    const measurements = [];
    for (const html of [source, target]) {
      const page = await browser.newPage({ viewport: { width, height: 1200 } });
      await page.setContent(html);
      measurements.push(await page.evaluate(() => {
        const rect = selector => {
          const { x, y, width, height } = document.querySelector(selector).getBoundingClientRect();
          return { x, y, width, height };
        };
        const section = getComputedStyle(document.querySelector('.split'));
        return { image: rect('img'), heading: rect('h2'), padding: section.padding, gap: section.gap, tracks: section.gridTemplateColumns };
      }));
      await page.close();
    }
    console.log(JSON.stringify({ width, source: measurements[0], imported: measurements[1] }));
    assert.deepEqual(measurements[1], measurements[0], `source grid geometry at ${width}px`);
  }
} finally {
  await browser.close();
}
