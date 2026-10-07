#!/usr/bin/env node
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { chromium } from '../tools/visual-parity/node_modules/playwright/index.mjs';

const evidence = process.env.NICK_THEME_EVIDENCE_DIR;
const baseUrl = process.env.THEME_ACCEPTANCE_WP_URL;
const postId = Number(process.env.NICK_THEME_POST_ID);
const blockName = process.env.NICK_THEME_BLOCK_NAME;
const user = process.env.THEME_ACCEPTANCE_USER;
const password = process.env.THEME_ACCEPTANCE_PASSWORD;
if (!evidence || !baseUrl || !user || !password || !Number.isInteger(postId) || !blockName) throw new Error('Nick theme acceptance environment is incomplete.');
const sourceEvidence = JSON.parse(await readFile(`${ evidence }/nick-live-browser-evidence.json`, 'utf8'));
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' });
const page = await context.newPage();
const errors = [];
const saveResponses = [];
page.on('pageerror', (error) => errors.push(error.message));
page.on('response', (response) => {
    const url = new URL(response.url());
    if (response.request().method() === 'POST' && (url.pathname.endsWith(`/wp/v2/pages/${ postId }`) || url.searchParams.get('rest_route') === `/wp/v2/pages/${ postId }`)) saveResponses.push(response.status());
});
const blockTree = () => page.evaluate(() => {
    const visit = (blocks) => blocks.flatMap((block) => [block, ...visit(block.innerBlocks || [])]);
    return visit(window.wp.data.select('core/block-editor').getBlocks());
});
const editorMode = (label) => page.frameLocator('iframe[name="editor-canvas"]').getByRole('button', { name: label, exact: true });
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
    await page.getByLabel('Username or Email Address').fill(user);
    await page.getByRole('textbox', { name: 'Password' }).fill(password);
    await page.getByRole('button', { name: 'Log In' }).click();
    await page.goto(`${ baseUrl }/wp-admin/post.php?post=${ postId }&action=edit`, { waitUntil: 'domcontentloaded' });
    await page.locator('iframe[name="editor-canvas"]').waitFor();
    await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0);
    const initial = await blockTree();
    const block = initial.find((candidate) => blockName === candidate.name);
    assert.ok(block, 'SSI imported the unchanged live Nick control as the canonical editable theme block');
    assert.equal(block.attributes.defaultTheme, 'system');
    assert.equal(block.attributes.selectedMode, 'system');
    assert.equal(block.attributes.storageKey, 'theme');
    assert.equal(block.attributes.groupClassName, 'flex transition-opacity duration-200 opacity-100');
    assert.deepEqual(block.attributes.selectionButtons.map((button) => [button.ariaLabel, button.iconSemantic]), [
        ['Light theme', 'sun'], ['System theme', 'monitor'], ['Dark theme', 'moon'],
    ]);
    assert.deepEqual(await page.frameLocator('iframe[name="editor-canvas"]').getByRole('button', { name: /^(Light|System|Dark) theme$/ }).evaluateAll((nodes) => nodes.map((node) => node.getAttribute('aria-label'))), ['Light theme', 'System theme', 'Dark theme']);
    await editorMode('Light theme').focus();
    await page.keyboard.press('Enter');
    await page.waitForFunction((blockType) => {
        const visit = (blocks) => blocks.flatMap((block) => [block, ...visit(block.innerBlocks || [])]);
        return visit(window.wp.data.select('core/block-editor').getBlocks()).find((candidate) => candidate.name === blockType)?.attributes.selectedMode === 'light';
    }, blockName);
    await save();
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.locator('iframe[name="editor-canvas"]').waitFor();
    const reloaded = await page.evaluate(() => {
        const content = window.wp.data.select('core/editor').getEditedPostContent();
        const blocks = window.wp.blocks.parse(content);
        const visit = (items) => items.flatMap((item) => [{ name: item.name, valid: window.wp.blocks.validateBlock(item)[0], attrs: item.attributes }, ...visit(item.innerBlocks || [])]);
        return { content, blocks: visit(blocks) };
    });
    const savedBlock = reloaded.blocks.find((candidate) => blockName === candidate.name);
    assert.ok(savedBlock?.valid, 'Gutenberg validates the SSI-imported custom theme block after REST save and editor reload');
    assert.equal(savedBlock.attrs.defaultTheme, 'system');
    assert.equal(savedBlock.attrs.selectedMode, 'light');
    await writeFile(`${ evidence }/nick-editor-roundtrip.json`, JSON.stringify({ initial: block, saved: savedBlock, block_valid: savedBlock.valid }, null, 2) + '\n');

    await page.evaluate(() => localStorage.removeItem('theme'));
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto(`${ baseUrl }/?page_id=${ postId }`, { waitUntil: 'networkidle' });
    const group = page.getByRole('button', { name: 'Light theme', exact: true }).locator('xpath=..');
    const mode = (label) => group.getByRole('button', { name: label, exact: true });
    assert.equal(await group.count(), 1, 'the saved source buttons retain one shared direct-control wrapper');
    assert.equal(await mode('Light theme').getAttribute('class'), sourceEvidence.unchanged_source_controls[0].markup.match(/class="([^"]+)"/)?.[1].replaceAll('&amp;', '&'));
    assert.equal(await mode('Light theme').locator('svg').getAttribute('class'), sourceEvidence.unchanged_source_controls[0].icon_class);

    await mode('System theme').click();
    await page.waitForFunction(() => localStorage.getItem('theme') === 'system' && document.documentElement.classList.contains('light'));
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.waitForFunction(() => localStorage.getItem('theme') === 'system' && document.documentElement.classList.contains('dark'));
    await mode('Light theme').click();
    await page.waitForFunction(() => localStorage.getItem('theme') === 'light' && document.documentElement.classList.contains('light'));
    await page.emulateMedia({ colorScheme: 'dark' });
    assert.equal(await page.evaluate(() => document.documentElement.classList.contains('light')), true, 'explicit Light remains authoritative over an OS change');
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForFunction(() => localStorage.getItem('theme') === 'light' && document.documentElement.classList.contains('light'));
    await mode('Dark theme').focus();
    await page.keyboard.press('Enter');
    await page.waitForFunction(() => localStorage.getItem('theme') === 'dark' && document.documentElement.classList.contains('dark'));
    await page.emulateMedia({ colorScheme: 'light' });
    assert.equal(await page.evaluate(() => document.documentElement.classList.contains('dark')), true, 'explicit Dark remains authoritative over an OS change');
    await mode('System theme').click();
    await page.waitForFunction(() => localStorage.getItem('theme') === 'system');
    await page.emulateMedia({ colorScheme: 'light' });
    await page.waitForFunction(() => localStorage.getItem('theme') === 'system' && document.documentElement.classList.contains('light'));
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.waitForFunction(() => localStorage.getItem('theme') === 'system' && document.documentElement.classList.contains('dark'));
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForFunction(() => localStorage.getItem('theme') === 'system' && document.documentElement.classList.contains('dark'));

    const result = {
        source_url: sourceEvidence.source_url,
        runtime_asset: sourceEvidence.runtime_asset,
        source_controls: sourceEvidence.unchanged_source_controls.map(({ accessible_name, icon_class }) => ({ accessible_name, icon_class })),
        final_preference: await page.evaluate(() => localStorage.getItem('theme')),
        final_root_class: await page.locator('html').getAttribute('class'),
        save_responses: saveResponses,
        browser_errors: errors,
    };
    assert.deepEqual(errors, [], 'the SSI-imported companion has no uncaught frontend/editor errors');
    assert.ok(saveResponses.some((status) => status >= 200 && status < 300), 'Gutenberg saved the imported Nick source page through REST');
    await writeFile(`${ evidence }/nick-ssi-browser-result.json`, JSON.stringify(result, null, 2) + '\n');
    await page.screenshot({ path: `${ evidence }/nick-ssi-frontend.png`, fullPage: true });
    console.log('PASS: SSI-imported Nick control survives Gutenberg edit/save/reload and operates with storage, OS, explicit preference, and keyboard behavior');
} finally {
    await writeFile(`${ evidence }/nick-browser-errors.json`, JSON.stringify(errors, null, 2) + '\n');
    await browser.close();
}
