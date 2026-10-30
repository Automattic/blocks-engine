import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../tools/visual-parity/node_modules/playwright/index.mjs');
const base = process.env.BE_EDITOR_WP_URL, id = process.env.BE_EDITOR_POST_ID;
const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
page.on('pageerror', error => errors.push(String(error)));
try {
 await page.goto(`${base}/?page_id=${id}`, { waitUntil: 'networkidle' });
 assert.deepEqual(errors, [], 'front-end console errors');
 const records = () => page.locator('article').evaluateAll(nodes => nodes.filter(n => !n.hidden && getComputedStyle(n).display !== 'none').map(n => Number(n.textContent.match(/Record (\d+)/)[1])));
 const all = Array.from({length:16}, (_, i) => i);
 assert.deepEqual(await records(), all);
 for (const [index, label] of ['Alpha', 'Beta', 'Gamma'].entries()) {
  await page.getByRole('button', {name:label, exact:true}).click();
  assert.deepEqual(await records(), all.filter(i => i % 3 === index));
 }
 await page.getByRole('button', {name:'Reset', exact:true}).click();
 assert.deepEqual(await records(), all);
 await page.getByRole('searchbox').fill('no such record');
 await page.getByText('No matching captured records.', {exact:true}).waitFor({state:'visible'});
 assert.deepEqual(await records(), []);
 await page.getByRole('searchbox').fill('');
 assert.deepEqual(await records(), all);
 await page.goto(`${base}/wp-login.php`);
 await page.getByLabel('Username or Email Address').fill(process.env.BE_EDITOR_USER);
 await page.getByRole('textbox', {name:'Password'}).fill(process.env.BE_EDITOR_PASSWORD);
 await page.getByRole('button', {name:'Log In'}).click();
 const open = async () => {
  await page.goto(`${base}/wp-admin/post.php?post=${id}&action=edit`);
  await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0);
  assert.ok(await page.evaluate(() => { const valid = bs => bs.every(b => b.isValid && !!wp.blocks.getBlockType(b.name) && valid(b.innerBlocks || [])); return valid(wp.data.select('core/block-editor').getBlocks()); }));
 };
 await open();
 await page.evaluate(() => wp.data.dispatch('core/editor').savePost());
 await page.waitForFunction(() => { const s = wp.data.select('core/editor'); return !s.isSavingPost() && s.didPostSaveRequestSucceed(); });
 await open();
 console.log('Collection controls and editor round trip passed');
} finally { await browser.close(); }
