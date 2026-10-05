import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./native-button-default-background-fixture.php', import.meta.url))], {encoding: 'utf8'}));
assert.match(fixture.candidate, /wp:button/, 'candidate retains canonical editable core/button markup');
const browser = await chromium.launch();
try {
	const page = await browser.newPage();
	const defaultTheme = '.wp-block-button__link{background-color:rgb(50,55,60);color:#fff}';
	await page.setContent(`<style>${defaultTheme}</style>${fixture.source}`);
	const source = await page.locator('a').evaluate(element => getComputedStyle(element).backgroundColor);
	await page.setContent(`<style>${defaultTheme}${fixture.css}</style>${fixture.candidate}`);
	const link = page.locator('.wp-block-button__link');
	const candidate = await link.evaluate(element => ({
		background: getComputedStyle(element).backgroundColor,
		color: getComputedStyle(element).color,
	}));
	assert.equal(source, 'rgba(0, 0, 0, 0)');
	assert.equal(candidate.background, source, 'native theme fill must not paint the source-transparent promoted anchor');
	assert.equal(candidate.color, 'rgb(248, 255, 249)', 'inherited source foreground remains intact');
	console.log('Native button default background browser regression passed');
} finally {
	await browser.close();
}
