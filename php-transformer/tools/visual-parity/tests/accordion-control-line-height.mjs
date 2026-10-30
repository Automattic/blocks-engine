import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = process.env.PLAYWRIGHT_MODULE ? await import(process.env.PLAYWRIGHT_MODULE) : require('playwright');
const root = new URL('../../..', import.meta.url).pathname;
assert.ok(process.env.WORDPRESS_PATH, 'WORDPRESS_PATH supplies real block-library CSS');
const nativeCss = readFileSync(process.env.WORDPRESS_PATH + '/wp-includes/css/dist/block-library/style.min.css', 'utf8');
const fixture = readFileSync(new URL('./fixtures/accordion-control-line-height.html', import.meta.url), 'utf8');
const browser = await chromium.launch({ headless:true });
try {
    for (const lineHeight of ['', '24px', 'inherit']) {
        const source = fixture.replace('.q{', '.q{' + (lineHeight ? 'line-height:' + lineHeight + ';' : ''));
        const result = JSON.parse(execFileSync('php', ['-r',
            'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN))->toArray());', root,
        ], { input:source, encoding:'utf8' }));
        const css = result.assets.filter(a => a.kind === 'css' && a.stylesheet_target !== 'editor').map(a => a.content).join('\n');
        const pages = await Promise.all([browser.newPage({ viewport:{ width:390, height:600 } }), browser.newPage({ viewport:{ width:390, height:600 } })]);
        await pages[0].setContent(source);
        await pages[1].setContent('<style>' + nativeCss + '\n' + css + '</style>' + result.serialized_blocks);
        const measure = (page, selector) => page.locator(selector).evaluateAll(elements => elements.map(e => ({ height:e.getBoundingClientRect().height, lineHeight:getComputedStyle(e).lineHeight })));
        const original = await measure(pages[0], '.q');
        const native = await measure(pages[1], '.wp-block-accordion-heading__toggle');
        console.log(JSON.stringify({ lineHeight:lineHeight || 'normal', original, native }));
        assert.equal(native.length, 2);
        assert.deepEqual(native, original, 'toggle used line-height and one-/two-line heights match source');
        await Promise.all(pages.map(p => p.close()));
    }
} finally { await browser.close(); }
