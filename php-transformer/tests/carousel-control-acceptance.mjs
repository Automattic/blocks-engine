import assert from 'node:assert/strict';
import { writeFile } from 'node:fs/promises';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../tools/visual-parity/node_modules/playwright/index.mjs');
const browser = await chromium.launch({ headless: true });
const evidence = { initial: null, reloaded: null };
try {
    const page = await browser.newPage();
    const base = process.env.BE_EDITOR_WP_URL;
    await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    await page.locator('#user_login').fill(process.env.BE_EDITOR_USER);
    await page.locator('#user_pass').fill(process.env.BE_EDITOR_PASSWORD);
    await page.locator('#wp-submit').click();
    await page.waitForURL(/wp-admin/);
    await page.goto(`${base}/wp-admin/post.php?post=${process.env.BE_EDITOR_POST_ID}&action=edit`, { waitUntil: 'domcontentloaded' });
    const read = async () => {
        await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0);
        return page.evaluate(() => wp.data.select('core/block-editor').getBlocks().map(block => {
            const controls = markup => {
                const dom = new DOMParser().parseFromString(markup, 'text/html');
                return [...dom.querySelectorAll('[data-carousel-previous],[data-carousel-next]')].map(button => ({ html: button.innerHTML, text: button.textContent, name: button.getAttribute('aria-label'), icons: button.querySelectorAll('svg path').length }));
            };
            return { name: block.name, case: block.attributes.ariaLabel, valid: wp.blocks.validateBlock(block)[0] && block.isValid,
                php: controls(block.originalContent), js: controls(wp.blocks.getSaveContent(block.name, block.attributes, block.innerBlocks)) };
        }));
    };
    const check = blocks => {
        assert.deepEqual(blocks.map(block => block.case), ['whitespace', 'artwork-and-label', 'empty', 'zero-label']);
        assert.ok(blocks.every(block => block.valid), 'every PHP-emitted carousel must pass real Gutenberg validateBlock');
        for (const block of blocks) {
            assert.deepEqual(block.php, block.js, `${block.case}: PHP and native save control content agree`);
            assert.deepEqual(block.js.map(button => button.name), ['Previous slide', 'Next slide']);
        }
        for (const name of ['whitespace', 'empty']) assert.ok(blocks.find(block => block.case === name).js.every(button => button.html === ''), 'blank source artwork adds no invented visible label');
        assert.deepEqual(blocks[1].js.map(button => [button.text, button.icons]), [['Back', 1], ['Forward', 1]]);
        assert.deepEqual(blocks[3].js.map(button => button.text), ['0', '0']);
    };
    evidence.initial = await read();
    check(evidence.initial);
    await page.evaluate(async () => {
        const block = wp.data.select('core/block-editor').getBlocks()[0];
        wp.data.dispatch('core/block-editor').updateBlockAttributes(block.clientId, { wrap: false });
        await wp.data.dispatch('core/editor').savePost();
    });
    await page.waitForFunction(() => { const store = wp.data.select('core/editor'); return !store.isSavingPost() && !store.isEditedPostDirty() && store.didPostSaveRequestSucceed(); });
    await page.reload({ waitUntil: 'domcontentloaded' });
    evidence.reloaded = await read();
    check(evidence.reloaded);
    assert.equal(await page.evaluate(() => wp.data.select('core/block-editor').getBlocks()[0].attributes.wrap), false);
    console.log(JSON.stringify({ ok: true, ...evidence }));
} finally {
    if (process.env.BE_EDITOR_EVIDENCE_DIR) await writeFile(`${process.env.BE_EDITOR_EVIDENCE_DIR}/${process.env.PROOF_PREFIX || ''}neutral-controls.json`, JSON.stringify(evidence, null, 2));
    await browser.close();
}
