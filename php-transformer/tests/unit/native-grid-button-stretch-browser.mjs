import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./native-grid-button-stretch-fixture.php', import.meta.url))], {encoding: 'utf8'}));
const browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_PATH});
try {
	const page = await browser.newPage({viewport: {width: 768, height: 800}});
	await page.setContent(fixture.source);
	const sourceWidth = await page.locator('.choice').first().evaluate(el => el.getBoundingClientRect().width);
	await page.setContent(`<style>.wp-block-button__link{box-sizing:border-box;width:100%}</style><style>${fixture.css}</style>${fixture.candidate}`);
	// The source fill must survive both importer wrapper layouts. Inline styles
	// deliberately beat the per-control display:block!important declarations.
	for (const flattened of [false, true]) {
	if (flattened) {
		await page.locator('.wp-block-buttons,.wp-block-button').evaluateAll(elements => {
			for (const element of elements) element.style.setProperty('display', 'contents', 'important');
		});
	}
	const buttons = await page.locator('.wp-block-button__link').evaluateAll(elements => elements.map(el => {
		const box = el.getBoundingClientRect();
		const price = el.lastElementChild.getBoundingClientRect();
		return {width: box.width, track: el.closest('.choices').getBoundingClientRect().width, price: el.lastElementChild.textContent, rightInset: box.right - price.right, visible: price.left >= box.left && price.right <= box.right};
	}));
	console.log(JSON.stringify({flattened, sourceWidth, buttons}));
	assert.equal(sourceWidth, 600);
	for (const button of buttons) {
		assert.equal(button.width, sourceWidth);
		assert.equal(button.width, button.track);
		assert.equal(button.visible, true);
		assert.equal(button.rightInset, 21);
	}
	assert.equal(buttons[0].price, '39 €');
	}
} finally {
	await browser.close();
}
