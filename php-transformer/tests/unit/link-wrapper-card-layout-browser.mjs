import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($argv[2])->toArray());';
// A card link wraps an image box and a label. Its link is propagated onto
// those children and the anchor itself becomes a group, so the author rule
// that lays the anchor out (flex column with a gap) must follow it there.
const img = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
const card = name => `<a href="/rings/"><div class="ph"><img src="${img}" alt="${name}" width="10" height="10"></div><b><span class="dot"></span>${name} gold</b></a>`;
const html = `<!doctype html><style>body{margin:0}.trio{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}`
    + `.trio a{text-decoration:none;display:flex;flex-direction:column;align-items:center;gap:18px}.ph{width:100%;aspect-ratio:4/5;position:relative}`
    + `.ph img{position:absolute;inset:0;width:100%;height:100%;object-fit:contain}.trio b{display:flex;align-items:center;gap:12px;font-size:24px}`
    + `.dot{width:11px;height:11px;border-radius:50%;background:#d4ae6a}</style><main><div class="trio">${card('Yellow')}${card('White')}</div><p>After the cards</p></main>`;
const result = JSON.parse(execFileSync('php', ['-r', php, root, html], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress wp-includes/css/dist/block-library/style.min.css');
const core = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 400, height: 900 } });
    const measure = () => page.evaluate(() => {
        const box = el => (r => [Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)])(el.getBoundingClientRect());
        const text = (selector, t) => [...document.querySelectorAll(selector)].find(e => e.textContent.trim() === t);
        return { label: box(text('b', 'Yellow gold')), after: box(text('p', 'After the cards')) };
    });
    await page.setContent(html);
    const source = await measure();
    await page.setContent(`<!doctype html><style>${core}\n${css}</style>${result.serialized_blocks}`);
    const candidate = await measure();
    console.log(JSON.stringify({ source, candidate }));
    assert.deepEqual(candidate, source, 'the card group keeps the anchor\'s flex column, centring and gap');
} finally {
    await browser.close();
}
