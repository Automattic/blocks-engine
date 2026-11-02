import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../tools/visual-parity/node_modules/playwright/index.mjs');
const base = process.env.BE_EDITOR_WP_URL, id = process.env.BE_EDITOR_POST_ID;
const browser = await chromium.launch();
const page = await browser.newPage();
try {
    await page.goto(`${base}/?page_id=${id}`, { waitUntil: 'networkidle' });
    const colors = await page.evaluate(() => {
        const paragraph = document.querySelector('.text-bg-70');
        const source = document.createElement('p');
        source.style.color = 'hsl(204 18% 95% / .7)';
        document.body.append(source);
        const result = { actual: getComputedStyle(paragraph).color, source: getComputedStyle(source).color };
        source.remove();
        return result;
    });
    // CSSOM may serialize color-mix as color(srgb), so compare channels on canvas.
    const pixels = await page.evaluate(colors => {
        const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
        const ctx = canvas.getContext('2d');
        return Object.values(colors).map(color => { ctx.clearRect(0, 0, 1, 1); ctx.fillStyle = color; ctx.fillRect(0, 0, 1, 1); return [...ctx.getImageData(0, 0, 1, 1).data]; });
    }, colors);
    pixels[0].forEach((channel, i) => assert.ok(Math.abs(channel - pixels[1][i]) <= 1, `Rendering unchanged: ${JSON.stringify({ colors, pixels })}`));
    await page.goto(`${base}/wp-login.php`);
    await page.getByLabel('Username or Email Address').fill(process.env.BE_EDITOR_USER);
    await page.getByRole('textbox', { name: 'Password' }).fill(process.env.BE_EDITOR_PASSWORD);
    await page.getByRole('button', { name: 'Log In' }).click();
    const open = async () => {
        await page.goto(`${base}/wp-admin/post.php?post=${id}&action=edit`);
        await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0);
        return page.evaluate(() => {
            const all = bs => bs.flatMap(b => [b, ...all(b.innerBlocks || [])]);
            const blocks = all(wp.data.select('core/block-editor').getBlocks());
            if (!blocks.every(b => b.isValid)) throw new Error('Saved blocks do not validate');
            const tint = blocks.find(b => b.attributes.textColor === 'base-alpha-70');
            if (!tint) throw new Error('Editor lost the tint preset');
            wp.data.dispatch('core/block-editor').selectBlock(tint.clientId);
            return tint.attributes;
        });
    };
    await open();
    // Core's color support resolves textColor against the editor palette.
    const preset = await page.evaluate(() => {
        const settings = wp.data.select('core/block-editor').getSettings();
        const palette = settings.__experimentalFeatures?.color?.palette?.theme || settings.colors;
        return palette.find(color => color.slug === 'base-alpha-70');
    });
    assert.equal(preset.name, 'Base 70%');
    assert.equal(preset.color, 'color-mix(in srgb, var(--wp--preset--color--base) 70%, transparent)');
    await page.evaluate(() => wp.data.dispatch('core/editor').savePost());
    await page.waitForFunction(() => { const s = wp.data.select('core/editor'); return !s.isSavingPost() && s.didPostSaveRequestSucceed(); });
    await open();
    // Change ONLY base in user Global Styles; the tint must stay linked.
    const installation = JSON.parse(readFileSync(`${process.env.BE_EDITOR_EVIDENCE_DIR}/source-and-page.json`, 'utf8'));
    await page.evaluate(async stylesId => {
        const settings = wp.data.select('core/block-editor').getSettings();
        const palette = settings.__experimentalFeatures?.color?.palette?.theme || settings.colors;
        await wp.apiFetch({ path: `/wp/v2/global-styles/${stylesId}`, method: 'POST', data: { settings: { color: { palette: { theme: palette.map(color => color.slug === 'base' ? { ...color, color: '#00a86b' } : color) } } } } });
    }, installation.global_styles_id);
    await page.goto(`${base}/?page_id=${id}`, { waitUntil: 'networkidle' });
    const after = await page.locator('.text-bg-70').evaluate(node => getComputedStyle(node).color);
    assert.ok(await page.locator('.text-bg-70').evaluate(node => {
        const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
        const ctx = canvas.getContext('2d'); ctx.fillStyle = getComputedStyle(node).color; ctx.fillRect(0, 0, 1, 1);
        const [r, g, b, a] = ctx.getImageData(0, 0, 1, 1).data;
        return r <= 1 && Math.abs(g - 168) <= 1 && Math.abs(b - 107) <= 1 && Math.abs(a - 179) <= 1;
    }), `Tint follows a base-only Global Styles palette change: ${after}`);
    console.log(JSON.stringify({ before: colors, pixels, after, editorPreset: preset, savedBlocksValidate: true }));
} finally { await browser.close(); }
