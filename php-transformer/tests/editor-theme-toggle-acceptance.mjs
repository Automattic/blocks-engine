#!/usr/bin/env node
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { chromium } from '../tools/visual-parity/node_modules/playwright/index.mjs';

const evidence = process.env.THEME_ACCEPTANCE_EVIDENCE_DIR;
const baseUrl = process.env.THEME_ACCEPTANCE_WP_URL;
const postId = Number(process.env.THEME_ACCEPTANCE_POST_ID);
const required = [ evidence, baseUrl, process.env.THEME_ACCEPTANCE_USER, process.env.THEME_ACCEPTANCE_PASSWORD ];
if ( required.some( ( value ) => ! value ) || ! Number.isInteger(postId) ) throw new Error('Theme acceptance environment is incomplete.');
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
    assert.deepEqual(validation.blocks.map((block) => block.name), ['core/group', 'core/heading', 'custom/theme-toggle']);
    assert.ok(validation.blocks.every((block) => block.valid), 'Gutenberg validates the saved selection block after reload');
    assert.equal(validation.blocks.find((block) => block.name === 'custom/theme-toggle').attrs.selectedMode, 'light');
    await writeFile(`${ evidence }/editor-reload-validation.json`, JSON.stringify(validation, null, 2) + '\n');

    await page.evaluate(() => localStorage.removeItem('theme'));
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto(`${ baseUrl }/?page_id=${ postId }`, { waitUntil: 'networkidle' });
    const group = page.getByRole('group', { name: 'Color theme' });
    const mode = (name) => group.getByRole('button', { name });
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
