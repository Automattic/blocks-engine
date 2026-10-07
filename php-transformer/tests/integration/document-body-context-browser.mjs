import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const routes = JSON.parse(readFileSync(process.env.BODY_CONTEXT_ROUTES, 'utf8'));
const base = process.env.BODY_CONTEXT_WP_URL;
const rows = [];
const failures = [];
const browser = await chromium.launch({ headless: true });
const measure = () => ({
    viewport: { width: innerWidth, height: innerHeight },
    runtimeClasses: document.body.className,
    body: { paddingTop: getComputedStyle(document.body).paddingTop, background: getComputedStyle(document.body).backgroundColor, color: getComputedStyle(document.body).color, mode: document.body.dataset.mode, id: document.body.id, emptyState: document.body.getAttribute('data-empty'), documentState: document.documentElement.dataset.document },
    frames: ['header-frame', 'hero', 'content-frame', 'same-element', 'footer-frame'].map(id => {
        const node = document.getElementById(id) || document.querySelector(`.blocks-engine-editor-anchor-${id}`);
        if (!node) return { id, missing: true };
        const rect = node.getBoundingClientRect();
        const css = getComputedStyle(node);
        return { id, x: rect.x, width: rect.width, height: rect.height, marginLeft: css.marginLeft, marginRight: css.marginRight, paddingLeft: css.paddingLeft, paddingRight: css.paddingRight, paddingTop: css.paddingTop, background: css.backgroundColor };
    }),
    scrollWidth: document.documentElement.scrollWidth,
});
const compare = (actual, source, label, shared = true) => {
    const checks = [
        () => assert.deepEqual(actual.body, source.body, `${label}: body subject and route state`),
        () => assert.deepEqual(actual.frames.filter(row => shared || !['header-frame', 'footer-frame'].includes(row.id)), source.frames.filter(row => shared || !['header-frame', 'footer-frame'].includes(row.id)), `${label}: ancestor hero/gutters and actual subjects`),
    ];
    if (shared) checks.push(() => assert.equal(actual.scrollWidth, source.scrollWidth, `${label}: overflow`));
    for (const check of checks) try { check(); } catch (error) { failures.push(error.message); }
};
try {
    const frontend = await browser.newPage();
    const sourcePage = await browser.newPage();
    for (const route of routes) for (const width of [390, 768, 1440]) {
        await frontend.setViewportSize({ width, height: 900 });
        await sourcePage.setViewportSize({ width, height: 900 });
        await sourcePage.setContent(route.source, { waitUntil: 'load' });
        await frontend.goto(route.url, { waitUntil: 'load' });
        const source = await sourcePage.evaluate(measure);
        const actual = await frontend.evaluate(measure);
        rows.push({ route: route.route, width, role: 'frontend', source, actual });
        assert.ok(actual.runtimeClasses.split(/\s+/).includes('page'), 'Core page state survives authored class collision isolation');
        compare(actual, source, `${route.route} ${width} frontend`);
    }
    const editor = await browser.newPage({ viewport: { width: 1800, height: 1200 } });
    await editor.goto(`${base}/wp-login.php`, { waitUntil: 'load' });
    await editor.getByLabel('Username or Email Address').fill(process.env.BODY_CONTEXT_USER);
    await editor.locator('#user_pass').fill(process.env.BODY_CONTEXT_PASSWORD);
    await editor.locator('#wp-submit').click();
    for (const route of routes) {
        await editor.goto(`${base}/wp-admin/post.php?post=${route.id}&action=edit`, { waitUntil: 'domcontentloaded' });
        await editor.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0);
        const welcome = editor.locator('.components-modal__screen-overlay');
        if (await welcome.isVisible()) await welcome.getByRole('button', { name: /Close|Get started/ }).first().click();
        await editor.locator('iframe[name="editor-canvas"]').waitFor();
        const iframe = editor.locator('iframe[name="editor-canvas"]');
        const canvas = await (await iframe.elementHandle()).contentFrame();
        await canvas.waitForSelector('.blocks-engine-editor-anchor-hero', { state: 'attached' });
        for (const width of [390, 768, 1440]) {
            // Resize the real native iframe viewport; do not alter its content,
            // classes, authored styles or the generated document-state assets.
            await iframe.evaluate((node, width) => { node.style.width = `${width}px`; node.style.height = '900px'; node.style.maxWidth = 'none'; node.style.flexShrink = '0'; }, width);
            await canvas.waitForFunction(width => innerWidth === width, width);
            await sourcePage.setViewportSize({ width, height: 900 });
            await sourcePage.setContent(route.source, { waitUntil: 'load' });
            const source = await sourcePage.evaluate(measure);
            const actual = await canvas.evaluate(measure);
            rows.push({ route: route.route, width, role: 'editor', source, actual });
            // The page editor's content excludes template parts. Shared-part
            // output is measured on the real frontend and native canvas gate.
            compare(actual, source, `${route.route} ${width} editor`, false);
        }
        const edit = await editor.evaluate(() => {
            const flatten = blocks => blocks.flatMap(block => [block, ...flatten(block.innerBlocks || [])]);
            const blocks = flatten(wp.data.select('core/block-editor').getBlocks());
            const paragraph = blocks.find(block => block.name === 'core/paragraph' && String(block.attributes.content).includes('Neutral hero'));
            wp.data.dispatch('core/block-editor').updateBlockAttributes(paragraph.clientId, { content: 'Neutral hero saved' });
            return { count: blocks.length, invalid: blocks.filter(block => !block.isValid).map(block => block.name) };
        });
        assert.deepEqual(edit.invalid, [], `${route.route}: imported blocks valid before edit`);
        await editor.evaluate(() => wp.data.dispatch('core/editor').savePost());
        await editor.waitForFunction(() => !wp.data.select('core/editor').isSavingPost() && !wp.data.select('core/editor').isEditedPostDirty());
        await editor.reload({ waitUntil: 'domcontentloaded' });
        await editor.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0);
        const validation = await editor.evaluate(() => {
            const flatten = blocks => blocks.flatMap(block => [block, ...flatten(block.innerBlocks || [])]);
            const blocks = flatten(wp.data.select('core/block-editor').getBlocks());
            return { count: blocks.length, invalid: blocks.filter(block => !block.isValid).map(block => block.name), saved: wp.data.select('core/editor').getEditedPostContent().includes('Neutral hero saved') };
        });
        rows.push({ route: route.route, role: 'save-reload', validation });
        assert.equal(validation.saved, true, 'The edit persisted through the real REST save/reload');
        assert.deepEqual(validation.invalid, [], 'Every reopened block is valid');
        await editor.locator('iframe[name="editor-canvas"]').waitFor();
        const reloadedIframe = editor.locator('iframe[name="editor-canvas"]');
        const reloadedCanvas = await (await reloadedIframe.elementHandle()).contentFrame();
        await reloadedCanvas.waitForSelector('.blocks-engine-editor-anchor-hero', { state: 'attached' });
        for (const width of [390, 768, 1440]) {
            await reloadedIframe.evaluate((node, width) => { node.style.width = `${width}px`; node.style.height = '900px'; node.style.maxWidth = 'none'; node.style.flexShrink = '0'; }, width);
            await reloadedCanvas.waitForFunction(width => innerWidth === width, width);
            await sourcePage.setViewportSize({ width, height: 900 });
            await sourcePage.setContent(route.source, { waitUntil: 'load' });
            const source = await sourcePage.evaluate(measure);
            const actual = await reloadedCanvas.evaluate(measure);
            rows.push({ route: route.route, width, role: 'editor-reloaded', source, actual });
            compare(actual, source, `${route.route} ${width} editor-reloaded`, false);
        }
        if (route.route === 'index.html') {
            const beforeEmpty = await reloadedCanvas.evaluate(() => ({ id: document.body.id, classes: document.body.className }));
            await editor.evaluate(() => wp.data.dispatch('core/editor').updateEditorSettings({ blocksEngineDocumentContext: {} }));
            await reloadedCanvas.waitForFunction(() => !document.body.hasAttribute('data-mode') && !document.body.classList.contains('scope'));
            const afterEmpty = await reloadedCanvas.evaluate(() => ({ id: document.body.id, mode: document.body.getAttribute('data-mode'), empty: document.body.getAttribute('data-empty'), documentState: document.documentElement.getAttribute('data-document'), classes: document.body.className }));
            assert.equal(afterEmpty.id, '');
            assert.equal(afterEmpty.mode, null);
            assert.equal(afterEmpty.empty, null);
            assert.equal(afterEmpty.documentState, null);
            assert.ok(afterEmpty.classes.split(/\s+/).includes('editor-styles-wrapper'), 'Empty source state preserves the native editor body state');
            rows.push({ route: route.route, role: 'editor-authoritative-empty', beforeEmpty, afterEmpty });
        }
    }
} finally {
    const summary = rows.filter(row => row.actual).map(row => ({ route: row.route, width: row.width, role: row.role, hero: [row.source.frames.find(frame => frame.id === 'hero').height, row.actual.frames.find(frame => frame.id === 'hero').height], gutter: [row.source.frames[0].x, row.actual.frames[0].x], scrollWidth: [row.source.scrollWidth, row.actual.scrollWidth] }));
    writeFileSync(process.env.BODY_CONTEXT_EVIDENCE, JSON.stringify({ summary, rows, failures }, null, 2));
    console.table(summary);
    await browser.close();
}
assert.deepEqual(failures, [], 'Actual HTTP frontend/editor document context');
console.log(`Document body context runtime: ${rows.length} frontend/editor/save-reload evidence rows passed`);
