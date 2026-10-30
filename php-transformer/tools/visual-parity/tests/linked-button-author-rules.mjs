import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../../..', import.meta.url).pathname;
const code = 'require $argv[1] . "/vendor/autoload.php"; $fixture = require $argv[1] . "/tests/fixtures/linked-button-author-rules.php"; echo json_encode(array("fixture" => $fixture, "plan" => (new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile($fixture)->toArray()["source_reports"]["wordpress_site_plan"]));';
const { fixture, plan } = JSON.parse(execFileSync('php', ['-r', code, root], { encoding: 'utf8' }));
const theme = JSON.parse(plan.writes.find(write => write.target_path === 'theme.json').payload.data);
const spacing = theme.settings.spacing.spacingSizes.map(({ slug, size }) => `:root{--wp--preset--spacing--${slug}:${size}}`).join('');
const globals = spacing + theme.settings.color.palette.map(({ slug, color }) => `:root{--wp--preset--color--${slug}:${color}}.has-${slug}-background-color{background-color:var(--wp--preset--color--${slug})!important}`).join('');
const css = plan.assets.filter(asset => asset.kind === 'css' && asset.stylesheet_target !== 'editor').map(asset => asset.content).join('\n');
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
            values.push([await button.evaluate(el => el.getBoundingClientRect().height), resting, await button.evaluate(el => getComputedStyle(el).backgroundColor)]);
        }
        return values;
    };
    await page.setContent(`<style>${fixture.files['assets/site.css']}</style>${fixture.files['index.html']}`);
    const source = await measure('a > button');
    assert.equal(source.length, 3);
    for (const values of source) assert.deepEqual(values, [48, 'rgb(255, 255, 255)', 'rgb(239, 246, 255)']);
    await page.setContent(`<style id="global-styles">${globals}</style><style>${css}</style>${markup}`);
    const candidate = await measure('.wp-block-button__link');
    assert.deepEqual(candidate, source, 'All icon variants keep source height and resting/hover backgrounds');
    console.log(JSON.stringify({ source, candidate }));
} finally {
    await browser.close();
}
