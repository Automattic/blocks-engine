import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { css } = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./responsive-navigation-direction.php', import.meta.url)), '--json'], { encoding: 'utf8' }));
const browser = await chromium.launch();
try {
    const page = await browser.newPage();
    for (const width of [390, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        // Core's two overlay transport boxes sit between the source list host
        // and its duplicated list. Their baseline is a column, not the host row.
        await page.setContent(`<style>
            .menu{display:flex;flex-direction:column}
            @media(min-width:992px){.menu{flex-direction:row}}
            .wp-block-navigation__responsive-container-content{display:flex;flex-direction:column}
            .wp-block-navigation__container{display:flex}
        </style><nav class="menu wp-block-navigation blocks-engine-list-navigation blocks-engine-native-responsive-navigation">
            <div class="wp-block-navigation__responsive-container"><div class="wp-block-navigation__responsive-container-content"><ul class="wp-block-navigation__container"><li>Home</li><li>About</li></ul></div></div>
        </nav>`);
        await page.addStyleTag({ content: css });
        const direction = () => page.locator('ul').evaluate(el => getComputedStyle(el).flexDirection);
        assert.equal(await direction(), width < 992 ? 'column' : 'row');
        await page.locator('.wp-block-navigation__responsive-container').evaluate(el => el.classList.add('is-menu-open'));
        assert.equal(await direction(), 'column', 'opened overlay keeps Core column');
    }
    console.log('Responsive native navigation preserves direction through closed overlay transport.');
} finally {
    await browser.close();
}
