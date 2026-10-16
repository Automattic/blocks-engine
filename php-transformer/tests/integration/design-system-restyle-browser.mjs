import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';

// Measures, in Chromium, how much of a converted site follows a Global Styles
// palette edit. RESTYLE_PHASE=before records the computed text and
// background colour of every rendered text element on every route;
// RESTYLE_PHASE=after renders the routes again once the palette has new
// values and counts the elements whose colour changed. An element restyles
// when its colour comes from a palette reference, through its own preset,
// an ancestor's, or the root styles.
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const routes = JSON.parse(readFileSync(process.env.RESTYLE_ROUTES, 'utf8'));
const phase = process.env.RESTYLE_PHASE;
const evidence = process.env.RESTYLE_EVIDENCE;
const minimum = Number(process.env.RESTYLE_MINIMUM || '0.8');

// Text elements: elements with their own visible text, as a reader sees them.
const measure = () => {
    const rows = [];
    const opaque = color => !/^rgba\(.*,\s*0\)$/.test(color) && 'transparent' !== color;
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_ELEMENT);
    let index = 0;
    for (let node = walker.currentNode; node; node = walker.nextNode()) {
        index++;
        if (['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE', 'svg'].includes(node.tagName) || node.closest('.screen-reader-text, #wpadminbar, svg')) continue;
        const text = Array.from(node.childNodes).filter(child => Node.TEXT_NODE === child.nodeType).map(child => child.textContent.trim()).join(' ').trim();
        const css = getComputedStyle(node);
        const rect = node.getBoundingClientRect();
        const visible = rect.width > 0 && rect.height > 0 && 'hidden' !== css.visibility && 'none' !== css.display;
        if (!visible) continue;
        let background = null;
        for (let ancestor = node; ancestor && ancestor !== document.documentElement; ancestor = ancestor.parentElement) {
            const value = getComputedStyle(ancestor).backgroundColor;
            if (opaque(value)) { background = value; break; }
        }
        const paintsBackground = opaque(css.backgroundColor) && node !== document.body;
        if ('' === text && !paintsBackground) continue;
        rows.push({ index, tag: node.tagName.toLowerCase(), className: String(node.className).slice(0, 160), text: text.slice(0, 60), color: css.color, background, ownBackground: paintsBackground ? css.backgroundColor : null });
    }
    return rows;
};

const browser = await chromium.launch({ headless: true });
const pages = [];
try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    for (const route of routes.pages) {
        await page.goto(route.url, { waitUntil: 'networkidle' });
        // Read settled colours: no transition or animation frame in between.
        await page.addStyleTag({ content: '*,*::before,*::after{transition:none!important;animation:none!important}' });
        await page.evaluate(() => document.fonts.ready);
        pages.push({ route: route.route, rows: await page.evaluate(measure) });
    }
} finally {
    await browser.close();
}

if ('before' === phase) {
    writeFileSync(evidence, JSON.stringify({ fixture: routes.fixture, pages }, null, 2));
    console.log(`${routes.fixture}: ${pages.reduce((sum, page) => sum + page.rows.length, 0)} elements recorded before the palette edit`);
    process.exit(0);
}

const before = JSON.parse(readFileSync(evidence, 'utf8'));
let text = 0, textRestyled = 0, backgrounds = 0, backgroundsRestyled = 0;
const unchanged = [];
const unchangedBackgrounds = [];
for (const [position, current] of pages.entries()) {
    const previous = before.pages[position];
    assert.equal(current.route, previous.route, 'Routes render in the same order');
    assert.equal(current.rows.length, previous.rows.length, `${current.route}: the palette edit changes colours, not the document`);
    for (const [row, after] of current.rows.entries()) {
        const original = previous.rows[row];
        if ('' !== original.text) {
            text++;
            if (after.color !== original.color) textRestyled++;
            else unchanged.push({ route: current.route, tag: original.tag, className: original.className, text: original.text, color: original.color });
        }
        if (null !== original.ownBackground) {
            backgrounds++;
            if (after.ownBackground !== original.ownBackground) backgroundsRestyled++;
            else unchangedBackgrounds.push({ route: current.route, tag: original.tag, className: original.className, background: original.ownBackground });
        }
    }
}
const share = (count, total) => (0 === total ? 1 : Math.round((count / total) * 10000) / 10000);
const result = { fixture: routes.fixture, design_system: routes.design_system, text_elements: text, text_restyled: textRestyled, text_restyle_share: share(textRestyled, text), background_elements: backgrounds, backgrounds_restyled: backgroundsRestyled, background_restyle_share: share(backgroundsRestyled, backgrounds), unchanged, unchanged_backgrounds: unchangedBackgrounds };
writeFileSync(evidence, JSON.stringify({ ...before, result }, null, 2));
console.log(JSON.stringify({ ...result, unchanged: unchanged.length, unchanged_backgrounds: unchangedBackgrounds.length }));
assert.ok(result.text_restyle_share >= minimum, `${routes.fixture}: ${textRestyled} of ${text} text elements follow the palette edit; at least ${minimum * 100}% must.\n${JSON.stringify(unchanged.slice(0, 40), null, 1)}`);
