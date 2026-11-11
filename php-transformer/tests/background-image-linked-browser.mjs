import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from '../tools/visual-parity/node_modules/playwright/index.mjs';

const root = new URL('..', import.meta.url).pathname;
const browser = await chromium.launch();
try {
    const page = await browser.newPage();
    for (const display of ['', 'flex']) {
        for (const scale of ['cover', 'contain']) {
            for (const linked of [true, false]) {
                const tile = '<div class="lazy" style="background-image:url(x.jpg)"></div>';
                const source = `<!doctype html><html><head><style>article{width:288px}.lazy{${display ? `display:${display};` : ''}height:208px;background-size:${scale};background-position:50% 4%}</style></head><body><main><article>${linked ? `<a href="/target">${tile}</a>` : tile}<h2>Title</h2></article></main></body></html>`;
                const result = JSON.parse(execFileSync('php', ['-r', `require $argv[1].'/vendor/autoload.php'; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(['files'=>[['path'=>'index.html','kind'=>'html','content'=>stream_get_contents(STDIN)]]])->toArray());`, root], { input: source, encoding: 'utf8' }));
                const css = result.assets.filter(asset => asset.path.endsWith('.css')).map(asset => asset.content).join('\n');
                // Core's image rules, including the auto-height inline-block link.
                await page.setContent(`<!doctype html><style>.wp-block-image>a,.wp-block-image>figure>a{display:inline-block}.wp-block-image img{height:auto;max-width:100%;vertical-align:bottom}${css}</style>${result.serialized_blocks}`);
                const actual = await page.evaluate(async () => {
                    const host = document.querySelector('.lazy');
                    const img = host.querySelector('img');
                    img.width = 400;
                    img.height = 100;
                    img.src = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="400" height="100"/>');
                    await img.decode();
                    const box = host.getBoundingClientRect();
                    const image = img.getBoundingClientRect();
                    return { box: [box.width, box.height], image: [image.width, image.height], scale: getComputedStyle(img).objectFit, inline: getComputedStyle(img.closest('figure')).display === 'contents', linked: !!img.closest('a[href="/target"]') };
                });
                const label = `${display}/${scale}/${linked}`;
                assert.deepEqual(actual.box, [288, 208], label);
                assert.deepEqual(actual.image, actual.box, label);
                assert.equal(actual.scale, scale, label);
                assert.equal(actual.linked, linked, label);
                assert.equal(actual.inline, display === '' && linked, label);
            }
        }
    }
    console.log('Linked background image browser contract passed.');
} finally {
    await browser.close();
}
