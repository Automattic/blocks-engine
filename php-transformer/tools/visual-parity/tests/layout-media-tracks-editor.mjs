import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';

const url = process.env.BE_WORDPRESS_URL;
const artifact = JSON.parse(readFileSync(process.env.BE_WORDPRESS_ARTIFACT, 'utf8'));
assert.ok(url && process.env.BE_WORDPRESS_PASSWORD, 'Use a disposable WordPress site and its admin credentials');
const browser = await chromium.launch();
try {
  const page = await browser.newPage();
  await page.goto(`${url}/wp-login.php`);
  await page.locator('#user_login').fill(process.env.BE_WORDPRESS_USER || 'admin');
  await page.locator('#user_pass').fill(process.env.BE_WORDPRESS_PASSWORD);
  await Promise.all([page.waitForURL('**/wp-admin/**'), page.locator('#wp-submit').click()]);
  await page.goto(`${url}/wp-admin/post.php?post=${artifact.post_id}&action=edit`);
  await page.waitForFunction(() => window.wp?.blocks && window.wp?.data?.select('core/editor')?.getCurrentPostId());
  const inspect = markup => {
    const blocks = window.wp.blocks.parse(markup);
    const walk = list => list.flatMap(block => [{ name: block.name, valid: block.isValid, issues: block.validationIssues }, ...walk(block.innerBlocks)]);
    return { blocks: walk(blocks), serialized: window.wp.blocks.serialize(blocks) };
  };
  const before = await page.evaluate(inspect, artifact.markup);
  assert.deepEqual(before.blocks.filter(block => !block.valid), [], 'Core Gutenberg save() validates every generated native block');
  const saved = await page.evaluate(async markup => {
    const blocks = window.wp.blocks.parse(markup);
    const walk = list => list.flatMap(block => [block, ...walk(block.innerBlocks)]);
    const caption = walk(blocks).find(block => block.name === 'core/paragraph' && String(block.attributes.content).trim() === 'First caption');
    if (!caption) throw new Error('The caption must remain a native editable paragraph');
    window.wp.data.dispatch('core/block-editor').resetBlocks(blocks);
    window.wp.data.dispatch('core/block-editor').updateBlockAttributes(caption.clientId, { content: 'Fresh caption' });
    const expected = window.wp.blocks.serialize(window.wp.data.select('core/block-editor').getBlocks());
    await window.wp.data.dispatch('core/editor').savePost();
    const post = await window.wp.apiFetch({ path: `/wp/v2/pages/${window.wp.data.select('core/editor').getCurrentPostId()}?context=edit` });
    return { post, expected };
  }, before.serialized);
  await page.reload();
  await page.waitForFunction(() => window.wp?.blocks && window.wp?.data?.select('core/editor')?.getCurrentPostId());
  const after = await page.evaluate(inspect, saved.post.content.raw);
  assert.deepEqual(after.blocks.filter(block => !block.valid), [], 'Native blocks remain valid after editor save and reload');
  assert.equal(after.serialized, saved.expected, 'Gutenberg native edit/save/reload is stable');
  assert.match(saved.post.content.raw, /Fresh caption/, 'A native caption edit persists through WordPress');
  assert.ok(after.blocks.some(block => block.name === 'core/table'), 'The semantic data table remains editable');
  const evidence = { wordpress: artifact.wordpress, postId: artifact.post_id, blockCount: after.blocks.length, invalid: [], editedCaption: 'Fresh caption', markup: saved.post.content.raw, rendered: saved.post.content.rendered };
  if (process.env.BE_EDITOR_EVIDENCE) {
    writeFileSync(process.env.BE_EDITOR_EVIDENCE, JSON.stringify(evidence, null, 2));
    writeFileSync(`${process.env.BE_EDITOR_EVIDENCE}.rendered.html`, `<style>${artifact.layout_css}</style>${saved.post.content.rendered}`);
  }
  console.log(JSON.stringify({ wordpress: artifact.wordpress, postId: artifact.post_id, blockCount: after.blocks.length, invalid: [] }));
} finally {
  await browser.close();
}
