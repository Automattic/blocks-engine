import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

// Render actual Core markup and CSS, not a hand-written approximation.
// WORDPRESS_ROOT points to a bootable local WordPress installation.
const root = new URL('../../..', import.meta.url).pathname;
assert.ok(process.env.WORDPRESS_ROOT, 'Set WORDPRESS_ROOT to a local WordPress installation');
const rendered = execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$html = '<header><div class="bar"><ul class="nav"><li><a href="/">Home</a></li><li><a href="/about/">About</a></li><li><a href="/blog/">Blog</a></li></ul><select class="menu-select"><option value="#">Navigate to...</option><option value="/">Home</option><option value="/about/">About</option><option value="/blog/">Blog</option></select></div></header><main><p>Hi</p></main>';
$css = '.menu-select{display:none!important}@media(max-width:767px){.bar .nav{display:none}.menu-select{display:block!important;width:100%}}';
$x = (new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(array('entrypoint'=>'website/index.html','files'=>array('website/index.html'=>'<style>'.$css.'</style>'.$html)))->toArray()['source_reports']['compiled_site'];
require getenv('WORDPRESS_ROOT') . '/wp-load.php';
$markup = do_blocks($x['pages'][0]['block_markup']);
$styles = file_get_contents(getenv('WORDPRESS_ROOT') . '/wp-includes/blocks/navigation/style.css');
foreach ($x['assets'] as $asset) $styles .= $asset['content'] ?? '';
echo '<!doctype html><style>'.$styles.'</style>'.$markup;
`, root], { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_PATH });
try {
    for (const width of [390, 700, 768, 1200]) {
        const page = await browser.newPage({ viewport: { width, height: 800 } });
        await page.setContent(rendered);
        const visible = async (selector) => page.locator(selector).evaluateAll(elements => elements.filter(el => el.getBoundingClientRect().width > 0 && el.getBoundingClientRect().height > 0).length);
        assert.equal(await visible('nav'), 1, `${width}: host is visible`);
        assert.equal(await visible('.wp-block-navigation__responsive-container-open'), width < 768 ? 1 : 0, `${width}: native opener visibility`);
        assert.equal(await visible('nav a'), width < 768 ? 0 : 3, `${width}: closed menu links`);
        if (width < 768) {
            // Apply the open state owned by Core's view module to verify that
            // source CSS cannot hide the destinations inside the overlay.
            await page.locator('.wp-block-navigation__responsive-container').evaluate(el => el.classList.add('is-menu-open'));
            assert.equal(await visible('nav a'), 3, `${width}: every overlay destination is visible`);
        }
        console.log(`${width}px: ${width < 768 ? 'visible native opener; all three links visible in open overlay' : 'all three inline links visible; opener hidden'}`);
        await page.close();
    }
} finally {
    await browser.close();
}
