import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../../..', import.meta.url).pathname;
const code = 'require $argv[1] . "/vendor/autoload.php"; $fixture = require $argv[1] . "/tests/fixtures/design-system/hover-variant-buttons.php"; echo json_encode(array("fixture" => $fixture, "plan" => (new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile($fixture)->toArray()["source_reports"]["wordpress_site_plan"]));';
const { fixture, plan } = JSON.parse(execFileSync('php', ['-r', code, root], { encoding: 'utf8' }));
const theme = JSON.parse(plan.writes.find(write => write.target_path === 'theme.json').payload.data);
const globals = theme.settings.color.palette.map(({ slug, color }) => `:root{--wp--preset--color--${slug}:${color}}.has-${slug}-background-color{background-color:var(--wp--preset--color--${slug})!important}`).join('');
const css = plan.assets.filter(asset => asset.kind === 'css' && !asset.content.includes('editor-styles-wrapper')).map(asset => asset.content).join('\n');
const markup = [...plan.template_parts, ...plan.pages].map(part => part.canonical_block_markup).join('');
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    const measure = async selector => {
        const values = [];
        for (const button of await page.locator(selector).all()) {
            await page.mouse.move(1000, 800);
            const resting = await button.evaluate(el => getComputedStyle(el).backgroundColor);
            await button.hover();
            values.push([resting, await button.evaluate(el => getComputedStyle(el).backgroundColor)]);
        }
        return values;
    };
    await page.setContent(`<style>${fixture.files['assets/site.css']}</style>${fixture.files['index.html']}`);
    const source = await measure('a.btn');
    await page.setContent(`<style id="global-styles">${globals}</style><style>${css}</style>${markup}`);
    const candidate = await measure('.wp-block-button__link');
    console.log(JSON.stringify({ source, candidate }));
    assert.deepEqual(candidate, source, 'Resting and hover colours match the reduced source fixture');
    await page.locator('#global-styles').evaluate(el => { el.textContent = el.textContent.replace('--wp--preset--color--primary:#2563eb', '--wp--preset--color--primary:#00a86b'); });
    const edited = await measure('.wp-block-button__link');
    assert.deepEqual(edited, source.map(([resting, hover]) => [resting === 'rgb(37, 99, 235)' ? 'rgb(0, 168, 107)' : resting, hover]));
    console.log('Hover-variant buttons: source paint, hover and changed primary passed');
} finally {
    await browser.close();
}
