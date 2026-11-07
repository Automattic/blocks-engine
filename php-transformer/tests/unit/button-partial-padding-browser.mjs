import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($argv[2])->toArray());';
// An underlined text link authors only padding-bottom. Lowered to core/button,
// its other sides must stay at the link's zero default, not Core's padding.
const html = `<!doctype html><style>body{margin:0;font-family:sans-serif}.link{display:inline-block;font-size:12px;line-height:1;letter-spacing:.24em;`
    + `text-transform:uppercase;text-decoration:none;white-space:nowrap;padding-bottom:8px;background:linear-gradient(currentColor,currentColor) 0 100%/100% 1px no-repeat}</style>`
    + `<main><h2>Heritage</h2><p>Forty-five years.</p><a class="link" href="/about/">Our story</a><p>After the link</p></main>`;
const result = JSON.parse(execFileSync('php', ['-r', php, root, html], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress wp-includes/css/dist/block-library/style.min.css');
const core = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 600, height: 900 } });
    const measure = () => page.evaluate(() => {
        const box = el => (r => [Math.round(r.width), Math.round(r.height)])(el.getBoundingClientRect());
        const link = [...document.querySelectorAll('a')].find(a => a.textContent.trim() === 'Our story');
        const after = [...document.querySelectorAll('p')].find(p => p.textContent.trim() === 'After the link');
        return { link: box(link), afterTop: Math.round(after.getBoundingClientRect().top - link.getBoundingClientRect().top) };
    });
    await page.setContent(html);
    const source = await measure();
    await page.setContent(`<!doctype html><style>body{margin:0;font-family:sans-serif}\n${core}\n${css}</style>${result.serialized_blocks}`);
    const candidate = await measure();
    console.log(JSON.stringify({ source, candidate }));
    assert.deepEqual(candidate.link, source.link, 'the link keeps its authored box: only the bottom padding');
} finally {
    await browser.close();
}
