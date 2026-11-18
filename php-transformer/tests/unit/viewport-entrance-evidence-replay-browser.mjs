import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

// Engine-owned style routes (ADR 0005) never ship the author sheet that bound a
// paused CSS entrance. The captured binding is the replay contract: the element
// must hold its captured start frame, then play it to the after pose, instead
// of reporting a lost binding and snapping to the after pose.
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = fileURLToPath(new URL('../..', import.meta.url));
const runtime = execFileSync('php', ['-r', `require '${root}/vendor/autoload.php'; echo Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\Generators\\ViewportEntranceRuntime::script();`], { encoding: 'utf8' });
const timing = { delay: 0, direction: 'normal', duration: 400, easing: 'linear', endDelay: 0, fill: 'backwards', iterationStart: 0, iterations: 1 };
const frames = [
    { offset: 0, easing: 'linear', composite: 'auto', opacity: '0', computedOffset: 0 },
    { offset: 1, easing: 'linear', composite: 'auto', opacity: '1', computedOffset: 1 },
];
const evidence = (startupContexts) => JSON.stringify({ attributes: { 'data-interact-enter': { before: null, after: 'done' } }, threshold: [0.15], rootMargin: '0px 0px 0px 0px', repeat: false, cssAnimations: [{ name: 'motion-fadeIn', timing, frames }], startupContexts });
const html = (width, height) => `<style>div{height:80px}</style>
<div id="started" data-interact-enter="done" data-blocks-engine-capture-viewport-entrance='${evidence([{ width, height, started: true }])}'>Started</div>
<div style="height:2000px"></div>
<div id="below" data-interact-enter="done" data-blocks-engine-capture-viewport-entrance='${evidence()}'>Below</div>`;
const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_PATH });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    // Scrollbars narrow the client viewport the startup context is keyed by.
    await page.setContent(html(0, 0));
    const [width, height] = await page.evaluate(() => [document.documentElement.clientWidth, document.documentElement.clientHeight]);
    await page.setContent(html(width, height));
    await page.addScriptTag({ content: runtime });
    const pose = (id) => page.locator(id).evaluate(node => ({ opacity: Number(getComputedStyle(node).opacity), enter: node.getAttribute('data-interact-enter'), loss: node.getAttribute('data-blocks-engine-capture-viewport-entrance-loss') }));
    const below = await pose('#below');
    assert.deepEqual(below, { opacity: 0, enter: null, loss: null }, 'an unstarted entrance holds its captured start frame');
    const playing = await page.locator('#started').evaluate(node => node.getAnimations().map(effect => effect.playState));
    assert.deepEqual(playing, ['running'], 'a started entrance plays its captured frames');
    await page.waitForTimeout(700);
    assert.deepEqual(await pose('#started'), { opacity: 1, enter: 'done', loss: null }, 'the captured entrance completes on the after pose');
    await page.locator('#below').scrollIntoViewIfNeeded();
    await page.waitForTimeout(700);
    assert.deepEqual(await pose('#below'), { opacity: 1, enter: 'done', loss: null }, 'a scrolled-in entrance replays to the after pose');
    await page.close();
} finally { await browser.close(); }
console.log('Viewport entrance evidence replay browser contract passed.');
