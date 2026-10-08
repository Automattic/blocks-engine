import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixtures = JSON.parse(readFileSync(process.env.PROJECTED_ROOT_DATA_STATE_ARTIFACT, 'utf8'));
const browser = await chromium.launch({ headless: true });
const evidence = [];
let checks = 0;
try {
    const page = await browser.newPage();
    const visible = () => ({
        launched: (() => { const nodes = [...document.querySelectorAll('.launched-only .cta')]; return nodes.map((node) => ({ display: getComputedStyle(node.closest('.launched-only')).display, width: node.getBoundingClientRect().width, fontSize: getComputedStyle(node).fontSize })); })(),
        preview: (() => { const nodes = [...document.querySelectorAll('.preview-only .cta')]; return nodes.map((node) => ({ display: getComputedStyle(node.closest('.preview-only')).display, width: node.getBoundingClientRect().width, fontSize: getComputedStyle(node).fontSize })); })(),
        root: { class: document.documentElement.className, launched: document.documentElement.getAttribute('data-launched') },
    });
    for (const fixture of fixtures) {
        for (const width of [390, 768, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            const measurements = {};
            for (const role of ['source', 'native', 'editor']) {
                await page.setContent(fixture[role], { waitUntil: 'load' });
                measurements[role] = await page.evaluate(visible);
            }
            evidence.push({ route: fixture.route, width, measurements });
            for (const role of ['native', 'editor']) {
                assert.deepEqual(measurements[role].launched, measurements.source.launched, `${fixture.route} ${width} ${role}: launched CTA visibility matches source`);
                assert.deepEqual(measurements[role].preview, measurements.source.preview, `${fixture.route} ${width} ${role}: preview CTA visibility matches source`);
                checks += 2;
            }
        }
    }
} finally {
    await browser.close();
    if (process.env.PROJECTED_ROOT_DATA_STATE_EVIDENCE) writeFileSync(process.env.PROJECTED_ROOT_DATA_STATE_EVIDENCE, JSON.stringify({ checks, evidence }, null, 2));
}
console.log(`Projected root data state browser: ${checks} checks across ${evidence.length} route/viewport cells passed`);
