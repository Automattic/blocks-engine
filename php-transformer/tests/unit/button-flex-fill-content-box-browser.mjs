import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($argv[2])->toArray());';
// A padded content-box link button stretched by a sized flex wrapper. Its
// border box is the wrapper's height; the lowered link must not add the
// padding on top of a filled content box.
const html = readFileSync(new URL('../fixtures/button-flex-fill-content-box.html', import.meta.url), 'utf8');
const result = JSON.parse(execFileSync('php', ['-r', php, root, html], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress wp-includes/css/dist/block-library/style.min.css');
const core = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1000, height: 600 } });
    const measure = () => page.evaluate(() => {
        const link = [...document.querySelectorAll('a')].find(a => a.textContent.trim() === 'Shop now');
        const r = link.getBoundingClientRect();
        return [Math.round(r.width), Math.round(r.height)];
    });
    await page.setContent(html);
    const source = await measure();
    await page.setContent(`<!doctype html><style>body{margin:0;font-family:sans-serif}\n${core}\n${css}</style>${result.serialized_blocks}`);
    const candidate = await measure();
    console.log(JSON.stringify({ source, candidate }));
    assert.deepEqual(candidate, source, 'the lowered link keeps the source border box');
} finally {
    await browser.close();
}
