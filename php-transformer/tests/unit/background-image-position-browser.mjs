import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');
const root = new URL('../..', import.meta.url).pathname;
const browser = await chromium.launch();
try {
    const page = await browser.newPage();
    for (const scale of ['cover', 'contain']) {
        for (const positionCss of ['div{background:0 0}', '.thumb{background-position:100% 0}', '']) {
            for (const linked of [true, false]) {
                const thumb = '<div class="thumb" style="background-image:url(&quot;/media/wide.webp&quot;)"></div>';
                const source = `<!doctype html><html><head><style>${positionCss}.thumb{background-size:${scale};height:209px}.card{width:288px}</style></head><body><main><article class="card">${linked ? `<a href="/post">${thumb}</a>` : thumb}<h2>Post</h2></article></main></body></html>`;
                const result = JSON.parse(execFileSync('php', ['-r', `require $argv[1].'/vendor/autoload.php'; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(['files'=>[['path'=>'index.html','kind'=>'html','content'=>stream_get_contents(STDIN)],['path'=>'media/wide.webp','kind'=>'asset','content'=>'x']]])->toArray());`, root], { input: source, encoding: 'utf8', maxBuffer: 1 << 26 }));
                const css = result.assets.filter(asset => asset.path.endsWith('.css')).map(asset => asset.content).join('\n');
                await page.setContent(source);
                const expected = await page.locator('.thumb').evaluate(host => getComputedStyle(host).backgroundPosition);
                await page.setContent(`<!doctype html><style>${css}</style>${result.serialized_blocks}`);
                const actual = await page.locator('img').evaluate(img => getComputedStyle(img).objectPosition);
                const normalize = value => value.replace(/\b0px\b/g, '0%');
                assert.equal(normalize(actual), normalize(expected), `${scale}/${positionCss}/linked=${linked}`);
            }
        }
    }
    console.log('Background image position browser contract passed.');
} finally {
    await browser.close();
}
