import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from '../../tools/visual-parity/node_modules/playwright/index.mjs';

const root = new URL('../..', import.meta.url).pathname;
const runtime = execFileSync('php', ['-r', `require '${root}/vendor/autoload.php'; echo Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\Generators\\ViewportEntranceRuntime::script('{"mobile":"(width <= 750px)","desktop":"(width > 750px)"}');`], { encoding: 'utf8' });
const state = (repeat, devices) => JSON.stringify({ ...(devices ? { devices } : {}), attributes: { class: { before: 'reveal', after: 'reveal in' }, style: { before: 'translate: 0 8px', after: null }, 'data-pose': { before: null, after: 'done' } }, threshold: [0.12], rootMargin: '0px 0px 0px 0px', repeat });
const html = (devices) => `<style>.reveal{height:200px;opacity:0;transform:translateY(24px);transition:opacity .8s linear,transform .8s}.reveal.in{opacity:1;transform:none}</style><div style="height:1400px"></div>${[false, true].map((repeat, index) => `<section id="card${index}" class="wp-block-group reveal in" style="color: rgb(1, 2, 3)" data-blocks-engine-capture-viewport-entrance='${state(repeat, devices)}'>Card</section>`).join('')}<div style="height:1400px"></div>`;
const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
try {
    for (const mode of ['normal', 'reduced', 'no-observer', 'no-js', 'other-device']) {
        const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, reducedMotion: mode === 'reduced' ? 'reduce' : 'no-preference', javaScriptEnabled: mode !== 'no-js' });
        // At 1440px the desktop viewport query matches; a mobile-only entrance settles.
        await page.setContent(html(mode === 'other-device' ? ['mobile'] : mode === 'normal' ? ['desktop'] : undefined));
        if (mode === 'no-observer') await page.evaluate(() => { delete window.IntersectionObserver; });
        if (mode !== 'no-js') await page.addScriptTag({ content: runtime });
        const opacity = () => page.locator('#card0').evaluate(node => Number(getComputedStyle(node).opacity));
        // Compiled classes and declarations survive every replayed pose.
        const compiled = async (id) => assert.deepEqual(await page.locator(id).evaluate(node => [node.classList.contains('wp-block-group'), node.style.color]), [true, 'rgb(1, 2, 3)'], `${mode}: ${id} keeps compiled attributes`);
        if (mode !== 'normal') {
            assert.equal(await opacity(), 1, `${mode}: final visible markup`);
        } else {
            await page.waitForTimeout(900);
            assert.equal(await opacity(), 0, 'offscreen before pose is hidden');
            await page.evaluate(() => scrollTo(0, 1300));
            await page.waitForTimeout(150);
            const transitioning = await opacity();
            assert.ok(transitioning > 0 && transitioning < 1, `transitioning: ${transitioning}`);
            await page.waitForTimeout(850);
            assert.equal(await opacity(), 1);
            await page.evaluate(() => scrollTo(0, 0));
            await page.waitForTimeout(900);
            assert.equal(await opacity(), 1, 'one-shot entrance stays revealed');
            assert.equal(await page.locator('#card1').getAttribute('class'), 'wp-block-group reveal', 'repeat resets offscreen');
            assert.equal(await page.locator('#card1').evaluate(node => node.style.translate), '0px 8px', 'repeat restores the before declaration');
            await compiled('#card1');
            await page.emulateMedia({ reducedMotion: 'reduce' });
            await page.waitForTimeout(900);
            assert.equal(await page.locator('#card1').getAttribute('class'), 'wp-block-group reveal in', 'motion preference change reveals pending content');
            assert.equal(await page.locator('#card1').evaluate(node => node.style.translate), '', 'after pose drops the before declaration');
        }
        if (mode !== 'no-js') await compiled('#card0');
        await page.close();
    }
} finally { await browser.close(); }
console.log('Viewport entrance browser contract passed.');
