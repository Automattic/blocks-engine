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
        if (process.env.NAVIGATION_OWNERSHIP_TEST) {
            const source = await browser.newPage({ viewport: { width, height: 900 } });
            const theme = process.env.NAVIGATION_LIST_PANEL_TEST ? 'navigation-list-panel-proof' : 'navigation-ownership-proof';
            await source.goto(`${url}/wp-content/themes/${theme}/source-proof.html`, { waitUntil: 'load' });
            const sourceControl = source.locator('header [role="button"]:visible');
            assert.equal(await sourceControl.count(), width <= 768 ? 1 : 0);
            if (width <= 768) {
                const original = await sourceControl.boundingBox();
                const native = await opener.boundingBox();
                for (const property of ['x', 'y', 'width', 'height']) assert.ok(Math.abs(original[property]-native[property])<0.05, `${width}: source-control ${property}: ${JSON.stringify({original,native})}`);
                const clip = box => ({ x:Math.floor(box.x)-2,y:Math.floor(box.y)-2,width:Math.ceil(box.width)+4,height:Math.ceil(box.height)+4 });
                assert.deepEqual(await page.screenshot({clip:clip(native)}), await source.screenshot({clip:clip(original)}), `${width}: source opener crop has zero differing pixels`);
                assert.equal(await opener.evaluate(e=>getComputedStyle(e.closest('nav')).marginBottom),'0px', `${width}: list margin does not belong to DIV opener`);
            }
            const originalList = await source.locator('ul#placed-menu').boundingBox();
            const nativeList = await page.locator('nav.wp-block-navigation#placed-menu').boundingBox();
            // Placement is relative to the authored region; body section
            // layout is an independent visual contract, not this list box.
            originalList.y -= (await source.locator('.placement-region').boundingBox()).y;
            nativeList.y -= (await page.locator('.placement-region').boundingBox()).y;
            for (const property of ['x', 'y', 'width', 'height']) assert.ok(Math.abs(originalList[property]-nativeList[property])<0.05, `${width}: genuine floated list ${property}: ${JSON.stringify({originalList,nativeList})}`);
            assert.equal(await page.locator('nav.wp-block-navigation#placed-menu').evaluate(e=>getComputedStyle(e).marginBottom), width<600?'13px':'23px', `${width}: genuine list keeps conditioned authored margin`);
            await source.close();
        }
        if (width <= 768) {
            if (process.env.NAVIGATION_OPENER_TEST) {
                const expected = width < 600 ? {size:24, margin:'16px', transform:'matrix(1.2, 0, 0, 1.2, 0, 0)'} : {size:30.8, margin:'22px', transform:'matrix(1.4, 0, 0, 1.4, 0, 0)'};
                const actual = await opener.evaluate(element => ({box:element.getBoundingClientRect().toJSON(),margin:getComputedStyle(element).marginLeft,transform:getComputedStyle(element).transform,color:getComputedStyle(element).color,svg:element.querySelector('svg').outerHTML,path:element.querySelector('path')?.getAttribute('d')}));
                assert.ok(Math.abs(actual.box.width-expected.size)<0.05 && Math.abs(actual.box.height-expected.size)<0.05, `${width}: intrinsic source SVG and conditioned transform own control geometry: ${JSON.stringify(actual)}`);
                if (actual.margin !== expected.margin) {
                    actual.rules = await opener.evaluate(element => {
                        const rules=[];
                        const walk=(list,conditions=[])=>{for(const rule of list){if(rule.selectorText){try{if(element.matches(rule.selectorText)&&/margin|transform/.test(rule.style.cssText))rules.push({selector:rule.selectorText,css:rule.style.cssText,conditions});}catch{}}else if(rule.cssRules)walk(rule.cssRules,[...conditions,rule.conditionText||'']);}};
                        for(const sheet of document.styleSheets){try{walk(sheet.cssRules);}catch{}}
                        return {classes:element.className,host:element.closest('nav')?.className,rules};
                    });
                }
                assert.equal(actual.margin, expected.margin, `${width}: source margin correspondence: ${JSON.stringify(actual)}`);
                assert.equal(actual.transform, expected.transform);
                assert.equal(actual.color, 'rgb(28, 45, 62)');
                assert.equal(actual.path, 'M2 3h16v2H2zM2 9h16v2H2zM2 15h16v2H2z', 'original source artwork replaces only Core generated icon');
                await opener.focus();
                await page.keyboard.press('Enter');
                await page.waitForFunction(()=>document.querySelector('header .is-menu-open'));
                await page.keyboard.press('Escape');
                await page.waitForFunction(()=>!document.querySelector('header .is-menu-open'));
            }
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
        const themes = await wp.apiFetch({ path: '/wp/v2/themes?status=active' });
        const blocks = [...flatten(wp.data.select('core/block-editor').getBlocks()), ...parts.filter(part => part.theme === themes[0].stylesheet).flatMap(part => flatten(wp.blocks.parse(part.content.raw)))];
        if (blocks.some(block => block.isValid === false || block.name === 'core/html' || block.name === 'core/freeform')) throw new Error('Invalid/fallback page block');
        const references = new Set(blocks.filter(block => block.name === 'core/navigation').map(block => block.attributes.ref));
        const menus = rows.filter(row => references.has(row.id));
        for (const row of menus) {
            const blocks = wp.blocks.parse(row.content.raw);
            if (blocks.some(block => !block.isValid || block.name !== 'core/navigation-link')) throw new Error('Invalid/non-native menu block');
        }
        const reference = blocks.find(block => block.name === 'core/navigation' && block.attributes.overlayMenu === 'never' && menus.some(menu => menu.id === block.attributes.ref && wp.blocks.parse(menu.content.raw).some(item => item.attributes.label === 'Services')))?.attributes.ref;
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
