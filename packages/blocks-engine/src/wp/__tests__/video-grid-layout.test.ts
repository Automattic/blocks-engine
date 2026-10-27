import { expect, it } from 'vitest';
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url) as { (id: string): any; resolve(id: string): string };
const root = fileURLToPath(new URL('../../../../../php-transformer/', import.meta.url));

it('retains source text/video grid geometry at 390, 768 and 1440px', async () => {
  const source = readFileSync(`${root}/tests/fixtures/text-video-grid.html`, 'utf8');
  const output = JSON.parse(execFileSync('php', ['-r', `
    require 'vendor/autoload.php';
    $r = (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(file_get_contents('tests/fixtures/text-video-grid.html'))->toArray();
    echo json_encode(['html' => $r['serialized_blocks'], 'css' => implode("\\n", array_column($r['assets'], 'content'))]);
  `], { cwd: root, encoding: 'utf8' })) as { html: string; css: string };
  const coreCss = readFileSync(require.resolve('@wordpress/block-library/build-style/style.css'), 'utf8');
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage();
    for (const width of [390, 768, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      const measure = async (html: string) => {
        await page.setContent(html);
        return page.evaluate(() => ['h2', 'video'].map((selector) => {
          const box = document.querySelector(selector)!.getBoundingClientRect();
          return { x: box.x, y: box.y, width: box.width, height: box.height };
        }));
      };
      const before = await measure(source);
      const after = await measure(`<!doctype html><style>${coreCss}\n${output.css}</style>${output.html}`);
      console.log(width, { source: before, imported: after });
      for (const [index, box] of after.entries()) {
        for (const key of ['x', 'y', 'width', 'height'] as const) {
          expect(Math.abs(box[key] - before[index][key]), `${width}px ${index} ${key}`).toBeLessThan(1);
        }
      }
    }
  } finally {
    await browser.close();
  }
}, 30000);
