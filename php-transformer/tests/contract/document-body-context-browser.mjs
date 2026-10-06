import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixtures = JSON.parse(readFileSync(process.env.BODY_CONTEXT_ARTIFACT, 'utf8'));
const browser = await chromium.launch({ headless: true });
const evidence = [];
let checks = 0;
try {
    const page = await browser.newPage();
    for (const fixture of fixtures) {
        for (const width of [1440, 390, 768]) {
            await page.setViewportSize({ width, height: 900 });
            const measurements = {};
            for (const role of ['source', 'native', 'editor']) {
                await page.setContent(fixture[role], { waitUntil: 'load' });
                measurements[role] = await page.evaluate(() => ({
                    body: { paddingTop: getComputedStyle(document.body).paddingTop, background: getComputedStyle(document.body).backgroundColor, color: getComputedStyle(document.body).color, mode: document.body.dataset.mode, id: document.body.id, emptyState: document.body.getAttribute('data-empty'), documentState: document.documentElement.dataset.document },
                    frames: ['header-frame', 'content-frame', 'same-element', 'footer-frame'].map((id) => {
                        const node = document.getElementById(id);
                        if (!node) throw new Error(`Missing native frame ${id}`);
                        const box = node.getBoundingClientRect();
                        const css = getComputedStyle(node);
                        return { id, x: box.x, y: box.y, width: box.width, height: box.height, marginLeft: css.marginLeft, marginRight: css.marginRight, paddingLeft: css.paddingLeft, paddingRight: css.paddingRight, paddingTop: css.paddingTop, background: css.backgroundColor };
                    }),
                    scrollWidth: document.documentElement.scrollWidth,
                    contentRules: (() => { const matches = []; const node = document.getElementById('content-frame'); function walk(rules) { for (const rule of rules) { if (rule.selectorText && node.matches(rule.selectorText)) matches.push(rule.cssText); else if (rule.cssRules) walk(rule.cssRules); } } for (const sheet of document.styleSheets) { try { walk(sheet.cssRules); } catch {} } return matches; })(),
                    footerParents: (() => { const rows = []; for (let node = document.getElementById('footer-frame'); node; node = node.parentElement) rows.push({ tag: node.tagName, className: node.className, html: node.outerHTML.slice(0, 800), margin: getComputedStyle(node).margin, padding: getComputedStyle(node).padding }); return rows; })(),
                }));
            }
            evidence.push({ route: fixture.route, width, measurements });
            for (const role of ['native', 'editor']) {
                assert.deepEqual(measurements[role].frames, measurements.source.frames, `${fixture.route} ${width} ${role}: ancestor gutters, same-class subject and shared frames`);
                checks++;
                assert.deepEqual(measurements[role].body, measurements.source.body, `${fixture.route} ${width} ${role}: body subject/state`);
                checks++;
                assert.equal(measurements[role].scrollWidth, measurements.source.scrollWidth, `${fixture.route} ${width} ${role}: viewport overflow`);
                checks++;
            }
        }
    }
} finally {
    await browser.close();
    if (process.env.BODY_CONTEXT_EVIDENCE) writeFileSync(process.env.BODY_CONTEXT_EVIDENCE, JSON.stringify({ checks, evidence }, null, 2));
}
console.log(`Document body context browser: ${checks} checks across ${evidence.length} route/viewport cells passed`);
