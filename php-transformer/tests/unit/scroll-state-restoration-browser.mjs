import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');
const root = new URL('../..', import.meta.url).pathname;
// Read the runtime emitted through the public compiler, not the script owner.
const script = execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$config = htmlspecialchars(json_encode(['thresholdPx'=>50,'addClasses'=>['scrolled'],'removeClasses'=>['rest'],'styleTargets'=>[]]), ENT_QUOTES);
$html = '<header class="rest" data-blocks-engine-scroll-state="true" data-blocks-engine-scroll-state-config="'.$config.'"><p>Brand</p></header><main><p>Content</p></main>';
$result = (new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(['files'=>['index.html'=>$html]])->toArray();
foreach ($result['source_reports']['wordpress_site_plan']['writes'] as $write) {
    if ('functions.php' !== $write['target_path']) continue;
    foreach (token_get_all($write['payload']['data']) as $token) {
        if (is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0] && str_contains($token[1], 'function parseConfig')) {
            echo eval('return '.$token[1].';');
            exit;
        }
    }
}
exit(1);
`, root], { encoding: 'utf8' });
const browser = await chromium.launch();
try {
    const page = await browser.newPage();
    const config = JSON.stringify({ thresholdPx: 50, addClasses: ['scrolled'], removeClasses: ['rest'], styleTargets: [] });
    await page.setContent(`<header class="rest" data-blocks-engine-scroll-state="true" data-blocks-engine-scroll-state-config='${config}'>Brand</header><main style="height:3000px"></main>`);
    // A suspended rendering clock must not suspend scroll-state restoration.
    await page.evaluate(() => { window.requestAnimationFrame = () => 1; });
    await page.addScriptTag({ content: script });
    const state = async top => page.evaluate(top => {
        window.scrollTo({ top, behavior: 'instant' });
        window.dispatchEvent(new Event('scroll'));
        return document.querySelector('header').className;
    }, top);
    assert.equal(await state(200), 'scrolled', 'crossing the threshold updates without another frame');
    assert.equal(await state(0), 'rest', 'returning to the top restores the transparent resting header');
    console.log('Scroll-state restoration browser contract passed.');
} finally {
    await browser.close();
}
