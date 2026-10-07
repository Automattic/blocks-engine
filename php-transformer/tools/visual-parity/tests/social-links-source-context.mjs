import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = process.env.PLAYWRIGHT_MODULE ? await import(process.env.PLAYWRIGHT_MODULE) : require('playwright');
const root = new URL('../../..', import.meta.url).pathname;
const fontPaint = process.argv.includes('--font-paint');
const compiler = process.argv.includes('--compiled');
const { source, result, compiled } = JSON.parse(execFileSync('php', [root + '/tests/unit/social-links-source-context.php', '--fixture', ...(fontPaint ? ['--font-paint'] : [])], { encoding: 'utf8' }));
const markup = compiler ? compiled.pages[0].block_markup : result.serialized_blocks;
assert.equal(result.metrics.fallback_count, 0);
assert.ok(process.env.WORDPRESS_PATH, 'WORDPRESS_PATH supplies actual WordPress block rendering');
const wpPath = process.env.WORDPRESS_PATH;
const runtime = JSON.parse(execFileSync(process.env.WP_CLI ?? 'wp', [
    '--path=' + wpPath, '--skip-plugins', '--skip-themes', 'eval',
    '$markup=base64_decode("' + Buffer.from(markup).toString('base64') + '");'
    + '$saved=serialize_blocks(parse_blocks($markup));$names=[];$walk=function($blocks)use(&$walk,&$names){foreach($blocks as $b){if($b["blockName"])$names[]=$b["blockName"]; $walk($b["innerBlocks"]);}};$walk(parse_blocks($saved));'
    + 'wp_enqueue_script("wp-block-library");ob_start();wp_scripts()->do_items();$scripts=ob_get_clean();'
    + 'echo json_encode(["scripts"=>$scripts,"html"=>do_blocks($saved),"names"=>$names,"version"=>get_bloginfo("version"),"stable"=>$saved===serialize_blocks(parse_blocks($saved)),"unregistered"=>array_values(array_filter($names,fn($n)=>!WP_Block_Type_Registry::get_instance()->is_registered($n)))]);',
], { encoding: 'utf8' }));
assert.deepEqual(runtime.unregistered, []);
assert.equal(runtime.stable, true);
assert.equal(runtime.names.filter(n => n === 'core/heading').length, 2);
assert.equal(runtime.names.filter(n => n === 'core/separator').length, 2);
assert.equal(runtime.names.filter(n => n === 'core/social-link').length, 3);
const nativeCss = readFileSync(wpPath + '/wp-includes/css/dist/block-library/common.min.css', 'utf8') + '\n' + ['social-links', 'group', 'heading', 'separator'].map(n => readFileSync(wpPath + '/wp-includes/blocks/' + n + '/style.min.css', 'utf8')).join('\n');
const css = (compiler ? compiled.assets : result.assets).filter(a => a.kind === 'css').map(a => a.content).join('\n');
const browser = await chromium.launch({ headless: true });
const evidence = [];
let editorValidation;
try {
    const editor = await browser.newPage();
    await editor.goto(process.env.WORDPRESS_URL ?? 'http://localhost:8898', { waitUntil:'domcontentloaded' });
    await editor.setContent(runtime.scripts, { waitUntil:'load' });
    await editor.waitForFunction(() => window.wp?.blockLibrary?.registerCoreBlocks);
    editorValidation = await editor.evaluate(markup => {
        wp.blockLibrary.registerCoreBlocks();
        const walk = blocks => blocks.flatMap(b => [b, ...walk(b.innerBlocks)]);
        const parsed = wp.blocks.parse(markup);
        const before = walk(parsed).map(b => ({ name:b.name, valid:wp.blocks.validateBlock(b)[0] }));
        walk(parsed).find(b => b.name === 'core/heading').attributes.content = 'Connect with everyone';
        walk(parsed).find(b => b.name === 'core/social-link').attributes.url = 'https://facebook.com/edited';
        const saved = wp.blocks.serialize(parsed);
        const reloaded = wp.blocks.parse(saved);
        return { before, after:walk(reloaded).map(b => ({ name:b.name, valid:wp.blocks.validateBlock(b)[0] })), stable:wp.blocks.serialize(reloaded) === saved, heading:walk(reloaded).find(b => b.name === 'core/heading').attributes.content, url:walk(reloaded).find(b => b.name === 'core/social-link').attributes.url };
    }, markup);
    assert.ok(editorValidation.before.every(b => b.valid), 'actual Gutenberg validates every initial native block');
    assert.ok(editorValidation.after.every(b => b.valid), 'actual Gutenberg validates every edited and saved native block');
    assert.equal(editorValidation.stable, true);
    assert.equal(editorValidation.heading, 'Connect with everyone');
    assert.equal(editorValidation.url, 'https://facebook.com/edited');
    await editor.close();
    for (const width of [390, 768, 1440]) {
        const pages = await Promise.all([browser.newPage({ viewport:{ width, height:600 } }), browser.newPage({ viewport:{ width, height:600 } })]);
        await pages[0].setContent(source);
        await pages[1].setContent('<style>' + nativeCss + '\n' + css + '</style>' + runtime.html);
        const measure = page => page.evaluate(() => {
            const box = e => { const r = e.getBoundingClientRect(); return { x:r.x, y:r.y, width:r.width, height:r.height }; };
            return Object.fromEntries(['.social-region', '.frame', '.responsive-region', 'h2', '.icon-row', '.following'].map(s => [s, box(document.querySelector(s))]).concat([['anchors', [...document.querySelectorAll('.icon-row a')].map(box)]]));
        });
        const [original, native] = await Promise.all(pages.map(measure));
        if (fontPaint) {
            const paintedGlyph = page => page.evaluate(() => {
                const anchor = document.querySelector('.icon-row a');
                const glyph = anchor.querySelector('span:not(.screen-reader-text)') ?? anchor;
                const style = getComputedStyle(glyph, '::before');
                return Object.fromEntries(['content', 'font-size', 'font-family', 'line-height', 'display', 'color'].map(p => [p, style.getPropertyValue(p)]));
            });
            [original.glyph, native.glyph] = await Promise.all(pages.map(paintedGlyph));
            assert.notEqual(native.glyph.content, 'none', 'the native link still paints its authored glyph');
        }
        evidence.push({ width, original, native });
        assert.deepEqual(native, original, `native mixed social content/row/anchor geometry matches at ${width}px: ${JSON.stringify(evidence)}`);
        await Promise.all(pages.map(p => p.close()));
    }
} finally { await browser.close(); }
console.log(JSON.stringify({ runtime:'actual WordPress parse/serialize/do_blocks and Gutenberg parse/edit/serialize/validate', fontPaint, compiler, version:runtime.version, registeredBlocks:runtime.names.length, fallbackCount:result.metrics.fallback_count, editorValidation, evidence }, null, 2));
console.log('Social-links source context browser regression passed');
