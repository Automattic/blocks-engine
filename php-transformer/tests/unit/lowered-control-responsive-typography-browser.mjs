import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./lowered-control-responsive-typography.php', import.meta.url)), '--json'], {encoding: 'utf8'}));
const browser = await chromium.launch();
try {
	for (const [width, expected] of [[390, '20.8px'], [1440, '33.6px']]) {
		const page = await browser.newPage({viewport: {width, height: 844}});
		for (const [label, html] of Object.entries(fixture)) {
			await page.setContent(html);
			assert.equal(await page.locator('a b').evaluate(element => getComputedStyle(element).fontSize), expected, `${label} at ${width}px`);
		}
		await page.close();
	}
} finally {
	await browser.close();
}
console.log('lowered control responsive typography browser passed');
