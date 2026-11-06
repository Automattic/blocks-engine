// WP_ROOT=/path/to/wordpress node tests/unit/absorbed-button-geometry-browser.mjs
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./absorbed-button-geometry.php', import.meta.url)), '--fixture'], {encoding:'utf8'}));
const coreCss = readFileSync(`${process.env.WP_ROOT}/wp-includes/blocks/button/style.min.css`, 'utf8');
const browser = await chromium.launch({executablePath:process.env.CHROME_PATH || '/usr/bin/google-chrome', args:['--no-sandbox']});
try {
    const page = await browser.newPage({viewport:{width:1440,height:900}});
    await page.setContent(`<style>${coreCss}${fixture.css}</style>${fixture.markup}`);
    const link = page.locator('a.wp-block-button__link');
    assert.equal(await link.count(), 1);
    const box = await link.boundingBox();
    assert.ok(Math.abs(box.width - 159) <= 1, `Painted button width: ${box.width}`);
    assert.ok(Math.abs(box.height - 25) <= 1, `Painted button height: ${box.height}`);
    const offset = await link.evaluate(element => {
        const range = document.createRange();
        range.selectNodeContents(element);
        const label = range.getBoundingClientRect();
        const box = element.getBoundingClientRect();
        return {dx: label.left + label.right - box.left - box.right, dy: label.top + label.bottom - box.top - box.bottom};
    });
    assert.ok(Math.abs(offset.dx) <= 1, `Label horizontal offset: ${offset.dx}`);
    assert.ok(Math.abs(offset.dy) <= 1, `Label vertical offset: ${offset.dy}`);
    assert.equal(await link.textContent(), 'Our Cooperative');
    console.log('Absorbed button browser geometry passed');
} finally { await browser.close(); }
