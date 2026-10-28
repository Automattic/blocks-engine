import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const wpPath = process.env.WORDPRESS_PATH;
if (!wpPath) throw new Error('Set WORDPRESS_PATH to a WordPress checkout to load block-library CSS.');
const blockCss = readFileSync(`${wpPath}/wp-includes/css/dist/block-library/style.min.css`, 'utf8');
const fixture = readFileSync(`${root}/tests/fixtures/hidden-lone-button.html`, 'utf8');
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    for (const [variant, source] of [
        ['responsive', fixture],
        ['base', fixture.replace('.btn{display:inline-block', '.btn{display:none')],
        ['base with reveal', fixture.replace('.btn{display:inline-block', '.btn{display:none').replace('.nav .btn.hero-cta{display:none}', '.nav .btn.hero-cta{display:inline-block}')],
    ]) {
        const output = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$source = stream_get_contents(STDIN);
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(array('entrypoint'=>'index.html','files'=>array('index.html'=>$source)))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $a): bool => 'css' === ($a['kind'] ?? '') && 'editor' !== ($a['stylesheet_target'] ?? ''));
echo json_encode(array('markup'=>$result['serialized_blocks'],'css'=>implode("\\n",array_column($css,'content'))));
`, root], { input: source, encoding: 'utf8' }));
        for (const width of [390, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            const measure = () => page.evaluate(() => {
                const box = selector => {
                    const element = document.querySelector(selector);
                    const rect = element.getBoundingClientRect();
                    return { x: rect.x, width: rect.width, display: getComputedStyle(element).display };
                };
                return { brand: box('.brand'), icon: box('.icon'), cta: box('a[href="#quote"]'), wrapper: document.querySelector('.wp-block-buttons') ? box('.wp-block-buttons') : null };
            });
            await page.setContent(source);
            const expected = await measure();
            await page.setContent(`<style>${blockCss}\n${output.css}</style>${output.markup}`);
            const actual = await measure();
            console.log(JSON.stringify({ variant, width, expected, actual }));
            for (const item of ['brand', 'icon', 'cta']) {
                for (const dimension of ['x', 'width']) {
                    assert.ok(Math.abs(actual[item][dimension] - expected[item][dimension]) <= 1, `${variant} ${width}: ${item} ${dimension}`);
                }
            }
            assert.equal(actual.wrapper.display === 'none', expected.cta.display === 'none', `${variant} ${width}: wrapper visibility`);
            if (expected.cta.display === 'none') {
                // A shared row must not inherit one child's hidden state.
                const sharedDisplay = await page.locator('.wp-block-buttons').evaluate(wrapper => {
                    const sibling = document.createElement('div');
                    sibling.className = 'wp-block-button';
                    sibling.textContent = 'Visible sibling';
                    wrapper.append(sibling);
                    return getComputedStyle(wrapper).display;
                });
                assert.notEqual(sharedDisplay, 'none', `${variant} ${width}: shared row remains visible`);
            }
        }
    }
} finally {
    await browser.close();
}
console.log('Hidden lone button geometry passed');
