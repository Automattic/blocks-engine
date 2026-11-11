import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = fileURLToPath(new URL('../../', import.meta.url));
const fixture = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$source = '<!doctype html><html><head><style>.wrap figure div,.wrap figure img{opacity:.5;filter:blur(2px)}.bg{width:300px;height:200px;background-size:cover}</style></head><body><main><div class="wrap"><figure id="thumbnail" class="content-fill"><div class="bg" style="background-image:url(x.jpg);background-position:50% 4%"></div></figure><figure><img src="x.jpg" alt="Real image"></figure><p>Caption</p></div></main></body></html>';
$result = (new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(array('files' => array(
    array('path' => 'index.html', 'kind' => 'html', 'content' => $source),
    array('path' => 'x.jpg', 'kind' => 'image', 'content' => base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSFw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==')),
)))->toArray();
$css = '';
foreach ($result['assets'] as $asset) if (str_ends_with($asset['path'] ?? '', '.css')) $css .= $asset['content'];
echo json_encode(array('source' => $source, 'native' => '<style>' . $css . '</style>' . $result['serialized_blocks']));
`, root], { encoding: 'utf8' }));
const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
try {
    const page = await browser.newPage();
    const presentation = selector => page.locator(selector).evaluate(element => {
        let opacity = 1;
        const filters = [];
        for (let node = element; node; node = node.parentElement) {
            const style = getComputedStyle(node);
            opacity *= Number(style.opacity);
            if (style.filter !== 'none') filters.push(style.filter);
        }
        return { opacity, filters };
    });
    await page.setContent(fixture.source);
    const background = await presentation('.bg');
    const realImage = await presentation('img[alt="Real image"]');
    assert.deepEqual(background, { opacity: 0.5, filters: ['blur(2px)'] });
    await page.setContent(fixture.native);
    assert.equal(await page.locator('.blocks-engine-background-image > img').count(), 1);
    assert.deepEqual(await presentation('.blocks-engine-background-image > img'), background, 'synthetic background paint must not re-match author selectors');
    assert.deepEqual(await presentation('img[alt="Real image"]'), realImage, 'real source images must retain author styling');
} finally {
    await browser.close();
}
console.log('Background image selector ownership browser contract passed.');
