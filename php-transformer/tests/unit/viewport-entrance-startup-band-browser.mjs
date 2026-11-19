import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

// The capture proves a CSS entrance's startup at the canvases it observed
// (1440 and 390 wide). A canvas between them (768) shows the layout the
// import projects from the wider capture, so it takes that capture's startup
// instead of reporting the lifecycle unproven (#5874). Extrapolating above
// all observed widths is not proven; a taller canvas only preserves pending
// startup when the entrance is still outside both viewport heights.
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = fileURLToPath(new URL('../..', import.meta.url));
const runtime = execFileSync('php', ['-r', `require '${root}/vendor/autoload.php'; echo Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\Generators\\ViewportEntranceRuntime::script();`], { encoding: 'utf8' });
const timing = { delay: 0, direction: 'normal', duration: 200, easing: 'linear', endDelay: 0, fill: 'backwards', iterationStart: 0, iterations: 1 };
const frames = [
    { offset: 0, easing: 'linear', composite: 'auto', opacity: '0', computedOffset: 0 },
    { offset: 1, easing: 'linear', composite: 'auto', opacity: '1', computedOffset: 1 },
];
const evidence = (wide, narrow) => JSON.stringify({ attributes: { 'data-interact-enter': { before: null, after: 'done' } }, threshold: [0.15], rootMargin: '0px 0px 0px 0px', repeat: false,
    cssAnimations: [{ name: 'motion-fadeIn', timing, frames }],
    startupContexts: [{ width: 1440, height: 900, started: wide }, { width: 390, height: 900, started: narrow }] });
const html = `<!doctype html><style>html{overflow:hidden}div{height:80px}</style>
<div id="started" data-interact-enter="done" data-blocks-engine-capture-viewport-entrance='${evidence(true, true)}'>Started</div>
<div style="height:2000px"></div>
<div id="below" data-interact-enter="done" data-blocks-engine-capture-viewport-entrance='${evidence(false, true)}'>Below</div>`;
const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_PATH });
try {
    const visit = async (width, height) => {
        const page = await browser.newPage({ viewport: { width, height } });
        await page.setContent(html);
        await page.addScriptTag({ content: runtime });
        const loss = (id) => page.locator(id).getAttribute('data-blocks-engine-capture-viewport-entrance-loss');
        return { page, loss };
    };
    {
        const { page, loss } = await visit(768, 900);
        assert.equal(await loss('#started'), null, 'an unobserved width between captures takes the wider capture startup');
        assert.equal(await loss('#below'), null, 'an unstarted entrance at that width is proven too');
        assert.deepEqual(await page.locator('#started').evaluate(node => node.getAnimations().map(effect => effect.playState)), ['running'], 'the wider capture started it at load');
        assert.equal(await page.locator('#below').evaluate(node => Number(getComputedStyle(node).opacity)), 0, 'the wider capture did not: it holds its start frame until it enters');
        await page.waitForFunction(() => document.querySelector('#started').getAttribute('data-interact-enter') === 'done');
        assert.equal(await page.locator('#started').getAttribute('data-interact-enter'), 'done', 'the started entrance completes on its after pose');
        await page.close();
    }
    {
        const { page, loss } = await visit(1600, 900);
        assert.equal(await loss('#started'), 'CSS startup context was not observed', 'a width above every capture must not extrapolate startup');
        assert.equal(await loss('#below'), 'CSS startup context was not observed', 'even a pending entrance has no proven layout beyond the widest capture');
        await page.close();
    }
    {
        const { page, loss } = await visit(768, 700);
        assert.equal(await loss('#started'), 'CSS startup context was not observed', 'a started entrance at an unobserved canvas height stays unproven');
        assert.equal(await loss('#below'), null, 'a pending entrance outside both heights remains pending');
        await page.close();
    }
    {
        const { page, loss } = await visit(390, 1200);
        assert.equal(await loss('#started'), 'CSS startup context was not observed', 'a taller viewport cannot borrow a started pose');
        // The narrow capture started this entrance, so its current offscreen
        // position alone cannot prove that the source held it pending.
        assert.equal(await loss('#below'), 'CSS startup context was not observed', 'an observed started pose is not evidence for pending startup');
        await page.close();
    }
    {
        const { page, loss } = await visit(768, 2500);
        assert.equal(await loss('#below'), 'CSS startup context was not observed', 'a taller viewport that now contains the entrance cannot borrow pending startup');
        await page.close();
    }
} finally { await browser.close(); }
console.log('Viewport entrance startup band browser contract passed.');
