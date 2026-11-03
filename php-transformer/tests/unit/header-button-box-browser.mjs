// WP_ROOT=/path/to/wordpress node tests/unit/header-button-box-browser.mjs
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./header-button-box-fixture.php', import.meta.url))], {encoding:'utf8'}));
const rendered = JSON.parse(execFileSync('php', ['-r', `
require getenv('WP_ROOT') . '/wp-load.php';
$f = json_decode(stream_get_contents(STDIN), true);
echo json_encode(array('html'=>do_blocks($f['markup']), 'css'=>file_get_contents(ABSPATH.WPINC.'/blocks/navigation/style.min.css').file_get_contents(ABSPATH.WPINC.'/blocks/button/style.min.css')));
`], {input:JSON.stringify(fixture), encoding:'utf8'}));
const browser = await chromium.launch({executablePath:process.env.CHROME_PATH || '/usr/bin/google-chrome', args:['--no-sandbox']});
try {
    const page = await browser.newPage({viewport:{width:390,height:844}});
    const results = {};
    for (const [name, html] of Object.entries({source:fixture.source, candidate:`<style>${rendered.css}${fixture.css}</style>${rendered.html}`})) {
        await page.setContent(html);
        const buttons = await page.locator('header button').evaluateAll(elements=>elements.filter(e=>e.getBoundingClientRect().width).map(e=>{
            const s=getComputedStyle(e), r=e.getBoundingClientRect();
            return {width:r.width,height:r.height,x:r.x,y:r.y,padding:s.padding,background:s.backgroundColor,radius:s.borderRadius};
        }));
        const logoX = await page.locator('header a.logo').evaluate(e=>e.getBoundingClientRect().x);
        results[name] = {buttons, logoX};
    }
    assert.equal(results.source.logoX, 87, 'toggle author padding positions the logo');
    assert.equal(results.source.buttons[0].width, 47);
    assert.equal(results.source.buttons[0].height, 43);
    console.log(JSON.stringify(results,null,2));
    assert.deepEqual(results.candidate,results.source);
} finally { await browser.close(); }
