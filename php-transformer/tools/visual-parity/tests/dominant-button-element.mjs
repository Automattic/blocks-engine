import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../../..', import.meta.url).pathname;
assert.ok(process.env.WORDPRESS_PATH, 'WORDPRESS_PATH supplies real block-library and global-styles CSS');
const wp = process.env.WORDPRESS_PATH;
const nativeCss = readFileSync(`${wp}/wp-includes/css/dist/block-library/style.min.css`, 'utf8');
for (const variation of [false, true]) {
const data = JSON.parse(execFileSync('php', ['-r', `
require $argv[1].'/vendor/autoload.php';
$fixture = require $argv[1].'/tests/fixtures/design-system/buttons.php';
if ($argv[2] === 'variation') foreach ($fixture['files'] as &$html) $html = str_replace('</body>', '<button class="close" aria-label="Close" style="font-size:1.2rem;border-radius:50%">✕</button></body>', $html);
echo json_encode(array('fixture'=>$fixture, 'plan'=>(new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile($fixture)->toArray()['source_reports']['wordpress_site_plan']));`, root, variation ? 'variation' : 'element'], { encoding: 'utf8' }));
const { plan, fixture } = data;
const theme = JSON.parse(plan.writes.find(w => w.target_path === 'theme.json').payload.data);
const globals = value => execFileSync('php', [new URL('./button-global-styles.php', import.meta.url).pathname, wp], { input: JSON.stringify(value), encoding: 'utf8' });
const globalCss = globals(theme);
const css = plan.assets.filter(a => a.kind === 'css' && a.stylesheet_target !== 'editor').map(a => a.content).join('\n');
// Shared shell extraction may place the repeated controls in a template part.
const markup = plan.writes.filter(w => w.target_path.endsWith('.html')).map(w => w.payload.data).join('\n');
const measure = (page, selector) => page.locator(selector).evaluateAll(elements => elements.map(el => {
    const s = getComputedStyle(el);
    return Object.fromEntries(['fontFamily', 'fontSize', 'fontWeight', 'textTransform', 'letterSpacing', 'borderRadius', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'backgroundColor', 'color', 'borderTopWidth', 'borderTopStyle'].map(p => [p, s[p]]));
}));
const browser = await chromium.launch({ headless: true });
try {
    const source = await browser.newPage();
    const output = await browser.newPage();
    for (const width of [390, 768, 1440]) {
        await source.setViewportSize({ width, height: 900 });
        await output.setViewportSize({ width, height: 900 });
        for (const [route, html] of Object.entries(fixture.files)) {
            await source.setContent(html);
            const expected = await measure(source, '.btn');
            for (const editor of [false, true]) {
                // The editor loads global styles last; the front end first.
                const styles = editor ? `${nativeCss}\n${css}\n${globalCss}` : `${nativeCss}\n${globalCss}\n${css}`;
                await output.setContent(`<style>${styles}</style>${markup}`);
                assert.deepEqual((await measure(output, '.wp-block-button__link')).slice(0, 6), expected, `${route} ${width} ${editor ? 'editor' : 'front end'}`);
                const edited = structuredClone(theme);
                const look = variation ? edited.styles.blocks['core/button'].variations.primary : edited.styles.elements.button;
                look.color.background = '#123456';
                look.typography.fontSize = '20px';
                await output.setContent(`<style>${editor ? nativeCss + css + globals(edited) : nativeCss + globals(edited) + css}</style>${markup}`);
                const changed = await measure(output, '.wp-block-button__link');
                if (variation) {
                    assert.equal(changed[6].fontSize, '19.2px', 'icon keeps its own font size');
                    assert.equal(changed[6].borderRadius, '50%', 'icon keeps its own radius');
                }
                assert.equal(changed[0].backgroundColor, 'rgb(18, 52, 86)', 'Global Styles edits restyle primary fill');
                assert.equal(changed[0].fontSize, '20px', 'Global Styles edits restyle primary size');
                assert.equal(changed[3].backgroundColor, 'rgba(0, 0, 0, 0)', 'outline stays transparent');
                assert.equal(changed[5].fontSize, '13.44px', 'small CTA keeps its real size difference');
            }
        }
    }
    console.log('Dominant button element: source computed styles and Global Styles edits match on both cascade orders at 390/768/1440');
} finally { await browser.close(); }
}
