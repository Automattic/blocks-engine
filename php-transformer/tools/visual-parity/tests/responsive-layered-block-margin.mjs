import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');

const fixturePath = fileURLToPath(new URL('../../../tests/unit/responsive-layered-block-margin.php', import.meta.url));
const fixtureOutput = execFileSync('php', [fixturePath], { encoding: 'utf8' });
const fixture = JSON.parse(fixtureOutput.slice(0, fixtureOutput.indexOf('\n')));
const browser = await chromium.launch();
const cases = [
	{
		name: 'single-breakpoint-shell-margin',
		sourceHtml: fixture.sourceHtml,
		candidateHtml: fixture.candidateHtml,
		expected: { 390: '32px', 768: '64px', 1280: '64px', 1440: '64px' },
	},
	...fixture.scenarios,
];

try {
	const measurements = [];
	for (const testCase of cases) {
		const widths = testCase.expected ? Object.keys(testCase.expected).map(Number) : [];
		assert.notEqual(widths.length, 0, `${testCase.name} declares expected winners at tested widths`);
		for (const width of widths) {
			const source = await browser.newPage({ viewport: { width, height: 720 }, deviceScaleFactor: 1, reducedMotion: 'reduce' });
			const candidate = await browser.newPage({ viewport: { width, height: 720 }, deviceScaleFactor: 1, reducedMotion: 'reduce' });
			await Promise.all([source.setContent(testCase.sourceHtml), candidate.setContent(testCase.candidateHtml)]);
			await Promise.all([source.evaluate(() => document.fonts.ready), candidate.evaluate(() => document.fonts.ready)]);
			const probe = (page) => page.evaluate(() => {
				const footer = document.querySelector('footer');
				const rect = footer.getBoundingClientRect();
				return {
					readyState: document.readyState,
					fontStatus: document.fonts.status,
					pendingImages: [...document.images].filter((image) => !image.complete || !image.naturalWidth).length,
					reducedMotion: matchMedia('(prefers-reduced-motion: reduce)').matches,
					devicePixelRatio,
					marginTop: getComputedStyle(footer).marginTop,
					box: { x: +rect.x.toFixed(2), y: +rect.y.toFixed(2), width: +rect.width.toFixed(2), height: +rect.height.toFixed(2) },
				};
			});
			const [sourceProbe, candidateProbe] = await Promise.all([probe(source), probe(candidate)]);
			const [sourceScreenshot, candidateScreenshot] = await Promise.all([
				source.screenshot({ fullPage: true }),
				candidate.screenshot({ fullPage: true }),
			]);
			assert.equal(sourceProbe.readyState, 'complete');
			assert.equal(candidateProbe.readyState, 'complete');
			assert.equal(sourceProbe.fontStatus, 'loaded');
			assert.equal(candidateProbe.fontStatus, 'loaded');
			assert.equal(sourceProbe.pendingImages, 0);
			assert.equal(candidateProbe.pendingImages, 0);
			assert.equal(sourceProbe.reducedMotion, true);
			assert.equal(candidateProbe.reducedMotion, true);
			assert.equal(sourceProbe.marginTop, testCase.expected[width], `${testCase.name}: source cascade winner at ${width}px`);
			assert.deepEqual(candidateProbe, sourceProbe, `${testCase.name}: materialized margin and geometry at ${width}px`);
			assert.deepEqual(candidateScreenshot, sourceScreenshot, `${testCase.name}: source/candidate screenshots at ${width}px`);
			measurements.push({ scenario: testCase.name, width, ...sourceProbe, screenshotSha256: createHash('sha256').update(sourceScreenshot).digest('hex') });
			await Promise.all([source.close(), candidate.close()]);
		}
	}
	console.log(JSON.stringify({ test: 'responsive layered block margin materialization passed', scenarios: cases.map(({ name, marker }) => ({ name, marker })), measurements }, null, 2));
} finally {
	await browser.close();
}
