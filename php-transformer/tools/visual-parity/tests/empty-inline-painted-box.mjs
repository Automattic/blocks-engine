import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = process.env.PLAYWRIGHT_MODULE ? await import(process.env.PLAYWRIGHT_MODULE) : require('playwright');
const root = new URL('../../..', import.meta.url).pathname;
const source = readFileSync(new URL('./fixtures/empty-inline-painted-box.html', import.meta.url), 'utf8');
const result = JSON.parse(execFileSync('php', ['-r',
    'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN))->toArray());', root,
], { input: source, encoding: 'utf8' }));
assert.equal(result.metrics.fallback_count, 0);
assert.ok(process.env.WORDPRESS_PATH, 'WORDPRESS_PATH supplies real block-library CSS');
const nativeCss = readFileSync(process.env.WORDPRESS_PATH + '/wp-includes/css/dist/block-library/style.min.css', 'utf8');
const css = result.assets.filter(a => a.kind === 'css' && a.stylesheet_target !== 'editor').map(a => a.content).join('\n');
const browser = await chromium.launch({ headless: true });
try {
    const evidence = [];
    for (const html of [source, '<style>' + nativeCss + '\n' + css + '</style>' + result.serialized_blocks]) {
        const page = await browser.newPage({ viewport: { width: 390, height: 600 } });
        await page.setContent(html);
        evidence.push(await page.evaluate(() => {
            const dot = document.querySelector('.dot');
            const badge = dot.parentElement;
            const box = e => { const r = e.getBoundingClientRect(); return { width: r.width, height: r.height }; };
            const style = getComputedStyle(dot);
            return { badge: box(badge), dot: box(dot), background: style.backgroundColor, radius: style.borderRadius, shadow: style.boxShadow };
        }));
        await page.close();
    }
    console.log(JSON.stringify({ source: evidence[0], compiled: evidence[1] }, null, 2));
    assert.deepEqual(evidence[0].dot, { width: 8, height: 8 });
    assert.equal(evidence[0].background, 'rgb(226, 98, 42)');
    assert.deepEqual(evidence[1], evidence[0], 'nested empty RichText carrier preserves source geometry and paint');
} finally {
    await browser.close();
}
