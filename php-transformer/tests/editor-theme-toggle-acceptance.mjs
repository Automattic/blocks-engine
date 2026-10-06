#!/usr/bin/env node
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { chromium } from '../tools/visual-parity/node_modules/playwright/index.mjs';

const evidence = process.env.THEME_ACCEPTANCE_EVIDENCE_DIR;
const baseUrl = process.env.THEME_ACCEPTANCE_WP_URL;
const postId = Number(process.env.THEME_ACCEPTANCE_POST_ID);
const attributePostId = Number(process.env.THEME_ACCEPTANCE_ATTRIBUTE_POST_ID);
const required = [ evidence, baseUrl, process.env.THEME_ACCEPTANCE_USER, process.env.THEME_ACCEPTANCE_PASSWORD ];
if ( required.some( ( value ) => ! value ) || ! Number.isInteger(postId) || ! Number.isInteger(attributePostId) ) throw new Error('Theme acceptance environment is incomplete.');
const source = JSON.parse(await readFile(`${ evidence }/source-and-page.json`, 'utf8'));
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' });
const page = await context.newPage();
const errors = [];
const saveResponses = [];
page.on('pageerror', (error) => errors.push(error.message));
page.on('response', (response) => {
    const request = response.request();
    const url = new URL(request.url());
    if (request.method() === 'POST' && (url.pathname.endsWith(`/wp/v2/pages/${ postId }`) || url.searchParams.get('rest_route') === `/wp/v2/pages/${ postId }`)) saveResponses.push(response.status());
});
const save = async () => {
    const saved = page.waitForResponse((response) => {
        const url = new URL(response.url());
        return response.request().method() === 'POST' && (url.pathname.endsWith(`/wp/v2/pages/${ postId }`) || url.searchParams.get('rest_route') === `/wp/v2/pages/${ postId }`) && response.ok();
    });
    await page.getByRole('button', { name: /^Save$/ }).click();
    await saved;
    await page.waitForFunction(() => !window.wp.data.select('core/editor').isSavingPost() && !window.wp.data.select('core/editor').isEditedPostDirty());
};
try {
    await page.goto(`${ baseUrl }/wp-login.php`, { waitUntil: 'networkidle' });
    await page.getByLabel('Username or Email Address').fill(process.env.THEME_ACCEPTANCE_USER);
    await page.getByRole('textbox', { name: 'Password' }).fill(process.env.THEME_ACCEPTANCE_PASSWORD);
    await page.getByRole('button', { name: 'Log In' }).click();
    await page.goto(`${ baseUrl }/wp-admin/post.php?post=${ postId }&action=edit`, { waitUntil: 'domcontentloaded' });
    await page.locator('iframe[name="editor-canvas"]').waitFor();
    const canvas = page.frameLocator('iframe[name="editor-canvas"]');
    await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0);
    const editorTree = await page.evaluate(() => {
        const visit = (blocks) => blocks.map((block) => ({ name: block.name, attrs: block.attributes, innerBlocks: visit(block.innerBlocks || []) }));
        return { blocks: visit(window.wp.data.select('core/block-editor').getBlocks()), registered: Boolean(window.wp.blocks.getBlockType('custom/theme-toggle')) };
    });
    await writeFile(`${ evidence }/editor-initial-tree.json`, JSON.stringify(editorTree, null, 2) + '\n');
    await page.screenshot({ path: `${ evidence }/editor-initial.png`, fullPage: true });
    const welcome = page.locator('.components-modal__screen-overlay');
    if (await welcome.isVisible()) { await welcome.getByRole('button', { name: /Close|Get started/ }).first().click(); await welcome.waitFor({ state: 'hidden' }); }
    const editorButtons = canvas.getByRole('button', { name: /^(Light theme|System theme|Dark theme)$/ });
    await editorButtons.first().waitFor();
    assert.equal(await editorButtons.count(), 3);
    assert.deepEqual(await editorButtons.evaluateAll((nodes) => nodes.map((node) => node.getAttribute('aria-label'))), ['Light theme', 'System theme', 'Dark theme']);
    const editorSelection = canvas.getByRole('button', { name: 'Light theme' });
    assert.equal(await editorSelection.getAttribute('id'), 'light-choice');
    assert.equal(await editorSelection.getAttribute('aria-describedby'), 'theme-help');
    assert.equal(await editorSelection.getAttribute('data-choice'), 'light');
    assert.equal(await editorSelection.evaluate((node) => node.style.width), '40px');
    assert.equal(await editorSelection.evaluate((node) => node.style.minWidth), '32px');
    assert.equal(await editorSelection.evaluate((node) => node.style.backgroundColor), 'rgb(18, 52, 86)');
    assert.equal(await editorSelection.evaluate((node) => node.style.borderRadius), '4px');
    assert.equal(await editorSelection.evaluate((node) => node.style.padding), '8px 16px');
    assert.equal(await editorSelection.getAttribute('onclick'), null, 'unsafe source handlers are never copied to the editable block');
    await editorSelection.click();
    await page.waitForFunction(() => {
        const visit = (blocks) => blocks.flatMap((block) => [block, ...visit(block.innerBlocks || [])]);
        return visit(window.wp.data.select('core/block-editor').getBlocks()).find((block) => block.name === 'custom/theme-toggle')?.attributes.selectedMode === 'light';
    });
    await save();
    await writeFile(`${ evidence }/editor-saved.json`, JSON.stringify(await page.evaluate(() => {
        const visit = (blocks) => blocks.flatMap((block) => [block, ...visit(block.innerBlocks || [])]);
        return { content: window.wp.data.select('core/editor').getEditedPostContent(), block: visit(window.wp.data.select('core/block-editor').getBlocks()).find((candidate) => candidate.name === 'custom/theme-toggle') };
    }), null, 2) + '\n');
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.locator('iframe[name="editor-canvas"]').waitFor();
    const validation = await page.evaluate(() => {
        const content = window.wp.data.select('core/editor').getEditedPostContent();
        const blocks = window.wp.blocks.parse(content);
        const visit = (items) => items.flatMap((block) => [{ name: block.name, valid: window.wp.blocks.validateBlock(block)[0], attrs: block.attributes }, ...visit(block.innerBlocks || [])]);
        return { content, blocks: visit(blocks) };
    });
    assert.deepEqual(validation.blocks.map((block) => block.name), ['core/group', 'core/heading', 'core/group', 'custom/theme-toggle', 'core/paragraph']);
    assert.ok(validation.blocks.every((block) => block.valid), 'Gutenberg validates the saved selection block after reload');
    const reloadedThemeBlock = validation.blocks.find((block) => block.name === 'custom/theme-toggle');
    assert.equal(reloadedThemeBlock.attrs.selectedMode, 'light');
    assert.equal(reloadedThemeBlock.attrs.selectionButtons[0].attributes.id, 'light-choice');
    assert.equal(reloadedThemeBlock.attrs.selectionButtons[0].attributes['aria-describedby'], 'theme-help');
    assert.equal(reloadedThemeBlock.attrs.selectionButtons[0].attributes['data-choice'], 'light');
    assert.equal(reloadedThemeBlock.attrs.selectionButtons[0].style.width, '40px');
    assert.equal(reloadedThemeBlock.attrs.selectionButtons[0].style['min-width'], '32px');
    assert.equal(reloadedThemeBlock.attrs.selectionButtons[0].style['background-color'], '#123456');
    assert.equal(reloadedThemeBlock.attrs.selectionButtons[0].style['border-radius'], '4px');
    assert.equal(reloadedThemeBlock.attrs.selectionButtons[0].style['padding-top'], '8px');
    assert.equal(reloadedThemeBlock.attrs.selectionButtons[0].style['padding-right'], '16px');
    assert.equal(reloadedThemeBlock.attrs.selectionButtons[0].style['padding-bottom'], '8px');
    assert.equal(reloadedThemeBlock.attrs.selectionButtons[0].style['padding-left'], '16px');
    await writeFile(`${ evidence }/editor-reload-validation.json`, JSON.stringify(validation, null, 2) + '\n');

    await page.evaluate(() => localStorage.removeItem('theme'));
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto(`${ baseUrl }/?page_id=${ postId }`, { waitUntil: 'networkidle' });
    const group = page.getByRole('group', { name: 'Color theme' });
    const mode = (name) => group.getByRole('button', { name });
    assert.equal(await mode('Light theme').getAttribute('id'), 'light-choice');
    assert.equal(await mode('Light theme').getAttribute('aria-describedby'), 'theme-help');
    assert.equal(await mode('Light theme').getAttribute('data-choice'), 'light');
    assert.equal(await mode('Light theme').evaluate((node) => node.style.width), '40px');
    assert.equal(await mode('Light theme').evaluate((node) => node.style.minWidth), '32px');
    assert.equal(await mode('Light theme').evaluate((node) => node.style.backgroundColor), 'rgb(18, 52, 86)');
    assert.equal(await mode('Light theme').evaluate((node) => node.style.borderRadius), '4px');
    assert.equal(await mode('Light theme').evaluate((node) => node.style.padding), '8px 16px');
    assert.equal(await mode('Light theme').getAttribute('onclick'), null);
    await mode('System theme').click();
    await page.waitForFunction(() => localStorage.getItem('theme') === 'system' && !document.documentElement.classList.contains('dark'));
    assert.equal(await mode('System theme').getAttribute('aria-pressed'), 'true');
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.waitForFunction(() => document.documentElement.classList.contains('dark') && document.documentElement.style.colorScheme === 'dark');
    assert.equal(await mode('System theme').getAttribute('aria-pressed'), 'true', 'OS preference changes do not replace the selected System preference');
    await mode('Light theme').click();
    await page.waitForFunction(() => localStorage.getItem('theme') === 'light' && !document.documentElement.classList.contains('dark'));
    await page.emulateMedia({ colorScheme: 'dark' });
    assert.equal(await page.evaluate(() => document.documentElement.classList.contains('dark')), false, 'explicit Light remains authoritative over OS changes');
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForFunction(() => localStorage.getItem('theme') === 'light' && !document.documentElement.classList.contains('dark'));
    await mode('Dark theme').focus();
    await page.keyboard.press('Enter');
    await page.waitForFunction(() => localStorage.getItem('theme') === 'dark' && document.documentElement.classList.contains('dark'));
    await mode('System theme').focus();
    await page.keyboard.press('Space');
    await page.waitForFunction(() => localStorage.getItem('theme') === 'system');
    await page.emulateMedia({ colorScheme: 'light' });
    await page.waitForFunction(() => !document.documentElement.classList.contains('dark'));
    await page.evaluate(() => localStorage.removeItem('appearance'));
    await page.goto(`${ baseUrl }/?page_id=${ attributePostId }`, { waitUntil: 'networkidle' });
    const attributeGroup = page.getByRole('group', { name: 'Color theme' });
    await page.waitForFunction(() => !document.documentElement.hasAttribute('data-theme'));
    await attributeGroup.getByRole('button', { name: 'System theme' }).click();
    await page.waitForFunction(() => localStorage.getItem('appearance') === 'system' && !document.documentElement.hasAttribute('data-theme'));
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.waitForFunction(() => document.documentElement.getAttribute('data-theme') === 'dark');
    assert.equal(await attributeGroup.getByRole('button', { name: 'System theme' }).getAttribute('aria-pressed'), 'true');
    await page.emulateMedia({ colorScheme: 'light' });
    await page.waitForFunction(() => !document.documentElement.hasAttribute('data-theme'));
    await attributeGroup.getByRole('button', { name: 'Light theme' }).click();
    await page.waitForFunction(() => localStorage.getItem('appearance') === 'light' && !document.documentElement.hasAttribute('data-theme'));
    await page.emulateMedia({ colorScheme: 'dark' });
    assert.equal(await page.locator('html').getAttribute('data-theme'), null, 'explicit light preserves the authored absent-data-theme representation across OS changes');
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForFunction(() => localStorage.getItem('appearance') === 'light' && !document.documentElement.hasAttribute('data-theme'));
    await attributeGroup.getByRole('button', { name: 'Dark theme' }).click();
    await page.waitForFunction(() => localStorage.getItem('appearance') === 'dark' && document.documentElement.getAttribute('data-theme') === 'dark');
    await page.emulateMedia({ colorScheme: 'light' });
    assert.equal(await page.locator('html').getAttribute('data-theme'), 'dark', 'explicit data-theme preference is authoritative over OS changes');
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForFunction(() => localStorage.getItem('appearance') === 'dark' && document.documentElement.getAttribute('data-theme') === 'dark');
    await writeFile(`${ evidence }/root-attribute-interactions.json`, JSON.stringify({
        storage: await page.evaluate(() => localStorage.getItem('appearance')),
        rootAttribute: await page.locator('html').getAttribute('data-theme'),
        pressed: await attributeGroup.getByRole('button').evaluateAll((nodes) => nodes.map((node) => [node.getAttribute('aria-label'), node.getAttribute('aria-pressed')])),
    }, null, 2) + '\n');
    await writeFile(`${ evidence }/browser-interactions.json`, JSON.stringify({
        storage: await page.evaluate(() => localStorage.getItem('theme')),
        rootClass: await page.locator('html').getAttribute('class'),
        colorScheme: await page.locator('html').evaluate((node) => node.style.colorScheme),
        pressed: await group.getByRole('button').evaluateAll((nodes) => nodes.map((node) => [node.getAttribute('aria-label'), node.getAttribute('aria-pressed')])),
        saveResponses,
        errors,
    }, null, 2) + '\n');
    assert.deepEqual(errors, [], 'the editor and frontend have no uncaught browser errors');
    assert.ok(saveResponses.some((status) => status >= 200 && status < 300), 'Gutenberg save reached the page REST endpoint');
    await page.screenshot({ path: `${ evidence }/theme-selection-frontend.png`, fullPage: true });
    console.log('PASS: source selection group, Gutenberg save/reload validation, browser clicks, persistence, system OS updates, and keyboard activation');
} finally {
    await writeFile(`${ evidence }/browser-errors.json`, JSON.stringify(errors, null, 2) + '\n');
    await browser.close();
}
