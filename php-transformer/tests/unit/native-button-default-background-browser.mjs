import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./native-button-default-background-fixture.php', import.meta.url))], {encoding: 'utf8'}));
assert.match(fixture.candidate, /wp:button/, 'candidate retains canonical editable core/button markup');
const browser = await chromium.launch();
try {
	const defaultTheme = ':root :where(.wp-element-button,.wp-block-button__link){background-color:rgb(50,55,60);color:#fff}';
	for (const width of [390, 1440]) {
		const sourcePage = await browser.newPage({viewport: {width, height: 844}});
		await sourcePage.setContent(`<style>${fixture.sourceCss}</style>${fixture.source.replace(/<link[^>]+>/, '')}`);
		const source = await sourcePage.locator('a').evaluateAll(elements => elements.map(element => getComputedStyle(element).backgroundColor));
		const page = await browser.newPage({viewport: {width, height: 844}});
		const candidateMarkup = fixture.candidate;
		await page.setContent(`<style>${defaultTheme}</style>${candidateMarkup}`);
		assert.equal(await page.locator('.wp-block-button__link').first().evaluate(element => getComputedStyle(element).backgroundColor), 'rgb(50, 55, 60)', 'reproduces the WordPress theme.json fill before engine support CSS');
		await page.addStyleTag({content: fixture.css});
		const candidate = await page.locator('.wp-block-button__link').evaluateAll(elements => elements.map(element => getComputedStyle(element).backgroundColor));
		assert.equal(source[0], 'rgba(0, 0, 0, 0)');
		assert.equal(candidate[0], source[0], `brand stays source-transparent at ${width}px`);
		assert.equal(candidate[1], 'rgb(204, 0, 0)', 'authored inline fill remains owner-controlled');
		assert.equal(candidate[2], source[2], `responsive base fill remains source-owned at ${width}px`);
		await sourcePage.locator('a').last().hover();
		await page.locator('.wp-block-button__link').last().hover();
		const sourceHover = await sourcePage.locator('a').last().evaluate(element => getComputedStyle(element).backgroundColor);
		const candidateHover = await page.locator('.wp-block-button__link').last().evaluate(element => getComputedStyle(element).backgroundColor);
		assert.equal(candidateHover, sourceHover, `responsive hover fill remains source-owned at ${width}px`);
		await sourcePage.close();
		await page.close();
	}
	console.log('Native button default background browser regression passed');
} finally {
	await browser.close();
}
