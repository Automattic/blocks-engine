import assert from 'node:assert/strict';
import { chromium } from '../tools/visual-parity/node_modules/playwright/index.mjs';

const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    page.setDefaultTimeout(45000);
    const base = process.env.BE_EDITOR_WP_URL;
    await page.goto(`${base}/wp-login.php`);
    await page.locator('#user_login').fill(process.env.BE_EDITOR_USER);
    await page.locator('#user_pass').fill(process.env.BE_EDITOR_PASSWORD);
    await page.locator('#wp-submit').click();
    await page.waitForURL(/wp-admin/);
    await page.goto(`${base}/wp-admin/post.php?post=${process.env.BE_EDITOR_POST_ID}&action=edit`);
    const read = async () => {
        await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getClientIdsWithDescendants().length > 0);
        return page.evaluate(() => {
            const store = wp.data.select('core/block-editor');
            const blocks = store.getClientIdsWithDescendants().map(id => store.getBlock(id));
            const carousel = blocks.find(block => block.name.endsWith('/authored-carousel'));
            return { valid: blocks.every(block => block.isValid), previous: carousel.attributes.previousControlOrientation, next: carousel.attributes.nextControlOrientation };
        });
    };
    assert.deepEqual(await read(), { valid: true, previous: [], next: { flipHorizontal: true, flipVertical: false, rotation: 0 } });
    await page.evaluate(() => {
        const preferences = wp.data.dispatch('core/preferences');
        for (const key of ['welcomeGuide', 'welcomeGuidePage', 'welcomeGuideTemplate', 'welcomeGuideStyles']) preferences.set('core/edit-post', key, false);
        const store = wp.data.select('core/block-editor');
        const id = store.getClientIdsWithDescendants().find(id => store.getBlockName(id).endsWith('/authored-carousel'));
        wp.data.dispatch('core/block-editor').selectBlock(id);
        wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/block');
    });
    const arrows = page.locator('.block-editor-block-inspector button').filter({ hasText: /^Arrows$/ });
    await arrows.waitFor();
    // The editor can open its welcome guide after the block inspector mounts.
    await page.waitForTimeout(1000);
    for (const selector of ['.components-modal__frame button[aria-label="Close"]', '.components-guide__finish-button']) {
        const close = page.locator(selector);
        if (await close.count()) await close.first().click();
    }
    await arrows.click();
    await page.getByLabel('Next arrow: flip horizontal').click();
    await page.getByLabel('Previous arrow: flip vertical').click();
    await page.getByLabel('Previous arrow: rotation').first().fill('45');
    const expected = { valid: true, previous: { flipHorizontal: false, flipVertical: true, rotation: 45 }, next: { flipHorizontal: false, flipVertical: false, rotation: 0 } };
    assert.deepEqual(await read(), expected);
    await page.evaluate(() => wp.data.dispatch('core/editor').savePost());
    await page.waitForFunction(() => { const store = wp.data.select('core/editor'); return !store.isSavingPost() && !store.isEditedPostDirty() && store.didPostSaveRequestSucceed(); });
    await page.reload();
    assert.deepEqual(await read(), expected);
    await page.goto(`${base}/?page_id=${process.env.BE_EDITOR_POST_ID}`);
    const controls = await page.evaluate(() => {
        const root = document.querySelector('.blocks-engine-authored-carousel');
        const stage = root.querySelector('.blocks-engine-authored-carousel__viewport').getBoundingClientRect();
        return ['previous', 'next'].map(direction => {
            const button = root.querySelector(`[data-carousel-${direction}]`);
            const style = getComputedStyle(button), box = button.getBoundingClientRect();
            return { scale: style.scale, rotation: style.rotate, transform: style.transform, centerOffset: box.y + box.height / 2 - stage.y - stage.height / 2 };
        });
    });
    assert.equal(controls[0].scale, '1 -1');
    assert.equal(controls[0].rotation, '45deg');
    assert.equal(controls[1].transform, 'none');
    assert.equal(controls[1].rotation, 'none');
    assert.ok(controls.every(control => Math.abs(control.centerOffset) < 1));
    const custom = await page.evaluate(() => {
        const style = document.createElement('style');
        style.textContent = 'body .blocks-engine-authored-carousel [data-carousel-next]{rotate:90deg!important;scale:-1 1!important}';
        document.head.append(style);
        const arrow = getComputedStyle(document.querySelector('[data-carousel-next]'));
        return { rotation: arrow.rotate, scale: arrow.scale };
    });
    assert.deepEqual(custom, { rotation: '90deg', scale: '-1 1' });
    console.log(JSON.stringify({ ok: true, reloaded: expected, controls, custom }));
} finally {
    await browser.close();
}
