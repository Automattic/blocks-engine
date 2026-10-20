import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../../..', import.meta.url).pathname;
const code = 'require $argv[1] . "/vendor/autoload.php"; $rows = array(); foreach (require $argv[1] . "/tests/fixtures/synthetic-inline-image-percentage.php" as $fixture) { $fixture["result"] = (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform("<style>" . $fixture["css"] . "</style>" . $fixture["html"])->toArray(); $rows[] = $fixture; } echo json_encode($rows);';
const fixtures = JSON.parse(execFileSync('php', ['-r', code, root], { encoding: 'utf8' }));
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    // Serve a neutral square locally; both documents use the exact source URL.
    const png = execFileSync('php', ['-r', '$im=imagecreatetruecolor(120,120); imagepng($im);']);
    await page.route('**/square.png', route => route.fulfill({ contentType: 'image/png', body: png }));
    for (const fixture of fixtures) {
        const measure = async (html, css) => {
            await page.setContent(`<!doctype html><base href="https://fixture.test/"><style>${css}</style>${html}`);
            await page.locator('img').evaluate(image => image.decode());
            return page.locator('img').evaluate(image => {
                const { x, y, width, height } = image.getBoundingClientRect();
                return { x, y, width, height };
            });
        };
        const source = await measure(fixture.html, fixture.css);
        const css = fixture.result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content ?? '').join('\n');
        const imported = await measure(fixture.result.serialized_blocks, css);
        assert.equal(source.width, fixture.size, `${fixture.name}: source width`);
        assert.equal(source.height, fixture.size, `${fixture.name}: source height`);
        assert.deepEqual(imported, source, `${fixture.name}: imported geometry matches source`);
    }
    console.log('OK: synthetic inline image percentage basis and existing image geometry');
} finally {
    await browser.close();
}
