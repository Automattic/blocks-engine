import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./details-control-presentation-fixture.php', import.meta.url))], { encoding: 'utf8' }));
assert.equal(fixture.validity.status, 'pass');
assert.equal(fixture.responsiveValidity.status, 'pass');

const browser = await chromium.launch();
try {
	const neutralGeometry = {};
	const neutralPaint = {};
	for (const stage of ['source', 'candidate']) {
		const page = await browser.newPage({ viewport: { width: 1000, height: 500 }, deviceScaleFactor: 1, reducedMotion: 'reduce' });
		await page.setContent(fixture[stage]);
		neutralGeometry[stage] = await page.evaluate(() => {
			const box = (element) => {
				const rect = element.getBoundingClientRect();
				return { x: rect.x, y: rect.y, width: rect.width, height: rect.height };
			};
			const summary = document.querySelector('summary');
			const walker = document.createTreeWalker(summary, NodeFilter.SHOW_TEXT);
			const text = [];
			for (let node = walker.nextNode(); node; node = walker.nextNode()) {
				if (!node.textContent.trim()) continue;
				const range = document.createRange();
				range.selectNodeContents(node);
				text.push(box(range));
			}
			return { control: box(summary), text, icon: box(summary.querySelector('svg')), next: box(document.querySelector('.next')) };
		});
		neutralPaint[stage] = [await page.locator('summary').screenshot()];
		await page.locator('summary').hover();
		neutralPaint[stage].push(await page.locator('summary').screenshot());
		await page.locator('summary').click();
		assert.equal(await page.locator('details').evaluate((element) => element.open), true);
		await page.close();
	}
	assert.deepEqual(neutralGeometry.candidate, neutralGeometry.source, 'native disclosure retains the source control box, label, icon and following sibling geometry');
	assert.deepEqual(neutralPaint.candidate, neutralPaint.source, 'source and candidate resting/hover disclosure paint is pixel-identical');

	const responsiveProof = {};
	for (const width of [390, 1280]) {
		const capture = {};
		for (const stage of ['responsiveSource', 'responsiveCandidate']) {
			const page = await browser.newPage({ viewport: { width, height: 844 }, deviceScaleFactor: 1, reducedMotion: 'reduce' });
			await page.setContent(fixture[stage]);
			await page.evaluate(() => document.fonts.ready);
			capture[stage] = await page.evaluate((stage) => {
				const box = (element) => {
					const rect = element.getBoundingClientRect();
					if (0 === rect.width && 0 === rect.height) return { x: 0, y: 0, width: 0, height: 0 };
					return { x: +rect.x.toFixed(2), y: +rect.y.toFixed(2), width: +rect.width.toFixed(2), height: +rect.height.toFixed(2) };
				};
				const style = (element) => {
					const computed = getComputedStyle(element);
					return {
						display: computed.display,
						padding: [computed.paddingTop, computed.paddingRight, computed.paddingBottom, computed.paddingLeft],
						lineHeight: computed.lineHeight,
						fontFamily: computed.fontFamily,
						fontSize: computed.fontSize,
						fontWeight: computed.fontWeight,
					};
				};
				const details = document.querySelector('details');
				const summary = details.querySelector('summary');
				const carrier = summary.querySelector('.blocks-engine-summary-content-carrier');
				const control = 'responsiveSource' === stage ? summary : carrier;
				const icon = summary.querySelector('svg');
				return {
					viewport: { width: innerWidth, height: innerHeight, devicePixelRatio },
					readiness: {
						document: document.readyState,
						fonts: document.fonts.status,
						pendingImages: [...document.images].filter((image) => !image.complete || !image.naturalWidth).length,
						runningAnimations: document.getAnimations({ subtree: true }).filter((animation) => 'running' === animation.playState).length,
						reducedMotion: matchMedia('(prefers-reduced-motion: reduce)').matches,
					},
					header: { box: box(document.querySelector('.site-header')), style: style(document.querySelector('.site-header')) },
					main: { box: box(document.querySelector('.content')) },
					brand: box(document.querySelector('.brandbox')),
					details: { box: box(details) },
					summary: { box: box(summary) },
					control: { box: box(control), style: style(control) },
					icon: { box: box(icon), style: style(icon) },
				};
			}, stage);
			if ( 390 === width ) {
				const image = await page.locator('summary').screenshot();
				capture[stage].screenshotSha256 = createHash('sha256').update(image).digest('hex');
				capture[stage].screenshot = image;
			}
			await page.close();
		}
		assert.deepEqual(capture.responsiveCandidate, capture.responsiveSource, `responsive disclosure geometry, computed styles, fonts and paint match at ${width}px`);
		delete capture.responsiveCandidate.screenshot;
		delete capture.responsiveSource.screenshot;
		responsiveProof[width] = capture;
	}
	console.log(JSON.stringify({ name: 'Details control presentation browser regression passed', responsiveProof }));
} finally {
	await browser.close();
}
