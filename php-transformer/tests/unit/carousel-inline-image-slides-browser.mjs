import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($argv[2])->toArray());';
// A Bootstrap-style slideshow whose slides are linked images in inline-level wrappers: each
// image follows inline flow, so it compiles to a box-less synthetic figure.
const image = fill => `data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="448" height="283"><rect width="448" height="283" fill="${fill}"/></svg>`)}`;
const slides = ['#468', '#684', '#846'].map((fill, index) => `<div class="carousel-item${index ? '' : ' active'}"><div class="frame"><a href="/album"><img class="photo" src="${image(fill)}" alt=""></a></div></div>`).join('');
const html = '<style>body{margin:0}.carousel{position:relative;width:427px}.carousel-inner{position:relative;overflow:hidden}.carousel-item{position:relative;display:none;float:left;width:100%;margin-right:-100%}.carousel-item.active{display:block}.frame{display:inline-block;position:relative;overflow:hidden}.photo{max-width:100%;height:auto}.carousel-control-prev,.carousel-control-next{position:absolute;top:0;bottom:0;width:10%}.carousel-control-prev{left:0}.carousel-control-next{right:0}</style>'
    + `<main><div id="photos" class="carousel slide" data-bs-ride="carousel"><div class="carousel-inner">${slides}</div><button class="carousel-control-prev" type="button" data-bs-target="#photos" data-bs-slide="prev">Previous</button><button class="carousel-control-next" type="button" data-bs-target="#photos" data-bs-slide="next">Next</button></div></main>`;
const result = JSON.parse(execFileSync('php', ['-r', php, root, html], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
const css = [...result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content),
    ...(result.source_reports.generated_blocks ?? []).flatMap(block => Object.entries(block.assets ?? {}).filter(([name]) => name.endsWith('.css')).map(([, content]) => content))].join('\n');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const measure = selector => page.evaluate(async selector => {
        await Promise.all([...document.images].map(img => img.decode()));
        const carousel = document.querySelector(selector);
        return { height: Math.round(carousel.getBoundingClientRect().height), tops: [...carousel.querySelectorAll('img')].map(img => Math.round(img.getBoundingClientRect().top)) };
    }, selector);
    await page.setContent(`<!doctype html>${html}`);
    const source = await measure('#photos');
    await page.setContent(`<!doctype html><style>body{margin:0}${css}</style>${result.serialized_blocks}`);
    const candidate = await measure('.blocks-engine-authored-carousel');
    console.log(JSON.stringify({ source, candidate }));
    assert.match(result.serialized_blocks, /authored-carousel--slideshow/);
    assert.match(result.serialized_blocks, /synthetic-image-figure-inline/);
    assert.equal(candidate.tops.length, 3, 'every slide image is kept');
    assert.ok(candidate.tops.every(top => top === candidate.tops[0]), 'inactive slides stack on the active slide, not below it');
    assert.ok(Math.abs(candidate.height - source.height) <= 8, `the slideshow is one slide tall: ${JSON.stringify({ source, candidate })}`);
} finally {
    await browser.close();
}
