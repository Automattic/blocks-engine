import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { PNG } from 'pngjs';
import pixelmatch from 'pixelmatch';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

// Bounded paint diagnostic, not a WordPress acceptance substitute. Native save
// markup remains the immutable + span; inline is an owning-layer experiment.
const shape = '<path d="m6 9 6 6 6-6"/>';
const svg = (style = '') => `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:rgb(37,56,85);${style}">${shape}</svg>`;
const transforms = {
    closed: 'none',
    expanded: 'rotate(180deg)',
    neutral135: 'rotate(135deg)',
    translated: 'translate(10px,-7px)',
    scaled: 'scale(1.8)',
    combined: 'translate(9px,-4px) rotate(37deg) scale(1.6)',
};
const styles = 'width:18px;height:18px;display:block;flex-shrink:0;font-size:0;line-height:0;';
const html = (mode, transform, clipped, phase) => {
    const image = svg(mode === 'internal' ? `transform:${transform};transform-origin:9px 9px;` : '');
    const artwork = `background-image:url("data:image/svg+xml,${encodeURIComponent(image)}");background-size:contain;background-position:center;background-repeat:no-repeat;`;
    const slot = mode === 'source' ? svg('transform:none;transform-origin:9px 9px;transition:transform 150ms;')
        : `<span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true" style='${styles}${mode === 'inline' ? 'overflow:visible;' : artwork}transform:none;transform-origin:9px 9px;transition:transform 150ms;'>${mode === 'inline' ? svg('transform:none;transform-origin:9px 9px;transition:transform 150ms;') : '+'}</span>`;
    return `<style>body{margin:0;background:white}.boundary{position:absolute;left:${32 + phase}px;top:${32 + phase}px;width:18px;height:18px;overflow:${clipped ? 'hidden' : 'visible'}}button{all:unset;display:flex;width:18px;height:18px}svg{display:block;flex-shrink:0}</style><div class="boundary"><h3 class="wp-block-accordion-heading" style="margin:0"><button type="button" class="wp-block-accordion-heading__toggle">${slot}</button></h3></div>`;
};
const difference = (a, b) => {
    assert.equal(a.width, b.width, 'screenshot width readiness');
    assert.equal(a.height, b.height, 'screenshot height readiness');
    let channels = 0, maxDelta = 0;
    for (let i = 0; i < a.data.length; i++) {
        if (i % 4 === 3) continue;
        const delta = Math.abs(a.data[i] - b.data[i]);
        if (delta) channels++;
        maxDelta = Math.max(maxDelta, delta);
    }
    return { pixels: pixelmatch(a.data, b.data, null, a.width, a.height, { threshold: 0, includeAA: true }), channels, maxDelta };
};
const browser = await chromium.launch();
try {
    const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('../../../tests/unit/accordion-vector-presentation-fixture.php', import.meta.url))], { encoding: 'utf8' }));
    assert.equal(fixture.validity.status, 'pass', 'compiler emits canonical Core save shape');
    assert.ok(!fixture.markup.includes('<svg') && fixture.markup.includes('aria-hidden="true">+</span>'), 'native saved slot stays canonical');
    const native = await browser.newPage();
    await native.setContent(`<style>${fixture.css}</style>${fixture.markup}`, { waitUntil: 'load' });
    const nativeProof = await native.locator('.wp-block-accordion-heading__toggle-icon').first().evaluate(async element => {
        const style = getComputedStyle(element);
        const image = new Image(); image.src = style.backgroundImage.slice(5, -2);
        await Promise.race([image.decode(), new Promise((_, reject) => setTimeout(() => reject(new Error('native SVG decode readiness timeout')), 5000))]);
        if (!image.naturalWidth || !image.naturalHeight) throw new Error('native artwork missing');
        return { width: style.width, height: style.height, imageWidth: image.naturalWidth, imageHeight: image.naturalHeight };
    });
    assert.equal(nativeProof.width, '18px');
    assert.equal(nativeProof.height, '18px');
    await native.locator('.wp-block-accordion-heading__toggle').first().evaluate(element => element.setAttribute('aria-expanded', 'true'));
    await native.waitForFunction(() => getComputedStyle(document.querySelector('.wp-block-accordion-heading__toggle-icon')).transform === 'matrix(-1, 0, 0, -1, 0, 0)' && !document.getAnimations().some(a => a.playState === 'running'), null, { timeout: 5000 });
    assert.equal(await native.locator('.wp-block-accordion-heading__toggle-icon').first().evaluate(element => getComputedStyle(element).transform), 'matrix(-1, 0, 0, -1, 0, 0)');
    await native.close();
    const results = [];
    for (const width of [390, 768, 1440]) {
        const page = await browser.newPage({ viewport: { width, height: 120 }, deviceScaleFactor: 1 });
        for (const clipped of [false, true]) {
          for (const phase of [0, 0.5]) {
            for (const [state, transform] of Object.entries(transforms)) {
                const captures = {};
                for (const mode of ['source', 'background', 'internal', 'inline']) {
                    await page.setContent(html(mode, transform, clipped, phase), { waitUntil: 'load' });
                    await page.evaluate(async () => {
                        await document.fonts.ready;
                        const slot = document.querySelector('.wp-block-accordion-heading__toggle-icon');
                        if (slot && getComputedStyle(slot).backgroundImage !== 'none') {
                            const url = getComputedStyle(slot).backgroundImage.slice(5, -2);
                            const image = new Image(); image.src = url;
                            await Promise.race([image.decode(), new Promise((_, reject) => setTimeout(() => reject(new Error('vector decode timeout')), 5000))]);
                            if (!image.naturalWidth || !image.naturalHeight) throw new Error('missing vector image readiness');
                        }
                        await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                        if (document.readyState !== 'complete' || document.fonts.status !== 'loaded' || document.getAnimations().some(a => a.playState === 'running')) throw new Error('missing paint readiness');
                    });
                    if (mode !== 'internal') {
                        await page.evaluate(async ({ mode, transform }) => {
                            const target = document.querySelector(mode === 'background' ? '.wp-block-accordion-heading__toggle-icon' : 'svg');
                            target.style.transform = transform;
                            await new Promise(resolve => requestAnimationFrame(resolve));
                            await Promise.race([Promise.all(document.getAnimations().map(a => a.finished)), new Promise((_, reject) => setTimeout(() => reject(new Error('transition readiness timeout')), 5000))]);
                            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                            if (getComputedStyle(target).transform === 'none' && transform !== 'none') throw new Error('expanded state missing');
                            if (document.getAnimations().some(a => a.playState === 'running')) throw new Error('transition not settled');
                        }, { mode, transform });
                    }
                    const box = await page.locator(mode === 'source' ? 'svg' : '.wp-block-accordion-heading__toggle-icon').boundingBox();
                    assert.ok(box && box.width > 0 && box.height > 0, 'nonempty vector slot');
                    captures[mode] = PNG.sync.read(await page.screenshot({ clip: { x: 0, y: 0, width: 96, height: 96 } }));
                }
                const background = difference(captures.source, captures.background);
                const internal = difference(captures.source, captures.internal);
                const inline = difference(captures.source, captures.inline);
                assert.deepEqual(inline, { pixels: 0, channels: 0, maxDelta: 0 }, `inline vector equality ${width}/${clipped}/${state}`);
                if (!clipped && ['translated', 'scaled', 'combined'].includes(state)) assert.ok(internal.pixels > 0, 'internal image transform loses bounds');
                results.push({ width, clipped, phase, state, background, internal, inline });
            }
          }
        }
        await page.close();
    }
    console.log(JSON.stringify({ chromium: browser.version(), threshold: 0, includeAA: true, nativeProof, results }));
    assert.ok(results.some(r => r.state === 'expanded' && r.background.pixels > 0), 'expanded background loss reproduced');
} finally {
    await browser.close();
}
