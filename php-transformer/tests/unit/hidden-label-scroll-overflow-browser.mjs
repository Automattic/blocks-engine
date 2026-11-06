import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($argv[2])->toArray());';
// Text-less swatch buttons carry their accessible name as a visually hidden
// label. In a horizontally scrolling rail that is not positioned, a label left
// at its static position escapes the rail's clip and widens the whole page.
const card = name => `<article class="card"><p>${name}</p><div class="sw" role="group" aria-label="Gold"><button type="button" aria-label="Yellow gold"></button><button type="button" aria-label="Rose gold"></button></div></article>`;
const html = `<!doctype html><style>body{margin:0}.rail{display:grid;grid-auto-flow:column;grid-auto-columns:300px;gap:20px;overflow-x:auto}`
    + `.sw{display:flex;gap:8px}.sw button{width:18px;height:18px;border-radius:50%;padding:0;border:1px solid #555;background:#d4ae6a}</style>`
    + `<main><div class="rail">${['Floe', 'Shard', 'Drift', 'Polar', 'Frost', 'Glint', 'Rime', 'Thaw'].map(card).join('')}</div></main>`;
const result = JSON.parse(execFileSync('php', ['-r', php, root, html], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
assert.match(result.serialized_blocks, /<button[^>]*><span class="screen-reader-text"[^>]*>Rose gold<\/span><\/button>/, 'each swatch carries its accessible name');
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress wp-includes/css/dist/block-library/style.min.css');
const core = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 390, height: 900 } });
    const measure = () => page.evaluate(() => ({ pageWidth: document.documentElement.scrollWidth, swatches: [...document.querySelectorAll('button')].filter(b => b.getBoundingClientRect().width === 18).length }));
    await page.setContent(html);
    const source = await measure();
    await page.setContent(`<!doctype html><style>${core}\n${css}</style>${result.serialized_blocks}`);
    const candidate = await measure();
    console.log(JSON.stringify({ source, candidate }));
    assert.deepEqual(candidate, source, 'hidden labels stay inside the scrolling rail and the swatches keep their box');
} finally {
    await browser.close();
}
