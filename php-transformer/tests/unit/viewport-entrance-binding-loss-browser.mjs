import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

// A paused CSS entrance whose keyframe reads a fractional custom property
// (source 177.140625px reads back as 177.141px; the compiled pose rounds it to
// 177.14px) must still replay, and a binding that truly changed (the animation
// lives on a descendant) must settle visible instead of staying hidden.
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = fileURLToPath(new URL('../..', import.meta.url));
const runtime = execFileSync('php', ['-r', `require '${root}/vendor/autoload.php'; echo Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\Generators\\ViewportEntranceRuntime::script();`], { encoding: 'utf8' });
const timing = { delay: 0, direction: 'normal', duration: 300, easing: 'linear', endDelay: 0, fill: 'backwards', iterationStart: 0, iterations: 1 };
const frames = [
    { offset: 0, easing: 'linear', composite: 'auto', transform: 'translate(177.141px)', computedOffset: 0 },
    { offset: 1, easing: 'linear', composite: 'auto', transform: 'translate(0px)', computedOffset: 1 },
];
const evidence = JSON.stringify({ attributes: { 'data-interact-enter': { before: null, after: 'done' } }, threshold: [0.15], rootMargin: '0px 0px 0px 0px', repeat: false, cssAnimations: [{ name: 'glide', timing, frames }] });
const html = `<style>
@keyframes glide{from{transform:translate(var(--motion-left))}to{transform:translate(0)}}
.entrance{--motion-left:177.14px;height:80px}
.entrance:not([data-interact-enter]){visibility:hidden}
#rounded:not([data-interact-enter=done]),#moved:not([data-interact-enter=done])>span{display:block;animation:glide .3s linear backwards paused}
</style>
<div id="rounded" class="entrance" data-blocks-engine-capture-viewport-entrance='${evidence}'>Rounded</div>
<div id="moved" class="entrance" data-blocks-engine-capture-viewport-entrance='${evidence}'><span>Moved</span></div>`;
const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_PATH });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.setContent(html);
    await page.addScriptTag({ content: runtime });
    await page.waitForTimeout(900);
    const pose = (id) => page.locator(id).evaluate(node => ({ visibility: getComputedStyle(node).visibility, enter: node.getAttribute('data-interact-enter'), loss: node.getAttribute('data-blocks-engine-capture-viewport-entrance-loss') }));
    assert.deepEqual(await pose('#rounded'), { visibility: 'visible', enter: 'done', loss: null }, 'length rounding is not a changed binding');
    assert.deepEqual(await pose('#moved'), { visibility: 'visible', enter: 'done', loss: 'authored paused CSS animation binding changed' }, 'a changed binding settles on the after pose');
    await page.close();
} finally { await browser.close(); }
console.log('Viewport entrance binding loss browser contract passed.');

