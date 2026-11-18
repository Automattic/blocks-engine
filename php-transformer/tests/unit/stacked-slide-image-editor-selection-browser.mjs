import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');
const root = new URL('../..', import.meta.url).pathname;
// Captured slides have ranked paint order and a full-surface caption overlay.
const source = `<style>.stage{position:relative;height:160px}.slide{position:absolute;inset:0;height:160px}.caption{position:absolute;inset:0;z-index:1000}img{display:block;width:240px;height:160px}</style><div class="stage">${[1, 2].map(i => `<div class="slide be-stacking-order-${i}" style="z-index:${i}"><div class="caption"><h2>Slide ${i}</h2></div><img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='240' height='160'/%3E" alt="Slide ${i}"></div>`).join('')}</div>`;
const code = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, root], { input: source, encoding: 'utf8' }));
const css = result.assets.filter(a => a.kind === 'css').map(a => a.content).join('\n');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage();
    for (const editor of [false, true]) {
        await page.setContent(`<style>.wp-block-image{margin:0}${css}</style><div class="${editor ? 'editor-styles-wrapper' : 'frontend'}">${result.serialized_blocks}</div>`);
        const slides = page.locator('.slide');
        assert.equal(await slides.count(), 2);
        const boxes = await slides.evaluateAll(nodes => nodes.map(n => n.getBoundingClientRect().y));
        if (!editor) {
            assert.equal(boxes[0], boxes[1], 'front-end slides keep their overlapping geometry');
            continue;
        }
        assert.ok(boxes[1] > boxes[0], 'editor slides occupy separate flow boxes');
        for (const image of await page.locator('figure.wp-block-image').all()) {
            await image.click({ timeout: 2000 });
        }
    }
    console.log('Stacked slide image selection and front-end geometry passed.');
} finally {
    await browser.close();
}
