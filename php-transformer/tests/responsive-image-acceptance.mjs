#!/usr/bin/env node
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import { chromium } from '../tools/visual-parity/node_modules/playwright/index.mjs';

const evidence = process.env.BE_EDITOR_EVIDENCE_DIR;
const base = process.env.BE_EDITOR_WP_URL;
const fixture = JSON.parse(await readFile(`${evidence}/responsive-source-and-pages.json`, 'utf8'));
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const browser = await chromium.launch({ headless: true });
const observations = [];
const errors = [];
const flatten = blocks => blocks.flatMap(block => [block, ...flatten(block.innerBlocks || [])]);
try {
    for (const width of [390, 768, 1440]) for (const deviceScaleFactor of [1, 2]) {
        const context = await browser.newContext({ viewport: { width, height: 1000 }, deviceScaleFactor });
        const pair = {};
        for (const [label, url] of Object.entries({ source: fixture.source_url, before: `${base}/?page_id=${fixture.posts.before}`, after: `${base}/?page_id=${fixture.posts.after}` })) {
            const page = await context.newPage();
            page.on('pageerror', error => errors.push(error.message));
            await page.goto(url, { waitUntil: 'domcontentloaded' });
            const images = page.locator('.media-container img');
            await images.first().waitFor();
            pair[label] = [];
            for (let index = 0; index < 5; index++) {
                const image = images.nth(index);
                await image.scrollIntoViewIfNeeded();
                const state = await image.evaluate(async image => {
                    await image.decode();
                    const box = image.getBoundingClientRect();
                    const canvas = document.createElement('canvas'); canvas.width = canvas.height = 32;
                    canvas.getContext('2d').drawImage(image, 0, 0, 32, 32);
                    return { currentSrc: image.currentSrc, src: image.src, srcset: image.srcset, sizes: image.sizes, natural: [image.naturalWidth, image.naturalHeight], geometry: [box.width, box.height], pixels: Array.from(canvas.getContext('2d').getImageData(0, 0, 32, 32).data), className: image.className };
                });
                state.pixelSha256 = hash(Buffer.from(state.pixels)); delete state.pixels;
                const response = await page.request.get(state.currentSrc); assert.equal(response.status(), 200);
                state.selectedFileSha256 = hash(await response.body());
                const screenshot = await image.screenshot();
                state.screenshotSha256 = hash(screenshot);
                await writeFile(`${evidence}/${label}-${width}-${deviceScaleFactor}-${index}.png`, screenshot);
                pair[label].push(state);
            }
            await page.close();
        }
        for (let index = 0; index < 5; index++) {
            const source = pair.source[index], after = pair.after[index];
            assert.equal(after.selectedFileSha256, source.selectedFileSha256, 'selected authored rendition bytes survive attachment binding');
            assert.equal(after.pixelSha256, source.pixelSha256, 'selected decoded pixels match');
            assert.deepEqual(after.geometry, source.geometry, 'display and density-corrected intrinsic geometry match');
            assert.equal(after.screenshotSha256, source.screenshotSha256, 'actual rendered image pixels match exactly');
            assert.ok(after.currentSrc.includes('/uploads/'), 'selected candidate is a Media Library attachment');
            const binding = fixture.source_bindings.find(item => item.source_url === source.currentSrc);
            assert.ok(binding?.identity, 'existing SSI source-asset identity backs the selected attachment');
            assert.equal(binding.attachment_url, after.currentSrc, 'selected source URL maps to the actual attachment URL');
            assert.equal(binding.sha256, source.selectedFileSha256, 'attachment provenance retains exact source bytes');
        }
        assert.notEqual(pair.before[2].selectedFileSha256, pair.source[2].selectedFileSha256, 'paired baseline demonstrates descriptorless selection loss');
        if (deviceScaleFactor === 2) assert.notEqual(pair.before[1].pixelSha256, pair.source[1].pixelSha256, 'paired baseline demonstrates density pixel loss');
        assert.notDeepEqual(pair.before[3].geometry, pair.source[3].geometry, 'native promotion cannot substitute largest bytes into an authored density family');
        observations.push({ width, deviceScaleFactor, ...pair });
        await context.close();
    }
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Username or Email Address').fill(process.env.BE_EDITOR_USER);
    await page.getByRole('textbox', { name: 'Password' }).fill(process.env.BE_EDITOR_PASSWORD);
    await page.getByRole('button', { name: 'Log In' }).click();
    await page.goto(`${base}/wp-admin/post.php?post=${fixture.posts.after}&action=edit`, { waitUntil: 'domcontentloaded' });
    const canvas = page.frameLocator('iframe[name="editor-canvas"]');
    await canvas.locator('[data-type="custom/responsive-media"]').first().waitFor();
    const welcome = page.locator('.components-modal__screen-overlay');
    if (await welcome.isVisible()) await welcome.getByRole('button', { name: /Close|Get started/ }).first().click();
    const getBlocks = () => page.evaluate(() => {
        const visit = blocks => blocks.flatMap(block => [block, ...visit(block.innerBlocks || [])]);
        return visit(window.wp.data.select('core/block-editor').getBlocks()).filter(block => block.name.endsWith('/responsive-media'));
    });
    const save = async () => {
        await page.getByRole('button', { name: /^Save$/ }).click();
        await page.waitForFunction(() => { const editor = window.wp.data.select('core/editor'); return !editor.isSavingPost() && !editor.isEditedPostDirty() && editor.didPostSaveRequestSucceed(); });
    };
    await canvas.locator('[data-type="custom/responsive-media"]').first().click();
    const original = await getBlocks(); assert.equal(original.length, 5);
    // One real content edit, followed by a save/reopen of all three families.
    await page.evaluate(() => {
        const visit = blocks => blocks.flatMap(block => [block, ...visit(block.innerBlocks || [])]);
        const block = visit(window.wp.data.select('core/block-editor').getBlocks()).find(block => block.name.endsWith('/responsive-media'));
        window.wp.data.dispatch('core/block-editor').updateBlockAttributes(block.clientId, { content: block.attributes.content.replace('Width family', 'Edited width family') });
    });
    await save();
    await page.reload({ waitUntil: 'domcontentloaded' });
    await canvas.locator('[data-type="custom/responsive-media"]').first().waitFor();
    const reopened = await getBlocks();
    assert.deepEqual(reopened.map(block => block.attributes.content), original.map((block, index) => index ? block.attributes.content : block.attributes.content.replace('Width family', 'Edited width family')), 'all authored families survive save/reopen');
    await canvas.locator('[data-type="custom/responsive-media"]').first().click();
    await page.evaluate(() => {
        const visit = blocks => blocks.flatMap(block => [block, ...visit(block.innerBlocks || [])]);
        const block = visit(window.wp.data.select('core/block-editor').getBlocks()).find(block => block.name.endsWith('/responsive-media'));
        window.wp.data.dispatch('core/block-editor').selectBlock(block.clientId);
    });
    await page.screenshot({ path: `${evidence}/responsive-before-replace.png`, fullPage: true });
    await writeFile(`${evidence}/responsive-toolbar.json`, JSON.stringify(await page.evaluate(() => ({ selected: window.wp.data.select('core/block-editor').getSelectedBlockClientId(), buttons: Array.from(document.querySelectorAll('button')).map(button => [button.textContent, button.getAttribute('aria-label')]) })), null, 2));
    await page.getByRole('button', { name: 'Replace image', exact: true }).click();
    const modal = page.locator('.media-modal'); await modal.waitFor();
    await modal.getByText('Media Library', { exact: true }).click();
    await modal.locator('[aria-label*="BE editor second"]').click();
    await modal.getByRole('button', { name: 'Select', exact: true }).click();
    await page.waitForFunction(id => window.wp.data.select('core/editor').getEditedPostContent().includes('wp-image-' + id), fixture.replacement_id);
    const replacement = (await getBlocks())[0];
    assert.ok(!replacement.attributes.content.includes('srcset=') && !replacement.attributes.content.includes('sizes='), 'replacement retires the old family');
    await save(); await page.reload({ waitUntil: 'domcontentloaded' });
    await canvas.locator('[data-type="custom/responsive-media"]').first().waitFor();
    const final = await getBlocks();
    assert.equal(final[0].attributes.content, replacement.attributes.content, 'Media Library replacement survives reopen');
    const validation = await page.evaluate(() => {
        const visit = blocks => blocks.flatMap(block => [{ name: block.name, valid: window.wp.blocks.validateBlock(block)[0], registered: Boolean(window.wp.blocks.getBlockType(block.name)) }, ...visit(block.innerBlocks || [])]);
        return visit(window.wp.blocks.parse(window.wp.data.select('core/editor').getEditedPostContent()));
    });
    assert.ok(validation.every(block => block.valid && block.registered));
    await page.goto(`${base}/?page_id=${fixture.posts.after}`, { waitUntil: 'domcontentloaded' });
    const replacedImage = page.locator('.media-container img').first();
    const replacedState = await replacedImage.evaluate(async image => { await image.decode(); const box = image.getBoundingClientRect(); return { currentSrc: image.currentSrc, natural: [image.naturalWidth, image.naturalHeight], geometry: [box.width, box.height] }; });
    assert.ok(replacedState.currentSrc.includes('be-editor-second'), 'frontend actually selects the replacement');
    assert.deepEqual(replacedState.geometry, [570, 427.5], 'replacement derives display aspect ratio from its actual file');
    await writeFile(`${evidence}/responsive-editor.json`, JSON.stringify({ original, reopened, replacement, final, validation, replacedState }, null, 2));
    assert.deepEqual(errors, [], 'source, destination and editor have no JavaScript runtime errors');
    assert.ok(fixture.provenance.some(item => item.url.includes('large-original.png') && item.full_url.includes('scaled')), 'the large-source proof exercises actual Core upload scaling');
    console.log(JSON.stringify({ ok: true, viewportProfiles: observations.length, imageComparisons: observations.length * 5, binding: fixture.binding, replacedState }));
} finally {
    await writeFile(`${evidence}/responsive-observations.json`, JSON.stringify({ observations, errors }, null, 2));
    await browser.close();
}
