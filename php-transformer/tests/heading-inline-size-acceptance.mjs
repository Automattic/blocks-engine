import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../tools/visual-parity/node_modules/playwright/index.mjs');
const base = process.env.BE_EDITOR_WP_URL, id = process.env.BE_EDITOR_POST_ID;
const browser = await chromium.launch();
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.goto(`${base}/?page_id=${id}`, { waitUntil: 'networkidle' });
    const frontend = await page.locator('h1.display-title').evaluate(el => getComputedStyle(el).fontSize);
    assert.equal(frontend, '50px');
    await page.goto(`${base}/wp-login.php`);
    await page.getByLabel('Username or Email Address').fill(process.env.BE_EDITOR_USER);
    await page.getByRole('textbox', { name: 'Password' }).fill(process.env.BE_EDITOR_PASSWORD);
    await page.getByRole('button', { name: 'Log In' }).click();
    await page.goto(`${base}/wp-admin/post.php?post=${id}&action=edit`);
    await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0);
    const heading = page.frameLocator('iframe[name="editor-canvas"]').locator('h1.display-title');
    await heading.waitFor();
    const editor = await heading.evaluate(el => getComputedStyle(el).fontSize);
    assert.equal(editor, frontend, 'Editor inline preset must beat the author font shorthand');
    console.log(JSON.stringify({ frontend, editor }));
} finally {
    await browser.close();
}
