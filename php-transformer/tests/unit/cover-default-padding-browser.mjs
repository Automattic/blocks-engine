import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress wp-includes/css/dist/block-library/style.min.css');
const blockLibrary = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const transform = `require 'vendor/autoload.php'; $r = (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN), array())->toArray(); $css = ''; foreach ($r['assets'] ?? array() as $a) { if ('css' === ($a['kind'] ?? '')) { $css .= "\\n" . $a['content']; } } echo json_encode(array('blocks' => $r['serialized_blocks'], 'css' => $css));`;
const browser = await chromium.launch({headless: true});
try {
    // A stylesheet-painted hero keeps exactly the padding its author declared.
    for (const padding of ['padding-top:0;padding-bottom:150px', 'padding:40px 60px']) {
        const source = `<!doctype html><html><head><style>body{margin:0}.contact{min-height:600px;background-image:url(https://example.test/hero.jpg);background-size:cover;${padding}}.box{width:100%;height:200px}</style></head><body><section class="contact"><div class="box"><h2>Get in touch</h2><p>Write to us any time.</p></div></section></body></html>`;
        const result = JSON.parse(execFileSync('php', ['-r', transform], {input: source, encoding: 'utf8'}));
        assert.ok(result.blocks.includes('<!-- wp:cover'), 'the hero becomes a cover');
        const page = await browser.newPage({viewport: {width: 1440, height: 900}});
        await page.route('https://example.test/**', route => route.fulfill({contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>'}));
        const measure = () => page.evaluate(() => {
            const box = document.querySelector('.box').getBoundingClientRect();
            const style = getComputedStyle(document.querySelector('.contact'));
            return {padding: [style.paddingTop, style.paddingRight, style.paddingBottom, style.paddingLeft].join(' '), left: box.left, width: box.width};
        });
        await page.setContent(source);
        const expected = await measure();
        // WordPress prints block styles before the theme's generated CSS.
        await page.setContent(`<style>body{margin:0}${blockLibrary}</style><style>${result.css}</style>${result.blocks}`);
        const actual = await measure();
        console.log(JSON.stringify({padding, expected, actual}));
        assert.deepEqual(actual, expected, 'the cover keeps the source padding and content box');
        await page.close();
    }
} finally {
    await browser.close();
}
