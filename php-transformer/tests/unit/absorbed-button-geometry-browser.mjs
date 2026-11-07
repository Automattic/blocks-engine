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
    // Compare both bordered pills and following flow content with the source.
    const positioned = fixture.positioned;
    await page.setContent(positioned.source);
    const sourceBoxes = await page.locator('.common-button').evaluateAll(elements => elements.map(element => {
        const {x, y, width, height} = element.getBoundingClientRect();
        return {x, y, width, height};
    }));
    const sourceParagraph = await page.locator('p').boundingBox();
    await page.setContent(`<style>${coreCss}${positioned.css}</style>${positioned.markup}`);
    const links = page.locator('a.wp-block-button__link');
    assert.equal(await links.count(), 2);
    for (let index = 0; index < sourceBoxes.length; index++) {
        const actual = await links.nth(index).boundingBox();
        for (const axis of ['x', 'y', 'width', 'height']) {
            assert.ok(Math.abs(actual[axis] - sourceBoxes[index][axis]) <= 1,
                `Pill ${index} ${axis}: ${actual[axis]} vs source ${sourceBoxes[index][axis]}`);
        }
    }
    const paragraph = await page.locator('p').boundingBox();
    assert.ok(Math.abs(paragraph.y - sourceParagraph.y) <= 1,
        `Following paragraph: ${paragraph.y} vs source ${sourceParagraph.y}`);
    console.log('Absorbed button browser geometry passed');
} finally { await browser.close(); }
