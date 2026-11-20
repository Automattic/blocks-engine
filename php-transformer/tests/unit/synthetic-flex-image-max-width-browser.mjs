import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const html = readFileSync(`${root}/tests/fixtures/synthetic-flex-image-max-width.html`, 'utf8');
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($argv[2])->toArray());';
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress block-library/style.min.css');
const core = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage();
    const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="2400" height="2000"></svg>';
    await page.route('**/wide.png', route => route.fulfill({ contentType: 'image/svg+xml', body: svg }));
    for (const selector of ['img', '.photo', '#photo', null]) {
        const source = (selector === null ? html.replace('img{max-width:100%}', '') : html.replace('img{max-width', `${selector}{max-width`))
            .replace('<img ', '<img class="photo" id="photo" ');
        const out = JSON.parse(execFileSync('php', ['-r', php, root, source], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
        const css = out.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
        for (const width of [1000, 390]) {
            await page.setViewportSize({ width, height: 900 });
            const measure = async content => {
                await page.setContent(`<!doctype html><base href="https://fixture.test/">${content}`);
                await page.locator('img').evaluate(img => img.decode());
                return page.locator('img').evaluate(img => {
                    const box = img.getBoundingClientRect();
                    return { width: box.width, height: box.height, scrollWidth: document.documentElement.scrollWidth };
                });
            };
            const expected = await measure(source);
            const actual = await measure(`<style>${core}</style><style>${css}</style>${out.serialized_blocks}`);
            if (selector === null) {
                assert.equal(expected.width, 2400, 'unconstrained source retains its intrinsic min-content size');
            } else {
                assert.ok(Math.abs(expected.width - (width - 16) * 0.4) < 0.02, 'source image fills its 40% column');
            }
            assert.deepEqual(actual, expected, `${selector} at ${width}px keeps source geometry without overflow`);
        }
    }
} finally {
    await browser.close();
}
