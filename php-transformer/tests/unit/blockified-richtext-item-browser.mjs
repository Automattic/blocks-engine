import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
// A styled span in a flex card is blockified by its parent: its own font sets
// the item's line box. Lowered to a paragraph carrier, it must keep that box.
const css = ':root{--t-xs:.72rem;--f-body:sans-serif}body{margin:0;font:16px/1.7 var(--f-body)}'
    + '.eyebrow{font:400 var(--t-xs)/1.5 var(--f-body);letter-spacing:.24em;text-transform:uppercase;color:#555}'
    + '.posts{display:grid;grid-template-columns:1fr 1fr;gap:24px;width:800px}.post{text-decoration:none;display:flex;flex-direction:column;gap:16px}.post h3{margin:0}';
const card = (href, title) => `<a class="post" href="${href}"><span class="eyebrow">Journal</span><h3>${title}</h3><p>Excerpt</p></a>`;
const html = `<!doctype html><html><head><link rel="stylesheet" href="s.css"></head><body><main><div class="posts">${card('/p/', 'Title one')}${card('/q/', 'Title two')}</div></main></body></html>`;
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(array("entrypoint" => "index.html", "files" => array("index.html" => $argv[2], "s.css" => $argv[3])))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', php, root, html, css], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
const generated = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress wp-includes/css/dist/block-library/style.min.css');
const core = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1000, height: 600 } });
    const measure = () => page.evaluate(() => [...document.querySelectorAll('h3')].map(h => {
        const card = h.closest('a');
        return Math.round(h.getBoundingClientRect().top - card.getBoundingClientRect().top);
    }));
    await page.setContent(html.replace('<link rel="stylesheet" href="s.css">', `<style>${css}</style>`));
    const source = await measure();
    await page.setContent(`<!doctype html><style>${core}\n${generated}</style><main>${result.serialized_blocks}</main>`);
    const candidate = await measure();
    console.log(JSON.stringify({ source, candidate }));
    assert.deepEqual(candidate, source, 'each card title keeps its source offset below the blockified eyebrow');
} finally {
    await browser.close();
}
