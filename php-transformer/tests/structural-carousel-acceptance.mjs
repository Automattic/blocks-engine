import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { chromium } from '../tools/visual-parity/node_modules/playwright/index.mjs';

const base = process.env.BE_EDITOR_WP_URL;
const id = process.env.BE_EDITOR_POST_ID;
const evidence = process.env.BE_EDITOR_EVIDENCE_DIR;
const fixture = JSON.parse(await readFile(`${evidence}/source-and-page.json`, 'utf8'));
const browser = await chromium.launch({ headless: true });
const findings = { widths: [], editor: null };
const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, locale: 'en-US' });
const consoleErrors = [];
page.on('console', message => { if (message.type() === 'error') consoleErrors.push(message.text()); });
const blockTree = () => page.evaluate(() => {
  const flatten = (blocks) => blocks.flatMap(b => [b, ...flatten(b.innerBlocks || [])]);
  return flatten(window.wp.data.select('core/block-editor').getBlocks()).map(b => ({ name: b.name, clientId: b.clientId, attrs: b.attributes, valid: b.isValid }));
});
const waitForSettledSlide = () => page.waitForFunction(() => {
  const slides = [...document.querySelector('.blocks-engine-authored-carousel__track').children];
  return slides.every(s => getComputedStyle(s).visibility === (s.classList.contains('blocks-engine-authored-carousel__slide--active') ? 'visible' : 'hidden'));
});
try {
  for (const width of [390, 768, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`${base}/?page_id=${id}`, { waitUntil: 'domcontentloaded' });
    const root = page.locator('.blocks-engine-authored-carousel');
    await root.waitFor();
    await page.waitForFunction(() => document.querySelectorAll('.blocks-engine-authored-carousel__slide--active').length === 1);
    await waitForSettledSlide();
    const state = () => root.evaluate(el => {
      const slides = [...el.querySelector('.blocks-engine-authored-carousel__track').children];
      const active = slides.find(s => s.classList.contains('blocks-engine-authored-carousel__slide--active'));
      const image = active.querySelector('img');
      const rect = image.getBoundingClientRect(), r = el.getBoundingClientRect();
      return { index: slides.indexOf(active), count: slides.length, images: el.querySelectorAll('img').length, width: rect.width, height: rect.height, x: rect.x-r.x, rootWidth: r.width, rootHeight: r.height,
        visible: slides.filter(s => getComputedStyle(s).visibility === 'visible').length,
        arrows: [...el.querySelectorAll('[data-carousel-next],[data-carousel-previous]')].map(b => ({ tag: b.tagName, svg: !!b.querySelector('svg'), width: b.getBoundingClientRect().width })) };
    });
    const before = await state();
    assert.equal(before.count, 20); assert.equal(before.images, 20); assert.equal(before.index, 7); assert.equal(before.visible, 1);
    assert.ok(before.arrows.every(b => b.tag === 'BUTTON' && b.svg && b.width > 0));
    await root.locator('[data-carousel-next]').click();
    await page.waitForFunction(() => document.querySelector('.blocks-engine-authored-carousel__slide--active img')?.alt === 'Frame 8');
    await waitForSettledSlide();
    const next = await state();
    await root.locator('[data-carousel-previous]').click();
    await page.waitForFunction(() => document.querySelector('.blocks-engine-authored-carousel__slide--active img')?.alt === 'Frame 7');
    await waitForSettledSlide();
    assert.equal(next.index, 8); assert.equal(next.visible, 1); assert.equal((await state()).index, 7); assert.equal((await state()).visible, 1);
    assert.ok(Math.abs(before.rootHeight-next.rootHeight) < 1, 'navigation keeps responsive stage height stable');
    const sourcePage = await browser.newPage({ viewport: { width, height: 900 } });
    await sourcePage.setContent(`<style>body{margin:0}${fixture.source.css}</style>${fixture.source.html}`);
    const source = await sourcePage.locator('.frame-carousel').evaluate(el => {
      const i = el.querySelector('.active img').getBoundingClientRect(), r = el.getBoundingClientRect();
      return { fraction: i.width/r.width, ratio: i.width/i.height };
    });
    assert.ok(Math.abs(before.width/before.rootWidth-source.fraction) < .002, `${width}px keeps source responsive image container width: ${JSON.stringify(before)}`);
    assert.ok(Math.abs(before.width/before.height-source.ratio) < .002, `${width}px keeps source image ratio: ${JSON.stringify(before)}`);
    await sourcePage.close();
    await page.screenshot({ path: `${evidence}/frontend-${width}.png`, fullPage: true });
    findings.widths.push({ width, before, next, previous: await state(), source });
  }
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.getByLabel('Username or Email Address').fill(process.env.BE_EDITOR_USER);
  await page.getByRole('textbox', { name: 'Password' }).fill(process.env.BE_EDITOR_PASSWORD);
  await page.getByRole('button', { name: 'Log In' }).click();
  await page.goto(`${base}/wp-admin/post.php?post=${id}&action=edit`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0);
  const welcome = page.locator('.components-modal__screen-overlay');
  if (await welcome.isVisible()) {
    await welcome.getByRole('button', { name: /Close|Get started/ }).first().click();
    await welcome.waitFor({ state: 'hidden' });
  }
  const initial = await blockTree();
  findings.editor = { initial };
  assert.ok(initial.every(b => b.valid && b.name !== 'core/missing'), 'all compiled blocks load as valid registered blocks');
  assert.equal(initial.filter(b => b.name === 'core/image').length, 20);
  const image = initial.find(b => b.name === 'core/image');
  await page.evaluate(clientId => window.wp.data.dispatch('core/block-editor').updateBlockAttributes(clientId, { alt: 'Edited native carousel image' }), image.clientId);
  await page.evaluate(() => window.wp.data.dispatch('core/editor').savePost());
  await page.waitForFunction(() => { const s = window.wp.data.select('core/editor'); return !s.isSavingPost() && !s.isEditedPostDirty() && s.didPostSaveRequestSucceed(); });
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0);
  const reloaded = await blockTree();
  assert.ok(reloaded.every(b => b.valid && b.name !== 'core/missing'));
  assert.equal(reloaded.filter(b => b.name === 'core/image').length, 20);
  assert.equal(reloaded.find(b => b.name === 'core/image').attrs.alt, 'Edited native carousel image');
  const validation = await page.evaluate(() => {
    const visit = blocks => blocks.flatMap(b => [{ name: b.name, registered: !!window.wp.blocks.getBlockType(b.name), valid: window.wp.blocks.validateBlock(b)[0] }, ...visit(b.innerBlocks || [])]);
    return visit(window.wp.blocks.parse(window.wp.data.select('core/editor').getEditedPostContent()));
  });
  assert.ok(validation.every(b => b.registered && b.valid), 'Gutenberg save/parse validates every native child and generated parent');
  findings.editor = { nativeImages: 20, editedAlt: reloaded.find(b => b.name === 'core/image').attrs.alt, validation };
  await page.screenshot({ path: `${evidence}/editor-saved.png`, fullPage: true });
  console.log(JSON.stringify({ ok: true, ...findings }));
} finally {
  await writeFile(`${evidence}/runtime-evidence.json`, JSON.stringify(findings, null, 2));
  await writeFile(`${evidence}/browser-console.json`, JSON.stringify(consoleErrors, null, 2));
  await browser.close();
}
