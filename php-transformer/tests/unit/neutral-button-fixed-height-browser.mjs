import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($argv[2])->toArray());';
// Swatch buttons are flex items with a fixed authored size. core/button's two
// save wrappers are flattened away, so the link itself is the flex item and
// must keep the authored height instead of a percentage of the auto-height row.
const html = `<!doctype html><style>body{margin:0}.card{display:flex;flex-direction:column;align-items:center;gap:14px}`
    + `.sw{display:flex;gap:8px;justify-content:center}.sw button{width:18px;height:18px;border-radius:50%;padding:0;border:1px solid #555;background:#d4ae6a}</style>`
    + `<main><article class="card"><p>Floe Hoop</p><div class="sw" role="group" aria-label="Gold"><button type="button" aria-label="Yellow gold"></button><button type="button" aria-label="Rose gold"></button></div><p>From $940</p></article></main>`;
const result = JSON.parse(execFileSync('php', ['-r', php, root, html], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress wp-includes/css/dist/block-library/style.min.css');
const core = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 390, height: 900 } });
    const measure = () => page.evaluate(() => ({
        swatches: [...document.querySelectorAll('button')].map(b => (r => [r.width, r.height])(b.getBoundingClientRect())),
        price: Math.round([...document.querySelectorAll('p')].find(p => p.textContent.includes('$940')).getBoundingClientRect().top),
    }));
    await page.setContent(html);
    const source = await measure();
    await page.setContent(`<!doctype html><style>${core}\n${css}</style>${result.serialized_blocks}`);
    const candidate = await measure();
    console.log(JSON.stringify({ source, candidate }));
    assert.deepEqual(candidate, source, 'the flattened swatch link keeps its authored 18px box');
} finally {
    await browser.close();
}
