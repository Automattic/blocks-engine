import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');
const root = new URL('../..', import.meta.url).pathname;
const source = readFileSync(new URL('../fixtures/fill-image-flex-row.html', import.meta.url), 'utf8');
const code = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, root], { input: source, encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const measurements = {};
    for (const mode of ['source', 'frontend', 'editor']) {
        const markup = result.serialized_blocks;
        await page.setContent(mode === 'source' ? source : `<style>.wp-block-image{margin:0}.wp-block-image img{height:auto;max-width:100%;vertical-align:bottom}${css}</style>${mode === 'editor' ? `<div class="editor-styles-wrapper">${markup}</div>` : markup}`);
        measurements[mode] = await page.locator('.row,.card,.media,img').evaluateAll(nodes => nodes.map(node => {
            const { x, y, width, height } = node.getBoundingClientRect();
            const rowY = document.querySelector('.row').getBoundingClientRect().y;
            return { display: getComputedStyle(node).display, x, y: y - rowY, width, height };
        }));
    }
    console.log(JSON.stringify(measurements));
    for (const mode of ['frontend', 'editor']) {
        assert.equal(measurements[mode][0].display, 'flex', `${mode} row retains flex layout`);
        assert.equal(measurements[mode].length, measurements.source.length);
        for (let i = 0; i < measurements.source.length; i++) {
            for (const axis of ['x', 'y', 'width', 'height']) {
                assert.ok(Math.abs(measurements[mode][i][axis] - measurements.source[i][axis]) < 1,
                    `${mode} element ${i} ${axis} retains source geometry`);
            }
        }
    }
    const figures = page.locator('figure.wp-block-image');
    assert.equal(await figures.count(), 4);
    for (let i = 0; i < 4; i++) await figures.nth(i).click({ timeout: 2000 });
    console.log('Fill image flex row geometry and editor hit boxes passed.');
} finally {
    await browser.close();
}
