import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(["files"=>[["path"=>"index.html","kind"=>"html","content"=>$argv[2]]]])->toArray());';
const library = [process.env.BLOCK_LIBRARY_CSS, `${root}/tools/visual-parity/node_modules/@wordpress/block-library/build-style/style.css`].find(f => f && existsSync(f));
const core = library ? readFileSync(library, 'utf8') : '.wp-block-spacer{clear:both}';
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH });
try {
    const page = await browser.newPage({ viewport: { width: 1000, height: 900 } });
    for (const display of ['inline-block', 'block']) {
        // The empty divider splits two independent runs; the first break after
        // an inline-block ends its existing line rather than adding a line box.
        const html = `<!doctype html><style>body{margin:0;font:16px/25px sans-serif}main{width:1000px}section{display:${display};width:100%;height:40px;background:#ccc}.rule{margin:0;border:0;height:0}</style><main><br><br><section class="a">A</section><br><br><div class="rule"></div><br><br><section class="b">B</section><br><br><br><section class="c">C</section></main>`;
        const result = JSON.parse(execFileSync('php', ['-r', php, root, html], { encoding: 'utf8', maxBuffer: 1 << 26 }));
        const tops = () => page.evaluate(() => ['a', 'b', 'c'].map(c => document.querySelector(`.${c}`).getBoundingClientRect().top));
        await page.setContent(html);
        const source = await tops();
        const css = result.assets.filter(a => a.path.endsWith('.css')).map(a => a.content).join('\n');
        assert.ok(!result.serialized_blocks.includes('<!-- wp:html'), 'spacing stays native');
        assert.equal((result.serialized_blocks.match(/<!-- wp:spacer /g) || []).length, 4, 'each run becomes an editable spacer');
        await page.setContent(`<!doctype html><style>body{margin:0;font:16px/25px sans-serif}${core}\n${css}</style>${result.serialized_blocks}`);
        const actual = await tops();
        assert.ok(actual.every((v, i) => Math.abs(v - source[i]) <= 3), `${display}: ${actual} vs source ${source}`);
    }
} finally {
    await browser.close();
}
