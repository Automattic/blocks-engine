import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { chromium } from '../../tools/visual-parity/node_modules/playwright/index.mjs';

const root = new URL('../..', import.meta.url).pathname;
const php = (code, input = '') => execFileSync('php', ['-r', `require $argv[1].'/vendor/autoload.php';${code}`, root], { input, encoding: 'utf8' });
const properties = JSON.parse(php('echo json_encode(Automattic\\BlocksEngine\\PhpTransformer\\Contract\\BoxTree::PROPERTIES);'));
const source = '<!doctype html><html data-blocks-engine-node-id="root"><head data-blocks-engine-node-id="head"><style>body{margin:0;font:16px/24px Arial}p{margin:0}.cta{display:inline-flex;align-items:center;gap:6px;margin-top:16px;font-size:14px;line-height:20px}</style></head><body data-blocks-engine-node-id="body"><main data-blocks-engine-node-id="main"><div data-blocks-engine-node-id="card"><p data-blocks-engine-node-id="copy">Service description</p><span class="cta" data-blocks-engine-node-id="cta">Book this service<span data-blocks-engine-node-id="arrow">→</span></span></div><div id="following" data-blocks-engine-node-id="following">Following image</div></main></body></html>';
const browser = await chromium.launch();
try {
    const page = await browser.newPage({ viewport: { width: 390, height: 900 } });
    for (const html of [source, source.replace('Book this service<span', '<span style="color:red">Book</span> this service<span').replace('display:inline-flex;', 'display:inline;')]) {
    await page.setContent(html);
    const before = await page.locator('#following').boundingBox();
    const nodes = await page.evaluate(properties => Object.fromEntries([...document.querySelectorAll('[data-blocks-engine-node-id]')].map(element => {
        const box = element.getBoundingClientRect();
        const style = getComputedStyle(element);
        return [element.dataset.blocksEngineNodeId, {
            children: [...element.children].map(child => child.dataset.blocksEngineNodeId).filter(Boolean),
            viewports: { mobile: {
                border_box: { x: box.x, y: box.y, width: box.width, height: box.height },
                used_styles: Object.fromEntries(properties.map(property => [property, style.getPropertyValue(property)])),
            } },
        }];
    })), properties);
    const tree = { schema: 'blocks-engine/box-tree/v2', source_path: 'index.html', source_hash: createHash('sha256').update(html).digest('hex'), viewports: { mobile: { width: 390, height: 900 } }, nodes };
    const result = JSON.parse(php('$a=json_decode(stream_get_contents(STDIN),true);echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile($a)->toArray());', JSON.stringify({ files: { 'index.html': html, 'box-tree/index.json': JSON.stringify(tree) } })));
    const css = result.assets.filter(asset => asset.path.endsWith('.css')).map(asset => asset.content).join('\n');
    await page.setContent(`<style>${css}</style>${result.serialized_blocks}`);
    const after = await page.locator('#following').boundingBox();
    // The synthetic inline RichText root can change the baseline strut by
    // two pixels; it must not add the source's 16px margin a second time.
    if (html === source) {
        assert.ok(Math.abs(before.y - after.y) < 3, `RichText source box must not be duplicated: ${before.y} != ${after.y}`);
    } else {
        // A paragraph starting with a child format must keep ownership of
        // its own geometry, not apply that geometry to every child mark.
        assert.ok(!/\.be-inline-geometry-[\w-]+ > mark\{/.test(css), 'Child marks must not receive the source paragraph box');
    }
    }
    console.log('Captured inline-flex and partial-mark RichText geometry passed');
} finally {
    await browser.close();
}
