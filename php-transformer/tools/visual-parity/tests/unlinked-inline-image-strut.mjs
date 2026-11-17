import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../../..', import.meta.url).pathname;
const source = readFileSync(`${root}/tests/fixtures/unlinked-inline-image-strut.html`, 'utf8');
const code = 'require $argv[1] . "/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(array("entrypoint" => "index.html", "files" => array("index.html" => file_get_contents($argv[1] . "/tests/fixtures/unlinked-inline-image-strut.html"))))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, root], { encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content ?? '').join('\n');
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    await page.route('**/photo.jpg', route => route.fulfill({
        contentType: 'image/svg+xml',
        body: '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"/>',
    }));
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        const measure = async html => {
            await page.setContent(html);
            await page.locator('img').evaluateAll(images => Promise.all(images.map(image => image.decode())));
            return page.evaluate(() => ({
                heights: [...document.querySelectorAll('.approach-image')].map(node => node.getBoundingClientRect().height),
                nextY: [...document.querySelectorAll('.after')].map(node => node.getBoundingClientRect().y),
                displays: [...document.querySelectorAll('img')].map(node => getComputedStyle(node).display),
                textY: document.querySelector('.after p').getBoundingClientRect().y,
            }));
        };
        const before = await measure(source.replace('<head>', '<head><base href="https://fixture.test/">'));
        const after = await measure(`<!doctype html><base href="https://fixture.test/"><style>${css}</style>${result.serialized_blocks}`);
        assert.deepEqual(before.heights, [684, 680], `${width}: source inline strut and block control`);
        assert.deepEqual(after, before, `${width}: wrapper heights, next section positions and image displays match source`);
        console.log(`${width}: ${JSON.stringify(after)}`);
    }
} finally {
    await browser.close();
}
