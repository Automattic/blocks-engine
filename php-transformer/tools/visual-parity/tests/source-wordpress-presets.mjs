import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../../..', import.meta.url).pathname;
const code = 'require $argv[1] . "/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(require $argv[1] . "/tests/fixtures/design-system/source-wordpress-presets.php")->toArray()["source_reports"]["wordpress_site_plan"]);';
const plan = JSON.parse(execFileSync('php', ['-r', code, root], { encoding: 'utf8' }));
const theme = JSON.parse(plan.writes.find(write => write.target_path === 'theme.json').payload.data);
const palette = theme.settings.color.palette;
assert.equal(palette.find(entry => entry.slug === 'accent-2').color, '#ffffff');
// Reproduce core's palette CSS before the late capture sheet. The regression
// is load-order-sensitive: both source :root declarations and !important
// preset utility classes must stay source-owned.
const globalStyles = palette.map(({ slug, color }) => `:root{--wp--preset--color--${slug}:${color}}.has-${slug}-background-color{background-color:var(--wp--preset--color--${slug})!important}`).join('');
const css = plan.assets.filter(asset => asset.kind === 'css' && !asset.content.includes('editor-styles-wrapper')).map(asset => asset.content).join('\n');
assert.equal(plan.pages.length, 2);
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    for (const route of plan.pages) {
        await page.setContent(`<style id="global-styles">${globalStyles}</style><style>${css}</style>${route.canonical_block_markup}`);
        assert.equal(await page.locator('.card').evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(255, 255, 255)', `${route.source_path}: the card is white, not source pink`);
        assert.equal(await page.locator('.source-swatch').evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(246, 207, 244)', `${route.source_path}: an authored utility class still paints source pink`);
        // A Global Styles palette edit replaces the earlier stylesheet, not an
        // inline override (which could hide the original cascade collision).
        await page.locator('#global-styles').evaluate(el => { el.textContent = el.textContent.replace('--wp--preset--color--accent-2:#ffffff', '--wp--preset--color--accent-2:#00a86b'); });
        assert.equal(await page.locator('.card').evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(0, 168, 107)', `${route.source_path}: the card follows the edited palette`);
        assert.equal(await page.locator('.source-swatch').evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(246, 207, 244)', `${route.source_path}: the source swatch stays independent of the destination preset`);
    }
    console.log('Source WordPress presets: both shared-stylesheet routes render white and follow palette edits');
} finally {
    await browser.close();
}
