import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = process.env.PLAYWRIGHT_MODULE ? await import(process.env.PLAYWRIGHT_MODULE) : require('playwright');
const root = new URL('../../..', import.meta.url).pathname;
const conditionalLayout = process.argv.includes('--conditional-layout');
const defaultLandmark = process.argv.includes('--default-landmark');
const crossFamilyCascade = process.argv.includes('--cross-family-cascade');
const crossFamilyOrder = process.argv.includes('--cross-family-order');
const variants = ['--conditional-layout', '--default-landmark', '--cross-family-cascade', '--cross-family-order'].filter(flag => process.argv.includes(flag));
const { source, result } = JSON.parse(execFileSync('php', [root + '/tests/unit/navigation-source-correspondence.php', '--fixture', ...variants], { encoding: 'utf8' }));
assert.ok(process.env.WORDPRESS_PATH, 'WORDPRESS_PATH supplies an actual native WordPress render for this regression');
const wpPath = process.env.WORDPRESS_PATH;
const runtime = JSON.parse(execFileSync(process.env.WP_CLI ?? 'wp', [
    '--path=' + wpPath, '--skip-plugins', '--skip-themes', 'eval',
    '$markup=base64_decode("' + Buffer.from(result.serialized_blocks).toString('base64') + '");'
    + '$names=[];$walk=function($blocks)use(&$walk,&$names){foreach($blocks as $block){if($block["blockName"])$names[]=$block["blockName"]; $walk($block["innerBlocks"]);}};'
    + '$saved=serialize_blocks(parse_blocks($markup));$walk(parse_blocks($saved));'
    + 'echo json_encode(["version"=>get_bloginfo("version"),"html"=>do_blocks($saved),"names"=>$names,"unregistered"=>array_values(array_filter($names,fn($name)=>!WP_Block_Type_Registry::get_instance()->is_registered($name))),"stable"=>$saved===serialize_blocks(parse_blocks($saved))]);',
], { encoding: 'utf8' }));
assert.deepEqual(runtime.unregistered, [], 'every saved fixture block is registered in the actual WordPress runtime');
assert.equal(runtime.stable, true, 'native parse/serialize/reparse preserves the saved structure');
assert.equal(runtime.names.filter(name => name === 'core/navigation-link').length, 3, 'three editable links survive native serialization');
const rendered = runtime.html;
const nativeCss = ['navigation', 'group'].map(name => readFileSync(wpPath + '/wp-includes/blocks/' + name + '/style.min.css', 'utf8')).join('\n');
const css = result.assets.filter(a => a.kind === 'css').map(a => a.content).join('\n');
const browser = await chromium.launch({ headless: true });
const evidence = [];
try {
    for (const width of [390, 768, 1000, 1440]) {
        const pages = await Promise.all([browser.newPage({ viewport: { width, height: 400 } }), browser.newPage({ viewport: { width, height: 400 } })]);
        await pages[0].setContent(source);
        await pages[1].setContent('<style>' + nativeCss + '\n' + css + '</style>' + rendered);
        const measure = page => page.evaluate(() => {
            const box = e => { const r = e.getBoundingClientRect(); return { x:r.x, y:r.y, width:r.width, height:r.height }; };
            const landmark = document.querySelector('nav.landmark');
            const anchors = [...document.querySelectorAll('.menu-links a')];
            return { landmark: box(landmark), landmarkDisplay:getComputedStyle(landmark).display, band: box(document.querySelector('.band')), following: box(document.querySelector('.following')), anchors: anchors.map(e => ({ box:box(e), font:getComputedStyle(e).fontSize, line:getComputedStyle(e).lineHeight, padding:getComputedStyle(e).padding, display:getComputedStyle(e).display })) };
        });
        const [original, native] = await Promise.all(pages.map(measure));
        evidence.push({ width, original, native });
        assert.deepEqual(native, original, `native landmark/item/anchor boxes and responsive type match at ${width}px`);
        assert.equal(native.anchors[0].font, crossFamilyCascade || crossFamilyOrder ? '20px' : width <= 700 || width >= 1200 ? '14px' : '12.32px', 'authored cross-family or breakpoint winner remains authoritative');
        await Promise.all(pages.map(page => page.close()));
    }
} finally { await browser.close(); }
console.log(JSON.stringify({ runtime:'actual WordPress parse/serialize/do_blocks', conditionalLayout, defaultLandmark, crossFamilyCascade, crossFamilyOrder, version:runtime.version, registeredBlocks:runtime.names.length, evidence }, null, 2));
console.log('Navigation source correspondence native-browser regression passed');
