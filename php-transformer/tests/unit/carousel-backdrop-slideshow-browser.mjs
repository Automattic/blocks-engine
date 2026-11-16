import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress wp-includes/css/dist/block-library/style.min.css');
const blockLibrary = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
// Author CSS assets plus the generated blocks' own style.css, as WordPress enqueues both.
const transform = `require 'vendor/autoload.php'; $r = (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN), array())->toArray(); $css = ''; foreach ($r['assets'] ?? array() as $a) { if ('css' === ($a['kind'] ?? '')) { $css .= "\\n" . $a['content']; } } $blockCss = ''; foreach ($r['source_reports']['generated_blocks'] ?? array() as $g) { foreach ($g['assets'] ?? array() as $n => $c) { if (str_ends_with((string) $n, '.css')) { $blockCss .= "\\n" . $c; } } } echo json_encode(array('blocks' => $r['serialized_blocks'], 'css' => $css, 'blockCss' => $blockCss));`;

// A hero whose photo slideshow is an absolutely positioned backdrop behind
// in-flow content. The slider root paints a dark fill until a photo loads,
// and the fade runtime stacks every slide at the origin by translating each
// one back by its own offset (left as captured state on the slides). A generic
// slider default height is raised to the full hero by the backdrop's min-height.
const slides = [1, 2, 3, 4].map(n => `<div class="slide slide-${n}" role="group" aria-label="${n} of 4"${n > 1 ? ' aria-hidden="true"' : ''} style="transform:translateX(-${(n - 1) * 100}vw)${n > 1 ? ";visibility:hidden" : ""}"></div>`).join('');
const source = `<!doctype html><html><head><style>
body{margin:0;font-family:sans-serif}
.hero{position:relative;display:flex;flex-direction:column;justify-content:flex-end;height:100vh;overflow:hidden;background:#141414}
.hero-content{position:relative;z-index:2;display:flex;flex-direction:column;justify-content:flex-end;height:100vh;padding:80px 50px;box-sizing:border-box;color:#fff}
.hero-content h1{margin:0;font-size:48px}
.slider{height:300px}
.backdrop{position:absolute;inset:0;z-index:1;display:block;min-height:100vh;background-color:#141414;text-align:center}
.mask{position:relative;height:100%;overflow:hidden;white-space:nowrap}
.slide{position:relative;display:inline-block;vertical-align:top;width:100%;height:100%;white-space:normal;background-size:cover;background-position:50%}
${[1, 2, 3, 4].map(n => `.slide-${n}{background-image:url(https://example.test/${n}.jpg)}`).join('')}
.status{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
.prev,.next{position:absolute;top:0;bottom:0;width:80px;margin:auto;height:48px;color:#fff;cursor:pointer;z-index:3}
.prev{left:0}.next{right:0}
</style></head><body><section class="hero">
<div class="hero-content"><h1>Ride the mountain passes</h1><p>Guided tours from the valley.</p></div>
<div class="backdrop slider" role="region" aria-label="carousel"><div class="mask" id="hero-slides">${slides}<div class="status" aria-live="off" aria-atomic="true">Slide 1 of 4.</div></div>
<div class="prev" role="button" tabindex="0" aria-controls="hero-slides" aria-label="previous slide">‹</div>
<div class="next" role="button" tabindex="0" aria-controls="hero-slides" aria-label="next slide">›</div></div>
</section></body></html>`;

// One flat colour per slide photo, so a sampled pixel names the slide on screen.
const colors = {1: '#3a7', 2: '#a37', 3: '#37a', 4: '#aa3'};
const slideAt = async (page, x, y) => {
    const png = (await page.screenshot({clip: {x, y, width: 1, height: 1}})).toString('base64');
    const [r, g, b] = await page.evaluate(async data => {
        const image = new Image();
        image.src = `data:image/png;base64,${data}`;
        await image.decode();
        const context = Object.assign(document.createElement('canvas'), {width: 1, height: 1}).getContext('2d');
        context.drawImage(image, 0, 0);
        return [...context.getImageData(0, 0, 1, 1).data];
    }, png);
    const hex = `#${[r, g, b].map(value => Math.round(value / 17).toString(16)).join('')}`;
    return Object.keys(colors).find(n => colors[n] === hex) || hex;
};
const measure = async page => {
    const heading = await page.evaluate(() => {
        const rect = document.querySelector('h1').getBoundingClientRect();
        return `${Math.round(rect.width)}x${Math.round(rect.height)}@${Math.round(rect.left)},${Math.round(rect.top)}`;
    });
    // Corners and the empty top of the hero show the backdrop photo.
    const {width, height} = page.viewportSize();
    const photo = [await slideAt(page, width / 2, 30), await slideAt(page, 10, height / 3), await slideAt(page, width - 10, height - 10)];
    return {heading, photo};
};

const browser = await chromium.launch({headless: true});
try {
    const result = JSON.parse(execFileSync('php', ['-r', transform], {input: source, encoding: 'utf8'}));
    // Wide and narrow: a photo left at its own aspect ratio only matches the
    // hero by coincidence at one width.
    for (const viewport of [{width: 1440, height: 900}, {width: 390, height: 844}]) {
        const page = await browser.newPage({viewport});
        await page.route('https://example.test/**', route => route.fulfill({contentType: 'image/svg+xml', body: `<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="1000"><rect width="1600" height="1000" fill="${colors[route.request().url().match(/(\d)\.jpg/)[1]]}"/></svg>`}));
        await page.setContent(source);
        const expected = await measure(page);
        // WordPress prints core and block styles before the theme's generated CSS, in standards mode.
        await page.setContent(`<!doctype html><style>body{margin:0}${blockLibrary}</style><style>${result.blockCss}</style><style>${result.css}</style>${result.blocks}`);
        const actual = await measure(page);
        console.log(JSON.stringify({viewport, expected, actual}));
        assert.ok(result.blocks.includes('authored-carousel'), 'the backdrop slider stays a carousel');
        assert.deepEqual(expected.photo, ['1', '1', '1']);
        assert.equal(actual.heading, expected.heading, 'the hero content keeps its source position beside an out-of-flow backdrop');
        assert.deepEqual(actual.photo, expected.photo, 'the first slide photo fills the hero behind its content');
        // Step to the second slide the way the view script does, by moving the active class.
        await page.evaluate(() => {
            const slides = [...document.querySelector('.blocks-engine-authored-carousel__track').children];
            slides.forEach((slide, index) => slide.classList.toggle('blocks-engine-authored-carousel__slide--active', 1 === index));
        });
        await page.waitForTimeout(1200);
        assert.deepEqual((await measure(page)).photo, ['2', '2', '2'], 'the next slide photo fills the hero in its place');
        await page.close();
    }
} finally {
    await browser.close();
}
