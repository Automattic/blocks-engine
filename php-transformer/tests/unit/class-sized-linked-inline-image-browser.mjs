import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const code = 'require $argv[1]."/vendor/autoload.php"; $html = require $argv[1]."/tests/fixtures/class-sized-linked-inline-image.php"; echo json_encode(["html" => $html, "result" => (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($html)->toArray()]);';
const { html, result } = JSON.parse(execFileSync('php', ['-r', code, root], { encoding: 'utf8' }));
assert.doesNotMatch(result.serialized_blocks, /is-resized/);
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress block-library/style.min.css');
const core = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const png = execFileSync('php', ['-r', '$im=imagecreatetruecolor(512,513); imagepng($im);']);
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage();
    await page.route('https://fixture.test/chat.png', route => route.fulfill({ contentType: 'image/png', body: png }));
    for (const width of [1440, 768, 390]) {
        await page.setViewportSize({ width, height: 900 });
        const measure = async content => {
            await page.setContent(`<!doctype html>${content}`);
            await page.locator('img').evaluate(image => image.decode());
            return page.locator('img').evaluate(image => {
                const { width, height } = image.getBoundingClientRect();
                return { width, height };
            });
        };
        const source = await measure(html);
        const candidate = await measure(`<style>${core}</style><style>${css}</style>${result.serialized_blocks}`);
        console.log(JSON.stringify({ viewport: width, source, candidate }));
        assert.equal(source.width, width < 479 ? 60 : 70);
        assert.deepEqual(candidate, source, 'the class-sized linked image keeps its responsive replaced box');
    }
} finally {
    await browser.close();
}
