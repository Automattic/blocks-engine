import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const url = process.env.NAVIGATION_TEST_URL || process.env.STYLESHEET_TEST_URL;
assert.ok(url, 'Disposable WordPress HTTP origin is required');
const browser = await chromium.launch({ headless: true });
const observations = [];
try {
    for (const width of [390, 768, 1440]) {
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        await page.goto(url, { waitUntil: 'networkidle' });
        const opener = page.locator('header .wp-block-navigation__responsive-container-open:visible');
        assert.equal(await opener.count(), width <= 768 ? 1 : 0, `${width}: source branch owns opener visibility`);
        if (width <= 768) {
            const closed = page.locator('header nav.wp-block-navigation:visible .wp-block-navigation__responsive-container:not(.is-menu-open)');
            assert.equal(await closed.count(), 1, `${width}: one controlled closed panel`);
            assert.equal(await closed.evaluate(element => getComputedStyle(element).display), 'none', `${width}: closed overlay stays out of header layout above Core breakpoint too`);
            await opener.click();
        }
        const menu = width <= 768 ? page.locator('header .is-menu-open') : page.locator('header nav.wp-block-navigation:visible');
        if (width <= 768) {
            await page.waitForFunction(() => {
                const panel = document.querySelector('header .is-menu-open');
                return panel && Math.abs(panel.getBoundingClientRect().top - panel.closest('header').getBoundingClientRect().bottom) < 2;
            });
            const panel = await menu.boundingBox();
            assert.ok(panel && Math.abs(panel.x) < 2 && Math.abs(panel.width - width) < 2 && panel.height < 900, `${width}: source header-bound dropdown geometry`);
        }
        const links = menu.locator('a.wp-block-navigation-item__content');
        const inventory = await links.allTextContents();
        assert.deepEqual(inventory, ['Services', 'Gallery', 'Instagram', 'Contact'], `${width}: visible native item inventory`);
        for (const link of await links.all()) {
            assert.ok(await link.isVisible(), `${width}: item is visible`);
            const box = await link.boundingBox();
            assert.ok(box && box.x >= 0 && box.x + box.width <= width + 1, `${width}: item stays within viewport: ${JSON.stringify(box)}`);
        }
        const suffix = width <= 768 ? '--phone' : '';
        for (const [label, id] of [['Services', 'services'], ['Gallery', 'gallery'], ['Contact', 'contact']]) {
            if (width <= 768 && !await menu.isVisible()) await opener.click();
            const link = menu.getByRole('link', { name: label, exact: true });
            const hash = new URL(await link.getAttribute('href'), url).hash;
            assert.equal(hash, `#${id}${suffix}`, `${width}: correct responsive fragment`);
            await link.click();
            await page.waitForFunction(hash => location.hash === hash, hash);
            await page.waitForFunction(() => !document.querySelector('.is-menu-open'));
            if (width <= 768) assert.equal(await menu.isVisible(), false, `${width}: destination click closes the panel without leaking menu content`);
            const target = page.locator(hash);
            assert.ok(await target.isVisible());
            assert.ok(Math.abs((await target.boundingBox()).y) < 2, `${width}: real link scroll reaches visible section`);
        }
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `${width}: no overflow`);
        observations.push({ width, inventory, fragmentSuffix: suffix, clickScroll: 'passed' });
        await page.close();
    }
    const editor = await browser.newPage();
    await editor.goto(`${url}/wp-login.php`);
    await editor.locator('#user_login').fill(process.env.NAVIGATION_TEST_USER || 'navigation-proof');
    await editor.locator('#user_pass').fill(process.env.NAVIGATION_TEST_PASSWORD || 'navigation-test-password');
    await editor.locator('#wp-submit').click();
    await editor.waitForURL(/wp-admin/);
    const pages = await (await fetch(`${url}/wp-json/wp/v2/pages?slug=navigation-home`)).json();
    await editor.goto(`${url}/wp-admin/post.php?post=${pages[0].id}&action=edit`);
    await editor.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0);
    const saved = await editor.evaluate(async () => {
        const rows = await wp.apiFetch({ path: '/wp/v2/navigation?context=edit&per_page=100' });
        const flatten = blocks => blocks.flatMap(block => [block, ...flatten(block.innerBlocks || [])]);
        const parts = await wp.apiFetch({ path: '/wp/v2/template-parts?context=edit' });
        const blocks = [...flatten(wp.data.select('core/block-editor').getBlocks()), ...parts.filter(part => part.theme === 'navigation-inventory-proof').flatMap(part => flatten(wp.blocks.parse(part.content.raw)))];
        if (blocks.some(block => block.isValid === false || block.name === 'core/html' || block.name === 'core/freeform')) throw new Error('Invalid/fallback page block');
        const references = new Set(blocks.filter(block => block.name === 'core/navigation').map(block => block.attributes.ref));
        const menus = rows.filter(row => references.has(row.id));
        for (const row of menus) {
            const blocks = wp.blocks.parse(row.content.raw);
            if (blocks.some(block => !block.isValid || block.name !== 'core/navigation-link')) throw new Error('Invalid/non-native menu block');
        }
        const reference = blocks.find(block => block.name === 'core/navigation' && block.attributes.overlayMenu === 'never')?.attributes.ref;
        const menu = menus.find(row => row.id === reference);
        if (!menu) throw new Error('No desktop native menu entity: ' + JSON.stringify(blocks.filter(block => block.name === 'core/navigation').map(block => block.attributes)));
        const items = wp.blocks.parse(menu.content.raw);
        items[0].attributes.label = 'Services edited once';
        await wp.data.resolveSelect('core').getEntityRecord('postType', 'wp_navigation', menu.id);
        wp.data.dispatch('core').editEntityRecord('postType', 'wp_navigation', menu.id, { content: wp.blocks.serialize(items) });
        await wp.data.dispatch('core').saveEditedEntityRecord('postType', 'wp_navigation', menu.id);
        const reloaded = await wp.apiFetch({ path: `/wp/v2/navigation/${menu.id}?context=edit` });
        if (!reloaded.content.raw.includes('Services edited once')) throw new Error('Menu edit did not persist');
        return { id: menu.id, original: menu.content.raw, validMenus: menus.length };
    });
    try {
        for (const route of ['/', '/navigation-other/']) {
            const front = await browser.newPage({ viewport: { width: 1440, height: 900 } });
            await front.goto(`${url}${route}`, { waitUntil: 'networkidle' });
            assert.equal(await front.getByRole('link', { name: 'Services edited once', exact: true }).count(), 1, `${route}: one entity edit reaches frontend`);
            await front.close();
        }
    } finally {
        await editor.evaluate(async saved => {
            wp.data.dispatch('core').editEntityRecord('postType', 'wp_navigation', saved.id, { content: saved.original });
            await wp.data.dispatch('core').saveEditedEntityRecord('postType', 'wp_navigation', saved.id);
            const restored = await wp.apiFetch({ path: `/wp/v2/navigation/${saved.id}?context=edit` });
            if (restored.content.raw !== saved.original) throw new Error('Menu restoration did not persist');
        }, saved);
    }
    const proof = { observations, editOnce: { entity: saved.id, routes: 2, validMenus: saved.validMenus, restored: true } };
    if (process.env.NAVIGATION_TEST_EVIDENCE) await fs.writeFile(process.env.NAVIGATION_TEST_EVIDENCE, JSON.stringify(proof, null, 2));
    console.log(JSON.stringify(proof));
} finally {
    await browser.close();
}
