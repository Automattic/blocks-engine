import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../tools/visual-parity/node_modules/playwright/index.mjs');

const root = resolve(new URL('..', import.meta.url).pathname);
const site = process.env.BE_CHOICE_WP_PATH;
const base = process.env.BE_CHOICE_WP_URL;
const evidence = process.env.BE_CHOICE_EVIDENCE;
assert.ok(site && base && evidence, 'Set isolated WordPress path, URL and evidence directory');
await mkdir(evidence, { recursive: true });
const compiled = JSON.parse(execFileSync('php', [join(root, 'tests/build-inactive-choice-form-acceptance.php')], { encoding: 'utf8', maxBuffer: 20 * 1024 * 1024 }));
await writeFile(join(evidence, 'compiled.json'), JSON.stringify(compiled, null, 2));
const plugin = join(site, 'wp-content/plugins/inactive-choice-proof');
await mkdir(plugin, { recursive: true });
const php = value => {
    if (Array.isArray(value)) return `array(${value.map(php).join(',')})`;
    if (value && typeof value === 'object') return `array(${Object.entries(value).map(([key, child]) => `${php(key)}=>${php(child)}`).join(',')})`;
    return `'${String(value).replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;
};
for (const block of compiled.source_reports.companion_plugin_payload.blocks) {
    const path = join(plugin, block.block_json.name.split('/')[1]);
    await mkdir(path, { recursive: true });
    await writeFile(join(path, 'block.json'), JSON.stringify(block.block_json));
    for (const [name, content] of Object.entries(block.assets || {})) await writeFile(join(path, name), content);
    if (block.view_js) await writeFile(join(path, 'view.js'), block.view_js);
    for (const [name, dependencies] of Object.entries(block.script_dependencies || {})) {
        await writeFile(join(path, name.replace(/\.js$/, '.asset.php')), `<?php return ${php({ dependencies, version: 'inactive-choice-proof' })};`);
    }
}
await writeFile(join(plugin, 'style.css'), (compiled.assets || []).filter(asset => asset.kind === 'css').map(asset => asset.content || '').join('\n'));
await writeFile(join(plugin, 'inactive-choice-proof.php'), `<?php /* Plugin Name: Inactive Choice Proof */
add_action('init', static function () { foreach (glob(__DIR__ . '/*/block.json') as $file) register_block_type(dirname($file)); });
add_action('wp_enqueue_scripts', static function () { wp_enqueue_style('inactive-choice-proof', plugins_url('style.css', __FILE__)); });`);
await writeFile(join(site, 'inactive-choice-content.json'), JSON.stringify({ content: compiled.serialized_blocks }));
await writeFile(join(site, 'install-inactive-choice.php'), `<?php
$input = json_decode(file_get_contents(__DIR__ . '/inactive-choice-content.json'), true);
$id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inactive Choice Proof', 'post_content' => wp_slash($input['content'])), true);
if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
echo $id;`);
const cli = args => execFileSync('studio', ['wp', `--path=${site}`, ...args], { encoding: 'utf8', maxBuffer: 20 * 1024 * 1024 });
cli(['plugin', 'activate', 'inactive-choice-proof']);
const postId = cli(['eval-file', '/wordpress/install-inactive-choice.php']).trim();
assert.match(postId, /^\d+$/);
const browser = await chromium.launch({ headless: true });
const results = {};
try {
    const page = await browser.newPage();
    const checkVisitors = async expectedLabel => {
        await page.goto(`${base}/?page_id=${postId}`, { waitUntil: 'networkidle' });
        const controls = page.locator('form.wp-block-group input, form.wp-block-group button');
        assert.equal(await controls.count(), 6);
        for (const control of await controls.all()) assert.equal(await control.isDisabled(), true);
        assert.equal(await page.getByText('This form is not accepting responses yet.', { exact: true }).count(), 2);
        assert.equal(await page.locator('#answer-yes').isChecked(), true);
        assert.equal(await page.locator('#answer-no').isChecked(), false);
        assert.equal(await page.locator('#topic-a').isChecked(), false);
        assert.equal(await page.locator('#topic-b').isChecked(), true);
        assert.ok((await page.locator('label').allTextContents()).includes(expectedLabel));
        await page.locator('#answer-no').click({ force: true });
        await page.locator('#topic-a').click({ force: true });
        assert.equal(await page.locator('#answer-no').isChecked(), false);
        assert.equal(await page.locator('#topic-a').isChecked(), false);
        await page.evaluate(() => {
            window.choiceSubmits = 0;
            document.querySelectorAll('form.wp-block-group').forEach(form => form.addEventListener('submit', event => { window.choiceSubmits++; event.preventDefault(); }));
        });
        await page.locator('form.wp-block-group button').click({ force: true });
        for (let i = 0; i < 2; i++) {
            await page.evaluate(index => {
                const form = document.querySelectorAll('form.wp-block-group')[index];
                const before = document.createElement('span');
                const after = document.createElement('span');
                before.tabIndex = after.tabIndex = 0;
                after.id = 'after-choice-' + index;
                form.before(before);
                form.after(after);
                before.focus();
            }, i);
            await page.keyboard.press('Tab');
            assert.equal(await page.evaluate(() => document.activeElement.id), 'after-choice-' + i);
            await page.keyboard.press('Enter');
            await page.keyboard.press('Space');
        }
        assert.equal(await page.evaluate(() => window.choiceSubmits), 0);
        return { controls: 6, disabled: true, notices: 2, mouseAndKeyboardCannotSubmit: true };
    };
    results.before = await checkVisitors('Yes');
    await page.goto(`${base}/wp-login.php`);
    await page.locator('#user_login').fill(process.env.BE_CHOICE_USER || 'admin');
    await page.locator('#user_pass').fill(process.env.BE_CHOICE_PASSWORD);
    await page.locator('#wp-submit').click();
    await page.waitForURL(/wp-admin/);
    await page.goto(`${base}/wp-admin/post.php?post=${postId}&action=edit`, { waitUntil: 'domcontentloaded' });
    const readBlocks = async () => {
        await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0);
        return page.evaluate(() => {
            const flat = blocks => blocks.flatMap(block => [block, ...flat(block.innerBlocks || [])]);
            return flat(wp.data.select('core/block-editor').getBlocks()).map(block => ({ name: block.name, attrs: block.attributes, valid: block.isValid && wp.blocks.validateBlock(block)[0] }));
        });
    };
    const checkBlocks = blocks => {
        assert.ok(blocks.every(block => block.valid), JSON.stringify(blocks.filter(block => !block.valid)));
        const controls = blocks.filter(block => /\/authored-(input|button)$/.test(block.name));
        assert.equal(controls.length, 6);
        assert.ok(controls.every(block => block.attrs.disabled === true));
    };
    results.editorBefore = await readBlocks();
    checkBlocks(results.editorBefore);
    await page.evaluate(async () => {
        const flat = blocks => blocks.flatMap(block => [block, ...flat(block.innerBlocks || [])]);
        const block = flat(wp.data.select('core/block-editor').getBlocks()).find(block => block.attributes.id === 'answer-yes');
        wp.data.dispatch('core/block-editor').updateBlockAttributes(block.clientId, { label: 'Edited yes', labelMarkup: 'Edited yes' });
        await wp.data.dispatch('core/editor').savePost();
    });
    await page.waitForFunction(() => { const store = wp.data.select('core/editor'); return !store.isSavingPost() && !store.isEditedPostDirty() && store.didPostSaveRequestSucceed(); });
    await page.reload({ waitUntil: 'domcontentloaded' });
    results.editorAfter = await readBlocks();
    checkBlocks(results.editorAfter);
    assert.equal(results.editorAfter.find(block => block.attrs.id === 'answer-yes').attrs.label, 'Edited yes');
    results.after = await checkVisitors('Edited yes');
    console.log('PASS: real WordPress disabled choice forms, mouse/keyboard behavior, editable labels and editor save/reopen');
} finally {
    await writeFile(join(evidence, 'editor-and-visitor-proof.json'), JSON.stringify(results, null, 2));
    await browser.close();
}
