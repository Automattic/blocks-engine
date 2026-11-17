import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./native-button-control-font-fixture.php', import.meta.url))], {encoding: 'utf8'}));
assert.match(fixture.candidate, /wp:button/, 'native button stays editable');
const browser = await chromium.launch();
try {
    const page = await browser.newPage();
    const measure = element => {
        const style = getComputedStyle(element);
        const box = element.getBoundingClientRect();
        return {family: style.fontFamily, size: style.fontSize, width: box.width, height: box.height};
    };
    await page.setContent(fixture.source);
    const source = await page.locator('button').evaluate(measure);
    await page.setContent(`<style>:root :where(.wp-element-button,.wp-block-button__link){font-family:inherit;font-size:inherit}</style>${fixture.candidate}<style>${fixture.css}</style>`);
    const candidate = await page.locator('.wp-block-button__link').evaluate(measure);
    console.log(JSON.stringify({source, candidate}));
    assert.equal(candidate.family, source.family, 'native button keeps its UA control font instead of its parent font');
    assert.equal(candidate.size, source.size);
    assert.ok(Math.abs(candidate.width - source.width) < 1, 'button width matches source');
    assert.ok(Math.abs(candidate.height - source.height) < 1, 'button height matches source');
    await page.locator('.wp-block-button__link').evaluate(element => {
        element.style.fontFamily = 'Verdana';
        element.style.fontSize = '24px';
    });
    const edited = await page.locator('.wp-block-button__link').evaluate(measure);
    assert.equal(edited.family, 'Verdana', 'editor font family overrides the fallback');
    assert.equal(edited.size, '24px', 'editor font size overrides the fallback');
    for (const font of ['font:inherit', 'font:20px Georgia', 'font-family:Verdana;font-size:20px']) {
        const authored = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./native-button-control-font-fixture.php', import.meta.url)), font], {encoding: 'utf8'}));
        await page.setContent(authored.source);
        const expected = await page.locator('button').evaluate(measure);
        await page.setContent(`<style>:root :where(.wp-element-button,.wp-block-button__link){font-family:inherit;font-size:inherit}</style>${authored.candidate}<style>${authored.css}</style>`);
        const actual = await page.locator('.wp-block-button__link').evaluate(measure);
        assert.equal(actual.family, expected.family, `authored ${font} keeps its family`);
        assert.equal(actual.size, expected.size, `authored ${font} keeps its size`);
    }
} finally {
    await browser.close();
}
