import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../../..', import.meta.url).pathname;
const source = '<!doctype html><style>body{margin:0;font:16px Arial}figure{margin:0}.review{box-sizing:border-box;width:340px;min-height:112px;padding:24px 36px 20px}.review blockquote{margin:0 0 12px;font-size:14px;font-style:italic;line-height:1.45}.review figcaption{font-weight:700}</style><figure class="review"><blockquote>A quotation spanning several lines with enough words to wrap and exceed the minimum height of this figure, so the citation keeps its authored separation.</blockquote><figcaption>Publication</figcaption></figure><div id="next">Next section</div>';
const result = JSON.parse(execFileSync('php', ['-r', 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(["entrypoint"=>"index.html","files"=>["index.html"=>stream_get_contents(STDIN)]])->toArray());', root], { input: source, encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content ?? '').join('\n');
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    const measure = async html => {
        await page.setContent(html);
        return page.locator('.review').evaluate(quote => {
            const text = quote.querySelector('blockquote,p');
            const box = text.getBoundingClientRect();
            const caption = quote.querySelector('figcaption,cite > span');
            return {
                textTop: box.top,
                textHeight: box.height,
                marginBottom: parseFloat(getComputedStyle(text).marginBottom),
                captionTop: caption.getBoundingClientRect().top,
                captionHeight: caption.getBoundingClientRect().height,
                figureHeight: quote.getBoundingClientRect().height,
                nextTop: document.querySelector('#next').getBoundingClientRect().top,
            };
        });
    };
    for (const width of [390, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        const expected = await measure(source);
        const actual = await measure(`<!doctype html><style>${css}</style>${result.serialized_blocks}`);
        for (const key of Object.keys(expected)) {
            assert.ok(Math.abs(actual[key] - expected[key]) < .1, `${width}px ${key}: expected ${expected[key]}, got ${actual[key]}`);
        }
    }
    console.log('OK: figure quotation block box and citation spacing');
} finally {
    await browser.close();
}
