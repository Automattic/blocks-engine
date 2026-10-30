import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../../..', import.meta.url).pathname;
const code = 'require $argv[1] . "/vendor/autoload.php"; $html = require $argv[1] . "/tests/fixtures/figure-media-frame.php"; $result = (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($html)->toArray(); echo json_encode(array("html" => $html, "result" => $result));';
const fixture = JSON.parse(execFileSync('php', ['-r', code, root], { encoding: 'utf8' }));
const result = fixture.result;
assert.equal((result.serialized_blocks.match(/<!-- wp:image /g) || []).length, 3, 'one native image per source figure');
assert.equal((result.serialized_blocks.match(/<figcaption/g) || []).length, 3, 'all native captions survive');
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content ?? '').join('\n');
const browser = await chromium.launch({ headless: true });
try {
  for (const width of [390, 768, 1440]) {
    const page = await browser.newPage({ viewport: { width, height: 1000 } });
    await page.route('**/*.jpg', route => {
      const [w, h] = route.request().url().endsWith('wide.jpg') ? [460, 310] : [805, 959];
      return route.fulfill({ contentType: 'image/svg+xml', body: `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}"><rect width="100%" height="100%" fill="#456"/></svg>` });
    });
    const measure = async html => {
      await page.setContent(html);
      await page.locator('img').evaluateAll(images => Promise.all(images.map(image => image.decode())));
      return page.locator('figure').evaluateAll(figures => figures.map(figure => {
        const image = figure.querySelector('img'), caption = figure.querySelector('figcaption');
        const box = image.getBoundingClientRect(), cap = caption.getBoundingClientRect(), style = getComputedStyle(image);
        return { x: box.x, y: box.y, width: box.width, height: box.height, captionTop: cap.top, bottom: box.bottom, fit: style.objectFit,
          padding: style.padding, background: style.backgroundColor, caption: caption.textContent };
      }));
    };
    const source = await measure(fixture.html.replace('<head>', '<head><base href="https://fixture.test/">'));
    const candidate = await measure(`<!doctype html><head><base href="https://fixture.test/"><style>/* Native core image baseline */.wp-block-image img{max-width:100%;height:auto;vertical-align:bottom}${css}</style></head><body>${result.serialized_blocks}</body>`);
    console.log(JSON.stringify({ width, source, candidate }));
    for (const [index, box] of candidate.entries()) {
      assert.ok(Math.abs(box.height - box.width * 3 / 4) < 0.1, `image ${index} height equals width × 3/4 at ${width}`);
      assert.ok(box.captionTop >= box.bottom, 'caption stays below the frame');
      assert.equal(box.caption, source[index].caption);
      assert.equal(box.fit, index === 2 ? 'contain' : 'cover');
      // A padded frame is represented by the image's border box; its content
      // box is the source image's box inside the lost wrapper.
      const padding = index === 2 ? 24 : 0;
      assert.ok(Math.abs(box.width - padding - source[index].width) < 0.1);
      assert.ok(Math.abs(box.height - padding - source[index].height) < 0.1);
      assert.ok(Math.abs(box.x + padding / 2 - source[index].x) < 0.1, 'content stays horizontally centered');
      assert.ok(Math.abs(box.y + padding / 2 - source[index].y) < 0.1, 'content stays vertically centered');
      assert.equal(box.captionTop, source[index].captionTop, 'caption retains source placement');
      if (index === 2) {
        assert.equal(box.padding, '12px');
        assert.equal(box.background, 'rgb(255, 255, 255)');
      }
    }
    await page.close();
  }
} finally {
  await browser.close();
}
