import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = process.argv[2] || new URL("../..", import.meta.url).pathname.replace(/\/$/, "");
const out = JSON.parse(execFileSync('php', [`${root}/tests/unit/icon-anchor-bar-fixture.php`, root], { encoding: 'utf8', maxBuffer: 1 << 26 }));
const core = process.env.BLOCK_LIBRARY_CSS
	? readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8')
	: await (await fetch('https://unpkg.com/@wordpress/block-library/build-style/style.css')).text();
const browser = await chromium.launch();
try {
	const page = await browser.newPage({ viewport: { width: 800, height: 600 } });
	const measure = () => page.evaluate(() => {
		const anchors = [...document.querySelectorAll('a')];
		const origin = anchors[0].getBoundingClientRect();
		return anchors.map((anchor) => {
			const box = anchor.getBoundingClientRect();
			return { size: [Math.round(box.width), Math.round(box.height)], dx: Math.round(box.left - origin.left), dy: Math.round(box.top - origin.top), fontSize: getComputedStyle(anchor).fontSize };
		});
	});
	await page.setContent(out.src);
	const expected = await measure();
	assert.match(out.html, /wp:buttons/);
	await page.setContent(`<!doctype html><style>${core}</style><style>${out.css}</style>${out.html}`);
	assert.deepEqual(await measure(), expected);
	console.log('PASS zoom controls');
} finally {
	await browser.close();
}
