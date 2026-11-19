import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const css = '*,:before,:after{box-sizing:border-box;margin:0;padding:0}body{font:16px/1.5 sans-serif}a{color:inherit;text-decoration:inherit}'
    + '.row{display:flex;align-items:center;gap:16px;padding:20px;border:1px solid #ddd;border-radius:16px}.icon{display:flex;width:48px;height:48px;flex-shrink:0;align-items:center;justify-content:center;background:#eee}.glyph{width:24px;height:24px}.cue{margin-left:auto}.title{font-size:16px}.summary{font-size:14px;margin-top:2px}';
const html = '<html><head><link rel="stylesheet" href="s.css"></head><body><main><a class="row" href="https://example.test/location/">'
    + '<div class="icon"><svg class="glyph" width="24" height="24" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg></div>'
    + '<div><h3 class="title">Our Location</h3><p class="summary">Where to find us and our operating hours.</p></div><span class="cue">→</span></a></main></body></html>';
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(array("entrypoint"=>"index.html","files"=>array("index.html"=>$argv[2],"s.css"=>$argv[3])))->toArray());';
const out = JSON.parse(execFileSync('php', ['-r', php, root, html, css], { encoding: 'utf8' }));
const generated = out.assets.filter(a => a.kind === 'css').map(a => a.content).join('\n');
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress core block CSS');
const core = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage();
    const measure = () => page.evaluate(() => ['.row', '.icon', '.title', '.summary', '.cue'].map(s => {
        const r = document.querySelector(s).getBoundingClientRect();
        return [r.x, r.y, r.width, r.height].map(n => Math.round(n * 100) / 100);
    }));
    for (const width of [390, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.setContent(html.replace('<link rel="stylesheet" href="s.css">', `<style>${css}</style>`));
        const source = await measure();
        await page.setContent(`<style>${core}\n${generated}</style>${out.serialized_blocks}`);
        assert.deepEqual(await measure(), source, `row source geometry at ${width}`);
        for (const point of [{ x: 3, y: 3 }, { x: width / 2, y: source[0][3] / 2 }]) {
            assert.equal(await page.evaluate(({ x, y }) => document.elementFromPoint(x, y)?.closest('a')?.href, point), 'https://example.test/location/');
        }
    }
    console.log('Trailing-label row card geometry and hitbox passed at 390, 768 and 1440.');
} finally {
    await browser.close();
}
