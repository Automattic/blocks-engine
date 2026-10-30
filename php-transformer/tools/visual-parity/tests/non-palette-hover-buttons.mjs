import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../../..', import.meta.url).pathname;
const code = 'require $argv[1] . "/vendor/autoload.php"; $fixture = require $argv[1] . "/tests/fixtures/design-system/non-palette-hover-buttons.php"; echo json_encode(array("fixture" => $fixture, "plan" => (new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile($fixture)->toArray()["source_reports"]["wordpress_site_plan"]));';
const { fixture, plan } = JSON.parse(execFileSync('php', ['-r', code, root], { encoding: 'utf8' }));
const theme = JSON.parse(plan.writes.find(write => write.target_path === 'theme.json').payload.data);
const globals = theme.settings.color.palette.map(({ slug, color }) => `:root{--wp--preset--color--${slug}:${color}}.has-${slug}-background-color{background-color:var(--wp--preset--color--${slug})!important}`).join('');
const css = plan.assets.filter(asset => asset.kind === 'css' && asset.stylesheet_target !== 'editor').map(asset => asset.content).join('\n');
const markup = [...plan.template_parts, ...plan.pages].map(part => part.canonical_block_markup).join('');
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    const background = el => {
        const context = document.createElement('canvas').getContext('2d');
        context.fillStyle = getComputedStyle(el).backgroundColor;
        context.fillRect(0, 0, 1, 1);
        return [...context.getImageData(0, 0, 1, 1).data];
    };
    const measure = async selector => {
        const values = [];
        for (const button of await page.locator(selector).all()) {
            await page.mouse.move(1000, 800);
            const resting = await button.evaluate(background);
            await button.hover();
            values.push([resting, await button.evaluate(background)]);
        }
        return values;
    };
    await page.setContent(`<style>${fixture.files['assets/site.css']}</style>${fixture.files['index.html']}`);
    const source = await measure('a > button, a.btn');
    assert.deepEqual(source, Array.from({ length: 2 }, () => [[0, 0, 0, 0], [255, 255, 255, 26]]));
    await page.setContent(`<style id="global-styles">${globals}</style><style>${css}</style>${markup}`);
    const candidate = await measure('.wp-block-button__link');
    console.log(JSON.stringify({ source, candidate }));
    assert.deepEqual(candidate, source, 'Resting and hover colours match the reduced source fixture');
    console.log('Non-palette buttons: resting and hover backgrounds match both source shapes');
} finally {
    await browser.close();
}
