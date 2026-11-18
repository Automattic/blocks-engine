import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');

const fixturePath = fileURLToPath(new URL('../../../tests/contract/responsive-structural-section-inset.php', import.meta.url));
const fixture = JSON.parse(execFileSync('php', [fixturePath, '--geometry'], { encoding: 'utf8' }));
const browser = await chromium.launch();
try {
	const measurements = [];
	for (const width of [390, 768, 1440]) {
		const source = await browser.newPage({ viewport: { width, height: 900 } });
		const candidate = await browser.newPage({ viewport: { width, height: 900 } });
		await Promise.all([source.setContent(fixture.sourceHtml), candidate.setContent(fixture.candidateHtml)]);
		const positions = (page) => page.locator('h2').evaluateAll((headings) => headings.map((heading) => {
			const { x, y } = heading.getBoundingClientRect();
			return { x, y };
		}));
		const [expected, actual] = await Promise.all([positions(source), positions(candidate)]);
		assert.equal(actual.length, 2);
		for (let i = 0; i < expected.length; i++) {
			for (const axis of ['x', 'y']) {
				assert.ok(Math.abs(expected[i][axis] - actual[i][axis]) < 0.1, `${width}px heading ${i} ${axis}: ${actual[i][axis]} vs ${expected[i][axis]}`);
			}
		}
		measurements.push({ width, source: expected, imported: actual });
		await Promise.all([source.close(), candidate.close()]);
	}
	console.log(JSON.stringify({ test: 'responsive structural section inset', measurements }, null, 2));
} finally {
	await browser.close();
}
