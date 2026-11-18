import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress wp-includes/css/dist/block-library/style.min.css');
const blockLibrary = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const transform = `require 'vendor/autoload.php'; $r = (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN), array())->toArray(); $css = ''; foreach ($r['assets'] ?? array() as $a) { if ('css' === ($a['kind'] ?? '')) { $css .= "\\n" . $a['content']; } } echo json_encode(array('blocks' => $r['serialized_blocks'], 'css' => $css));`;

// A photo panel filling a tall frame, tinted by a gradient layer above it:
// each layer has its own size and position, and only the photo layer is
// \`cover\` from its top-left corner.
const source = `<!doctype html><html><head><style>
body{margin:0}
.frame{height:400px}
.panel{width:100%;height:100%;background-image:linear-gradient(#ffffff12,#ffffff12),url(https://example.test/photo.jpg);background-size:auto,cover;background-position:50% 50%,0 0}
</style></head><body><div class="frame"><div class="panel"></div></div><p>Below the panel.</p></body></html>`;
// Left 320/1600 red, the rest blue. Covering the 390x400 panel scales it to
// 640x400 from the left edge, so red reaches x=128. Left at its own aspect it
// stops short of the panel's bottom; stretched, or centred by the tint layer's
// position, red narrows or moves off the left edge.
const photo = '<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="1000"><rect width="320" height="1000" fill="#c33"/><rect x="320" width="1280" height="1000" fill="#33c"/></svg>';
const points = [[20, 50], [100, 100], [100, 380], [160, 380], [370, 380]];

const pixels = async page => {
    const png = (await page.screenshot({clip: {x: 0, y: 0, width: 390, height: 400}})).toString('base64');
    return page.evaluate(async ({data, points}) => {
        const image = new Image();
        image.src = `data:image/png;base64,${data}`;
        await image.decode();
        const context = Object.assign(document.createElement('canvas'), {width: 390, height: 400}).getContext('2d');
        context.drawImage(image, 0, 0);
        return points.map(([x, y]) => {
            const [r, , b] = context.getImageData(x, y, 1, 1).data;
            return r > b + 60 ? 'red' : b > r + 60 ? 'blue' : `${r},${b}`;
        });
    }, {data: png, points});
};

const browser = await chromium.launch({headless: true});
try {
    const result = JSON.parse(execFileSync('php', ['-r', transform], {input: source, encoding: 'utf8'}));
    const page = await browser.newPage({viewport: {width: 390, height: 900}});
    await page.route('https://example.test/**', route => route.fulfill({contentType: 'image/svg+xml', body: photo}));
    await page.setContent(source);
    await page.waitForTimeout(200);
    const expected = await pixels(page);
    await page.setContent(`<!doctype html><style>body{margin:0}${blockLibrary}</style><style>${result.css}</style>${result.blocks}`);
    await page.waitForTimeout(200);
    const actual = await pixels(page);
    console.log(JSON.stringify({expected, actual}));
    assert.deepEqual(expected, ['red', 'red', 'red', 'blue', 'blue']);
    assert.deepEqual(actual, expected, 'the photo layer still covers the panel under its tint');
    await page.close();
} finally {
    await browser.close();
}
