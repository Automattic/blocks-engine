import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($argv[2])->toArray());';
const html = '<style>.w{width:320px}.w svg{width:100%;filter:drop-shadow(6px 6px 0 #123456)}</style><main><h1>W</h1><div class="w"><svg width="220" height="60" viewBox="0 0 220 60"><defs><path id="curve" d="M10 50 Q100 -20 210 50"/></defs><text><textPath href="#curve" startOffset="25%">Label</textPath></text></svg></div></main>';
const result = JSON.parse(execFileSync('php', ['-r', php, root, html], { encoding: 'utf8' }));
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.route('https://fixture.test/**', route => {
        const asset = result.assets.find(asset => asset.path === new URL(route.request().url()).pathname.slice(1));
        return route.fulfill({ contentType: asset?.mime_type || 'text/plain', body: asset?.content || '' });
    });
    const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
    await page.setContent(`<base href="https://fixture.test/"><style>${css}</style>${result.serialized_blocks}`);
    const image = page.locator('.w img');
    await image.evaluate(image => image.decode());
    const computed = await image.evaluate(image => ({ width: getComputedStyle(image).width, filter: getComputedStyle(image).filter }));
    assert.equal(computed.width, '320px', 'authored width overrides the 220px intrinsic width');
    assert.equal(computed.filter, 'drop-shadow(rgb(18, 52, 86) 6px 6px 0px)');
    // The shared size class also applies to a flex item. Projection keeps that
    // width on figures, including the icon in normal flow, so its auto margins
    // must be able to center the figure rather than just the image inside it.
    const icon = classes => `<svg class="${classes}" width="24" height="24" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>`;
    const centered = '<style>svg{display:block}.card{width:280px}.row{display:flex}.icon{width:28px;height:28px}.centered{margin-inline:auto}@media(max-width:600px){.centered{margin-inline:0}}</style>'
        + `<main><div class="card">${icon('icon centered')}<p>Menu</p></div><div class="row">${icon('icon')}<p>Other</p></div></main>`;
    const cards = JSON.parse(execFileSync('php', ['-r', php, root, centered], { encoding: 'utf8' }));
    const cardCss = cards.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
    for (const [width, expectedOffset] of [[900, 126], [390, 0]]) {
        await page.setViewportSize({ width, height: 900 });
        for (const markup of [centered, `<style>${cardCss}</style>${cards.serialized_blocks}`]) {
            await page.setContent(markup);
            const bounds = await page.locator('.card').evaluate(card => {
                const artwork = card.querySelector('svg, img').getBoundingClientRect();
                return { offset: artwork.x - card.getBoundingClientRect().x, width: artwork.width };
            });
            assert.deepEqual(bounds, { offset: expectedOffset, width: 28 }, `icon position and width at ${width}px`);
        }
    }
    console.log('Passive SVG image projection browser check passed');
} finally {
    await browser.close();
}
