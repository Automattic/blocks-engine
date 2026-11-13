import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../../', import.meta.url));
// Read the CSS actually shipped by the public generated-theme interface.
const css = execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$bootstrap = \\Automattic\\BlocksEngine\\PhpTransformer\\WordPressSitePlan\\ThemeBootstrap::functionsPhp([]);
if (!preg_match("/'css' => ('(?:[^'\\\\\\\\]|\\\\\\\\.)*')/s", $bootstrap, $match)) throw new RuntimeException('Missing editor settings CSS');
echo eval('return ' . $match[1] . ';');
`, root], { encoding: 'utf8' });

const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.setContent(`<style>
        .data-blocks-engine-capture-mobile-only { display:none }
        figure.blocks-engine-synthetic-image-figure-inline { display:contents }
        img { width:80px;height:60px;pointer-events:none }
        ${css}
        </style>
        <main><div class="block-editor-block-list__block data-blocks-engine-capture-mobile-only">
        <figure class="block-editor-block-list__block wp-block-image blocks-engine-synthetic-image-figure-inline" tabindex="0">
        <img alt="Editable responsive image" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='80' height='60'/%3E">
        </figure></div></main>`);
    const figure = page.locator('figure');
    assert.equal(await figure.isVisible(), false, 'frontend responsive visibility must remain unchanged');
    await page.locator('main').evaluate((main) => main.classList.add('editor-styles-wrapper'));
    await figure.click({ timeout: 2000 });
    assert.ok((await figure.boundingBox()).height >= 60, 'synthetic editing surface must have a selectable box');
    assert.equal(await page.locator('img').evaluate((img) => getComputedStyle(img).pointerEvents), 'auto');
    await page.locator('main').evaluate((main) => main.classList.remove('editor-styles-wrapper'));
    assert.equal(await figure.isVisible(), false, 'editor override must not leak into frontend');
} finally {
    await browser.close();
}
console.log('Responsive image editor interaction: selectable figure, visible responsive island, frontend unchanged');
