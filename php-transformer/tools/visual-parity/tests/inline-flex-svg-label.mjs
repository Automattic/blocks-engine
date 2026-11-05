import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../../..', import.meta.url).pathname;
const source = readFileSync(`${root}/tests/fixtures/inline-flex-svg-label.html`, 'utf8');
const result = JSON.parse(execFileSync('php', ['-r', 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(["entrypoint"=>"index.html","files"=>["index.html"=>file_get_contents($argv[1]."/tests/fixtures/inline-flex-svg-label.html")]])->toArray());', root], { encoding: 'utf8' }));
assert.match(result.serialized_blocks, /blocks-engine-source-box-paragraph/, 'the lowered label owns its source layout box, rather than an inline-only carrier');
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content ?? '').join('\n');
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    await page.route('https://fixture.test/**', route => {
        const asset = result.assets.find(asset => new URL(route.request().url()).pathname === `/${asset.path}`);
        return asset ? route.fulfill({ contentType: asset.mime_type, body: asset.content }) : route.abort();
    });
    const measure = async html => {
        await page.setContent(html);
        await page.locator('img').evaluateAll(images => Promise.all(images.map(image => image.decode())));
        return page.locator('.turn-hint').evaluate(label => {
            const icon = label.querySelector('svg, img').getBoundingClientRect();
            const range = document.createRange();
            range.selectNodeContents(label.lastChild);
            const text = range.getBoundingClientRect();
            return { width: label.getBoundingClientRect().width, gap: text.left - icon.right, iconWidth: icon.width };
        });
    };
    for (const width of [390, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        const expected = await measure(source);
        const actual = await measure(`<!doctype html><base href="https://fixture.test/"><style>${css}</style>${result.serialized_blocks}`);
        assert.equal(expected.gap, 10);
        assert.equal(expected.iconWidth, 18);
        for (const key of Object.keys(expected)) {
            assert.ok(Math.abs(actual[key] - expected[key]) < .1, `${width}px ${key}: expected ${expected[key]}, got ${actual[key]}`);
        }
    }
    console.log('OK: inline-flex SVG label width and spacing');
} finally {
    await browser.close();
}
