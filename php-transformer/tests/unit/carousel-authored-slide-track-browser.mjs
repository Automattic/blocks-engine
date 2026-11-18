import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress wp-includes/css/dist/block-library/style.min.css');
const blockLibrary = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
// Author CSS assets plus the generated blocks' own style.css, as WordPress enqueues both.
const transform = `require 'vendor/autoload.php'; $r = (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN), array())->toArray(); $css = ''; foreach ($r['assets'] ?? array() as $a) { if ('css' === ($a['kind'] ?? '')) { $css .= "\\n" . $a['content']; } } $blockCss = ''; foreach ($r['source_reports']['generated_blocks'] ?? array() as $g) { foreach ($g['assets'] ?? array() as $n => $c) { if (str_ends_with((string) $n, '.css')) { $blockCss .= "\\n" . $c; } } } echo json_encode(array('blocks' => $r['serialized_blocks'], 'css' => $css, 'blockCss' => $blockCss));`;

// A slider whose mask is narrower than the row: slides overflow it and show
// side by side until an ancestor clips them, and two absolutely positioned
// image arrows step through them. Each slide is an image card with an
// overlaid caption. Every slide but the first is aria-hidden, so the
// collection also looks like a one-at-a-time image slideshow.
const slide = (n, name) => `<div class="slide" role="group" aria-label="${n} of 6"${n > 1 ? ' aria-hidden="true"' : ''}><div class="card"><img src="https://example.test/${n}.jpg" alt="${name}"><div class="caption">${name}</div></div></div>`;
// A numbered dot pager beside the arrows selects slides; it must not hide
// the stepping pair (hidden on wide screens, as sliders often ship it).
const pagers = ['', `<div class="dots">${[1, 2, 3, 4, 5, 6].map(n => `<div class="dot" role="button" tabindex="0" aria-label="Show slide ${n} of 6"></div>`).join('')}</div>`];
const sourceWith = pager => `<!doctype html><html><head><style>
body{margin:0;font-family:sans-serif}
section{overflow:hidden;padding:40px 0}
.slider{position:relative;height:auto;background:transparent;text-align:left}
.slider-mask{position:relative;left:0;right:0;display:block;overflow:visible;white-space:nowrap;height:100%;max-width:496px}
.slide{position:relative;display:inline-block;vertical-align:top;width:100%;height:100%;white-space:normal;text-align:left;margin-right:4px}
.card{position:relative}
.card img{display:block;width:100%;height:515px;object-fit:cover}
.caption{position:absolute;right:10px;bottom:10px;background:#222;color:#fff;padding:4px 8px}
.arrow-prev,.arrow-next{position:absolute;top:0;bottom:0;margin:auto;width:56px;height:56px;cursor:pointer;z-index:3}
.arrow-prev{left:0}.arrow-next{right:0}
.arrow-prev img,.arrow-next img{width:28px;height:29px}
.dot{display:inline-block;width:8px;height:8px}@media (min-width:992px){.dots{display:none}}
</style></head><body><section><div class="slider" role="region" aria-label="carousel">
<div class="slider-mask">${['Pass one', 'Pass two', 'Pass three', 'Pass four', 'Pass five', 'Pass six'].map((name, index) => slide(index + 1, name)).join('')}</div>
<div class="arrow-prev" role="button" aria-label="previous slide" tabindex="0"><img src="https://example.test/arrow-left.svg" alt=""></div>
<div class="arrow-next" role="button" aria-label="next slide" tabindex="0"><img src="https://example.test/arrow-right.svg" alt=""></div>
${pager}</div></section></body></html>`;


const measure = page => page.evaluate(() => {
    // The painted part of a box: its rect clipped by every clipping ancestor and the viewport.
    const visible = element => {
        let {left, top, right, bottom} = element.getBoundingClientRect();
        for (let node = element.parentElement; node; node = node.parentElement) {
            if ('visible' === getComputedStyle(node).overflowX) continue;
            const clip = node.getBoundingClientRect();
            left = Math.max(left, clip.left); right = Math.min(right, clip.right);
            top = Math.max(top, clip.top); bottom = Math.min(bottom, clip.bottom);
        }
        left = Math.max(left, 0); right = Math.min(right, innerWidth);
        return right - left > 4 && bottom - top > 4;
    };
    const box = element => {
        const rect = element.getBoundingClientRect();
        return `${Math.round(rect.width)}x${Math.round(rect.height)}@${Math.round(rect.left)}`;
    };
    const images = [...document.images].filter(image => /^Pass /.test(image.alt));
    // The slide is the image's ancestor that sits directly in the track.
    const slideOf = image => {
        let node = image;
        while (node.parentElement && !node.parentElement.matches('.slider-mask, .blocks-engine-authored-carousel__track')) node = node.parentElement;
        return node.parentElement ? node : image;
    };
    return {
        slides: images.length,
        visibleSlides: images.filter(visible).length,
        firstSlide: box(slideOf(images[0])),
        firstImage: box(images[0]),
        captions: images.map(image => slideOf(image).textContent.trim()),
        arrows: [...document.images].filter(image => /arrow-(?:left|right)/.test(image.src)).map(image => {
            const rect = image.getBoundingClientRect();
            return `${Math.round(rect.left)},${Math.round(rect.top)} ${Math.round(rect.width)}x${Math.round(rect.height)}`;
        }),
    };
});

const browser = await chromium.launch({headless: true});
try {
    for (const pager of pagers) {
    const source = sourceWith(pager);
    const result = JSON.parse(execFileSync('php', ['-r', transform], {input: source, encoding: 'utf8'}));
    const page = await browser.newPage({viewport: {width: 1440, height: 900}});
    await page.route('https://example.test/**', route => route.fulfill({contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="800"/>'}));
    await page.setContent(source);
    const expected = await measure(page);
    // WordPress prints core and block styles before the theme's generated CSS.
    await page.setContent(`<style>body{margin:0}${blockLibrary}</style><style>${result.blockCss}</style><style>${result.css}</style>${result.blocks}`);
    const actual = await measure(page);
    console.log(JSON.stringify({pager: pager ? 'dots' : 'none', expected, actual}));
    assert.ok(result.blocks.includes('authored-carousel'), 'a slider with a stepping pair stays a carousel');
    assert.ok(!result.blocks.includes('<!-- wp:gallery'), 'it is not flattened into a core gallery');
    assert.equal(actual.slides, expected.slides, 'every slide is kept');
    assert.equal(actual.firstSlide, expected.firstSlide, 'the authored slide box wins over N-per-view packing');
    assert.equal(actual.firstImage, expected.firstImage, 'the slide image keeps its source box');
    assert.equal(actual.visibleSlides, expected.visibleSlides, 'the track overflows and clips where the source did');
    assert.deepEqual(actual.captions, expected.captions, 'each slide keeps its own caption');
    assert.equal(expected.arrows.length, 2);
    assert.deepEqual(actual.arrows, expected.arrows, 'both arrow images keep their source position and size');
    await page.close();
    }
} finally {
    await browser.close();
}
