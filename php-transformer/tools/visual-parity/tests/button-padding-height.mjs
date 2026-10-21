import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { wordpressButtonCss } from './wordpress-button-css.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const source = readFileSync(path.join(root, 'tests/fixtures/button-padding-height.html'), 'utf8');
const transform = (source) => JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''));
echo json_encode(array('html' => $result['serialized_blocks'], 'css' => implode("\\n", array_column($css, 'content'))));
`, root, Buffer.from(source).toString('base64')], { encoding: 'utf8' }));
const transformed = transform(source);
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 600 } });
    const measure = async (html, controls) => {
        await page.setContent(html);
        return page.evaluate((selector) => {
            const heights = [...document.querySelectorAll(selector)].map((element) => element.getBoundingClientRect().height);
            const trustTop = [...document.querySelectorAll('span, p')].find((element) => element.textContent === 'Fast turnaround').getBoundingClientRect().top;
            return { heights, trustTop };
        }, controls);
    };
    const before = await measure(source, '.row, button');
    const after = await measure(`<!doctype html><style>${wordpressButtonCss}${transformed.css}</style>${transformed.html}`, '.row, .wp-block-buttons, .wp-block-button, .wp-block-button__link');
    console.log(`Padded button geometry: source=${JSON.stringify(before)} transformed=${JSON.stringify(after)}`);
    assert.ok(before.heights.every((height) => Math.abs(height - 48) < 0.1));
    assert.ok(after.heights.every((height) => Math.abs(height - 48) < 0.1), 'row and all button carriers must contain the padded 48px control');
    assert.ok(Math.abs(after.trustTop - before.trustTop) < 0.1, 'following content must retain its source position');
    for (const [name, css, control] of [
        ['stretch', '.cta{height:auto;padding:12px 24px;display:inline-block;background:#4c2929;color:#fff}.fluid-engine .sqs-stretched .cta{height:100%;display:flex;flex:1;padding-top:0;padding-bottom:0}', '<div class="fluid-engine"><div class="sqs-stretched" style="height:78px"><a class="cta" href="/order">Order</a></div></div>'],
        ['tall', '*{box-sizing:border-box}.btn{display:inline-flex;align-items:center;height:60px;padding:8px 16px}', '<div><button class="btn">Quote</button></div>'],
        ['borderbox', '*{box-sizing:border-box;border:0 solid}.btn{display:inline-flex;align-items:center;height:2.25rem;padding:1.5rem 1.75rem;background:#fff}', '<div><button class="btn">Quote</button></div>'],
        ['contentbox', '.btn{box-sizing:content-box;border:0;display:inline-flex;align-items:center;height:2.25rem;padding:1.5rem 1.75rem;background:#fff}', '<div><button class="btn">Quote</button></div>'],
    ]) {
        const fixture = `<!doctype html><style>${css}</style>${control}<p>after</p>`;
        const output = transform(fixture);
        const geometry = async (html, selector) => {
            await page.setContent(html);
            return page.evaluate((selector) => ({
                link: document.querySelector(selector).getBoundingClientRect().height,
                wrapper: document.querySelector('.wp-block-buttons')?.getBoundingClientRect().height,
                after: [...document.querySelectorAll('p')].find(p => p.textContent === 'after').getBoundingClientRect().top,
            }), selector);
        };
        const expected = await geometry(fixture, 'button, a');
        const actual = await geometry(`<!doctype html><style>${wordpressButtonCss}${output.css}</style>${output.html}`, '.wp-block-button__link');
        console.log(`${name}: source=${JSON.stringify(expected)} transformed=${JSON.stringify(actual)}`);
        for (const key of ['link', 'wrapper', 'after']) {
            assert.ok(Math.abs(actual[key] - expected[key === 'wrapper' ? 'link' : key]) <= 1, `${name} ${key} must match source`);
        }
    }
    const stretched = `<style>.cta{height:auto;display:inline-block;padding:12px 24px;background:#4c2929;color:#fff}.fluid-engine .sqs-stretched .cta{height:100%;display:flex;flex:1;padding-top:0;padding-bottom:0;align-items:center;justify-content:center}</style>
<div class="fluid-engine"><div class="sqs-stretched" style="height:78px"><a class="cta" href="/order">Order Now</a></div></div>`;
    const stretchedOutput = transform(stretched);
    await page.setContent(stretched);
    const sourceHeight = await page.locator('.cta').evaluate((element) => element.getBoundingClientRect().height);
    await page.setContent(`<!doctype html><style>${wordpressButtonCss}${stretchedOutput.css}</style>${stretchedOutput.html}`);
    const linkHeight = await page.locator('.wp-block-button__link').evaluate((element) => element.getBoundingClientRect().height);
    console.log(`Stretched button geometry: source=${sourceHeight} transformed=${linkHeight}`);
    assert.equal(sourceHeight, 78);
    assert.equal(linkHeight, 78, 'percentage height must resolve through the wrapper chain');
} finally {
    await browser.close();
}
console.log('Padded button height geometry passed');
