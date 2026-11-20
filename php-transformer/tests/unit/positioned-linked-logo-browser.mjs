import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($argv[2])->toArray());';
const image = `data:image/svg+xml,${encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="2400" height="2000"><rect width="2400" height="2000" fill="red"/></svg>')}`;
const html = readFileSync(`${root}/tests/fixtures/positioned-linked-logo.html`, 'utf8').replace('/wide.png', image);
const result = JSON.parse(execFileSync('php', ['-r', php, root, html], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
assert.doesNotMatch(result.serialized_blocks, /<figure[^>]*logo-box[^>]*blocks-engine-synthetic-(?:flex-image-figure|image-figure-inline)/);
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to the WordPress block-library stylesheet');
const core = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const measure = async (markup) => {
        await page.setContent(`<!doctype html>${markup}`);
        await page.locator('img').evaluate(img => img.decode());
        return page.evaluate(() => {
            const rect = document.querySelector('img').getBoundingClientRect();
            return { x: rect.x, y: rect.y, width: rect.width, height: rect.height };
        });
    };
    const source = await measure(html);
    const candidate = await measure(`<style>${core}</style><style>${css}</style>${result.serialized_blocks}`);
    assert.equal(source.y, 63.5, 'the logo sits below the banner, centered in its source box');
    assert.equal(source.width, 250);
    assert.equal(source.height, 75);
    assert.deepEqual(candidate, source, 'the source containing block and image geometry survive conversion');
} finally {
    await browser.close();
}
