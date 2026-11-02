import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const plans = JSON.parse(execFileSync('php', [fileURLToPath(new URL('../contract/detached-auto-inset-backdrop.php', import.meta.url)), '--json'], {encoding: 'utf8'}));
const browser = await chromium.launch({headless: true});
try {
    const page = await browser.newPage({viewport: {width: 1000, height: 150}});
    for (const [kind, plan] of Object.entries(plans)) {
    const context = plan.assets.filter(asset => (asset.path || '').includes('shared-chrome-context')).map(asset => asset.content).join('\n');
    for (const mobile of (kind === 'inline' ? [false] : [false, true])) {
        // Isolate the shell transport from unrelated block/theme defaults. Use
        // the actual generated backdrop hook and detached-context stylesheet.
        const id = mobile ? 'backdrop--data-blocks-engine-capture-mobile' : 'backdrop';
        const siblings = kind === 'siblings' ? '<div class="skip-links" style="height:0;overflow:hidden">Skip</div><nav class="mobile-menu" style="height:0;overflow:hidden">Menu</nav>' : '';
        const bg = `${siblings}<div id="${id}" class="bg"></div>`;
        const header = '<header>Studio Name</header>';
        const main = '<main style="height:300px">Route content</main>';
        const footer = '<footer>Studio footer</footer>';
        const css = 'body{position:relative;margin:0}.bg{position:absolute;width:100%;height:100%}header{height:16px}footer{height:40px}';
        const measure = () => page.evaluate(() => ({
            top: document.querySelector('.bg').getBoundingClientRect().top,
            height: document.documentElement.scrollHeight,
            footer: document.querySelector('footer').getBoundingClientRect().bottom,
        }));
        await page.setContent(`<style>${css}</style>${bg}${header}${main}${footer}`);
        const source = await measure();
        await page.setContent(`<style>${css}</style><div class="wp-site-blocks">${header}<div class="wp-block-post-content">${bg}${main}</div>${footer}</div>`);
        const broken = await measure();
        assert.equal(broken.top, 16, 'reduction reproduces the hoisted-header static-position shift');
        assert.equal(broken.height, broken.footer + 16, 'unfixed backdrop creates extra scroll');
        await page.addStyleTag({content: context});
        const fixed = await measure();
        assert.equal(fixed.top, 0, 'generated CSS preserves the source backdrop origin');
        assert.equal(fixed.height, fixed.footer, 'document ends at the footer');
        assert.deepEqual(fixed, source);
    }
    }
    await page.close();
} finally {
    await browser.close();
}
console.log('detached auto-inset backdrop browser geometry passed');
