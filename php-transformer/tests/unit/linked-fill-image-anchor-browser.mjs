import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($argv[2])->toArray());';
// A product card links its media box and its name separately, so the media
// link lands inside the image block: <figure><a><img></a></figure>. The source
// image fills its box (absolute, 100% on both axes, padded). core makes that
// anchor inline-block; the image's percentages must still resolve against the
// box, not against a shrink-to-fit anchor that sizes from the image itself.
const svg = `data:image/svg+xml,${encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="560" height="560"><rect width="560" height="560" fill="#123"/></svg>')}`;
const html = `<style>*{box-sizing:border-box}body{margin:0}img{display:block;max-width:100%}.card{display:flex;flex-direction:column;align-items:center;gap:14px;width:300px;text-align:center}.card__media{display:block;width:100%;text-decoration:none}`
    + `.ph{position:relative;background:#eee;overflow:hidden;aspect-ratio:1}.ph img{position:absolute;inset:0;width:100%;height:100%;object-fit:contain}.card .ph{aspect-ratio:4/5}.card .ph img{padding:9%}.card .ph .alt{opacity:0}</style>`
    + `<article class="card"><a class="card__media" href="/rings/a/"><div class="ph"><img class="main" src="${svg}" width="560" height="560" alt="Ring A"><img class="alt" src="${svg}" width="560" height="560" alt=""></div></a>`
    + `<a class="card__name" href="/rings/a/">Ring A</a><span class="card__sub">Round diamond</span></article>`;
const result = JSON.parse(execFileSync('php', ['-r', php, root, html], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
assert.match(result.serialized_blocks, /<figure class="[^"]*blocks-engine-synthetic-image-figure-fill[^"]*blocks-engine-linked-image-figure[^"]*"><a href="\/rings\/a\/"><img/, 'the media link lands inside a fill image figure');
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
// The real WordPress stylesheet, whose linked-image anchor is inline-block.
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress wp-includes/css/dist/block-library/style.min.css');
const core = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    // The painted image is the img content box, wherever the padding lives.
    const measure = () => page.locator('img[alt="Ring A"]').evaluate(img => {
        const box = img.getBoundingClientRect(), style = getComputedStyle(img);
        const left = parseFloat(style.paddingLeft) + parseFloat(style.borderLeftWidth), top = parseFloat(style.paddingTop) + parseFloat(style.borderTopWidth);
        return { x: Math.round(box.x + left), y: Math.round(box.y + top), width: Math.round(box.width - left - parseFloat(style.paddingRight) - parseFloat(style.borderRightWidth)), height: Math.round(box.height - top - parseFloat(style.paddingBottom) - parseFloat(style.borderBottomWidth)) };
    });
    await page.setContent(`<!doctype html>${html}`);
    const source = await measure();
    await page.setContent(`<!doctype html><style>body{margin:0}</style><style>${core}</style><style>${css}</style>${result.serialized_blocks}`);
    const candidate = await measure();
    console.log(JSON.stringify({ source, candidate }));
    assert.ok(source.width > 200, 'the source image paints');
    assert.deepEqual(candidate, source, 'the linked fill image paints at the source box');
} finally {
    await browser.close();
}
