import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($argv[2])->toArray());';
// A header's logo link is a grid item, so the browser blockifies it and no
// line box (strut) sits under the image. core/image re-emits the link inside a
// figure that must stay out of layout. The page's global `img{display:block}`
// also matches a positioned card image, whose figure owns its box; that must
// not carry the global rule onto every figure, the logo's included, where a
// block figure puts the inline-block link on a line and adds a strut.
const svg = `data:image/svg+xml,${encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="200" height="100"><rect width="200" height="100" fill="#123"/></svg>')}`;
const html = `<style>*{box-sizing:border-box}body{margin:0;line-height:1.7}img{max-width:100%;display:block}`
    + `.hdr__row{display:grid;grid-template-columns:1fr auto 1fr;align-items:center}.hdr__logo{line-height:0;padding-block:10px}.hdr__logo img{height:70px;width:auto}`
    + `.ph{position:relative;width:200px;aspect-ratio:1}.ph img{position:absolute;inset:0;width:100%;height:100%;object-fit:contain}</style>`
    + `<header class="hdr"><div class="hdr__row"><button class="burger" type="button">Menu</button><a class="hdr__logo" href="/"><img src="${svg}" width="200" height="100" alt="Logo"></a><a class="bag" href="/bag/">Bag</a></div></header>`
    + `<main><div class="ph"><img src="${svg}" width="200" height="100" alt="Ring"></div></main>`;
const result = JSON.parse(execFileSync('php', ['-r', php, root, html], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
assert.match(result.serialized_blocks, /<figure class="[^"]*blocks-engine-synthetic-flex-image-figure[^"]*blocks-engine-linked-image-figure[^"]*"><a href="\/" class="hdr__logo[^"]*"><img/, 'the logo link lands inside a linked image figure');
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
// The real WordPress stylesheet, whose linked-image anchor is inline-block.
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress wp-includes/css/dist/block-library/style.min.css');
const core = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 390, height: 900 } });
    const measure = () => page.evaluate(() => ({
        header: document.querySelector('header').getBoundingClientRect().height,
        logo: (rect => ({ y: rect.y, height: rect.height }))(document.querySelector('img[alt="Logo"]').getBoundingClientRect()),
        card: (rect => ({ y: rect.y, width: rect.width, height: rect.height }))(document.querySelector('img[alt="Ring"]').getBoundingClientRect()),
    }));
    await page.setContent(`<!doctype html>${html}`);
    const source = await measure();
    await page.setContent(`<!doctype html><style>body{margin:0}</style><style>${core}</style><style>${css}</style>${result.serialized_blocks}`);
    const candidate = await measure();
    console.log(JSON.stringify({ source, candidate }));
    assert.equal(source.header, 90, 'the source header is the padded logo');
    assert.deepEqual(candidate, source, 'the linked logo adds no line box and the positioned card image keeps its box');
} finally {
    await browser.close();
}
