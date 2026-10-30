import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($argv[2])->toArray());';
// A logo with no size attributes, limited on both axes: the browser keeps its
// ratio and the height limit wins. WordPress later adds the attachment's
// width/height attributes when it renders a Media Library image.
const logo = `data:image/svg+xml,${encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="1331" height="1200"><rect width="1331" height="1200" fill="#123"/></svg>')}`;
const html = `<style>body{margin:0}.brand img{max-height:136px;max-width:200px}</style><main><h1>Title</h1><div class="brand"><a href="/"><img src="${logo}" alt="Logo"></a></div></main>`;
const result = JSON.parse(execFileSync('php', ['-r', php, root, html], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
// What WordPress adds on render: the attachment's size attributes, and core's own image rule.
const rendered = result.serialized_blocks.replace('<img ', '<img width="1331" height="1200" ');
const core = '.wp-block-image img{box-sizing:border-box;height:auto;max-width:100%;vertical-align:bottom}';
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const measure = () => page.locator('img').evaluate(async img => {
        await img.decode();
        const box = img.getBoundingClientRect();
        return { width: Math.round(box.width), height: Math.round(box.height) };
    });
    await page.setContent(`<!doctype html>${html}`);
    const source = await measure();
    await page.setContent(`<!doctype html><style>body{margin:0}${core}${css}</style>${rendered}`);
    const candidate = await measure();
    console.log(JSON.stringify({ source, candidate }));
    assert.deepEqual(source, { width: 151, height: 136 });
    assert.deepEqual(candidate, source, 'the height limit keeps the logo ratio');
} finally {
    await browser.close();
}
