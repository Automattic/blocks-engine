import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { chromium, devices } from 'playwright';

// Requires a disposable site materialized by native-emoji-setup.php. The core
// fallback branch is selected through its real cached browser-support result,
// without loading replacement JS ourselves or editing the source document.
const config = JSON.parse(readFileSync(process.env.BE_EMOJI_SITE, 'utf8'));
const evidencePath = process.env.BE_EMOJI_EVIDENCE;
const widths = [390, 768, 1440];
const evidence = { wordpress: config.wordpress, widths: {}, editor: {}, saved: {} };
const browser = await chromium.launch();
try {
  const context = await browser.newContext({ ...devices['iPhone 17'], viewport: { width: 390, height: 900 } });
  await context.addInitScript(() => {
    sessionStorage.setItem('wpEmojiSettingsSupports', JSON.stringify({ timestamp: Date.now(), supportTests: { flag: false, emoji: false } }));
  });
  const source = await context.newPage();
  const frontend = await context.newPage();
  const read = frame => frame.locator('.emoji-fixture').evaluate(container => {
    const boxes = {};
    for (const selector of ['.emoji-copy', '.emoji-following']) {
      const node = container.querySelector(selector);
      const rect = node.getBoundingClientRect();
      const style = getComputedStyle(node);
      boxes[selector] = { text: node.textContent, height: rect.height, relativeY: rect.y - container.getBoundingClientRect().y, fontSize: style.fontSize, lineHeight: style.lineHeight, emojiImages: node.querySelectorAll('img.emoji').length };
    }
    return { viewport: innerWidth, height: container.getBoundingClientRect().height, boxes };
  });
  const failures = [];
  const compare = (expected, actual, label) => {
    for (const [selector, box] of Object.entries(expected.boxes)) {
      const candidate = actual.boxes[selector];
      for (const key of ['text', 'fontSize', 'lineHeight']) if (candidate[key] !== box[key]) failures.push(`${label} ${selector} ${key}: ${JSON.stringify(candidate[key])} != ${JSON.stringify(box[key])}`);
      for (const key of ['height', 'relativeY']) if (Math.abs(candidate[key] - box[key]) > 0.01) failures.push(`${label} ${selector} ${key}: ${candidate[key]} != ${box[key]}`);
      if (candidate.emojiImages) failures.push(`${label} ${selector}: core replaced native text with ${candidate.emojiImages} images`);
    }
    if (Math.abs(actual.height - expected.height) > 0.01) failures.push(`${label} container height: ${actual.height} != ${expected.height}`);
  };
  for (const width of widths) {
    await source.setViewportSize({ width, height: 900 });
    await frontend.setViewportSize({ width, height: 900 });
    await source.goto(config.source_url);
    await frontend.goto(config.url);
    // Observe the actual replacement/absence after the real footer loader has
    // had time to run; failed remote image fetches must not undo the evidence.
    await frontend.waitForTimeout(1500);
    evidence.widths[width] = { source: await read(source), frontend: await read(frontend), emojiSettings: await frontend.locator('#wp-emoji-settings').count() };
    assert.equal(evidence.widths[width].source.viewport, width);
    compare(evidence.widths[width].source, evidence.widths[width].frontend, `frontend ${width}`);
  }

  const editorContext = await browser.newContext();
  await editorContext.addInitScript(() => {
    sessionStorage.setItem('wpEmojiSettingsSupports', JSON.stringify({ timestamp: Date.now(), supportTests: { flag: false, emoji: false } }));
  });
  const editor = await editorContext.newPage();
  await editor.goto(new URL('/wp-login.php', config.url).href);
  await editor.locator('#user_login').fill(process.env.BE_EMOJI_USER);
  await editor.locator('#user_pass').fill(process.env.BE_EMOJI_PASSWORD);
  await Promise.all([editor.waitForURL(/wp-admin/), editor.locator('#wp-submit').click()]);
  evidence.adminEmojiSettings = await editor.locator('#wp-emoji-settings').count();
  if (evidence.adminEmojiSettings) failures.push('admin dashboard: core emoji replacement runtime is still present');
  await editor.goto(new URL(`/wp-admin/post.php?post=${config.post_id}&action=edit`, config.url).href);
  await editor.waitForFunction(() => Boolean(window.wp?.data?.select('core/block-editor').getBlocks().length), { timeout: 30000 });
  const iframe = editor.locator('iframe[name="editor-canvas"]');
  await iframe.waitFor({ timeout: 30000 });
  const canvas = await (await iframe.elementHandle()).contentFrame();
  await canvas.locator('.emoji-copy').waitFor();
  for (const width of widths) {
    await editor.setViewportSize({ width: width + 500, height: 1000 });
    await iframe.evaluate((element, width) => { element.style.width = `${width}px`; element.style.minWidth = `${width}px`; element.style.maxWidth = `${width}px`; }, width);
    await canvas.waitForFunction(width => innerWidth === width, width);
    evidence.editor[width] = await read(canvas);
    compare(evidence.widths[width].source, evidence.editor[width], `editor ${width}`);
  }
  const edited = '<span class="emoji-inline">Saved emoji 👉🏼</span><br>Keep this text editable.<br>Authored line height.';
  await editor.evaluate(edited => {
    const find = blocks => {
      for (const block of blocks) {
        if (block.name === 'core/paragraph' && block.attributes.className?.includes('emoji-copy')) return block;
        const child = find(block.innerBlocks || []);
        if (child) return child;
      }
    };
    const block = find(wp.data.select('core/block-editor').getBlocks());
    if (!block) throw new Error('Imported emoji text is not an editable core/paragraph');
    wp.data.dispatch('core/block-editor').updateBlockAttributes(block.clientId, { content: edited });
    return wp.data.dispatch('core/editor').savePost();
  }, edited);
  await editor.reload();
  await editor.locator('iframe[name="editor-canvas"]').waitFor({ timeout: 30000 });
  const savedCanvas = await (await editor.locator('iframe[name="editor-canvas"]').elementHandle()).contentFrame();
  await savedCanvas.locator('.emoji-copy').waitFor();
  assert.match(await savedCanvas.locator('.emoji-copy').textContent(), /^Saved emoji 👉🏼/);
  for (const width of widths) {
    await source.setViewportSize({ width, height: 900 });
    await frontend.setViewportSize({ width, height: 900 });
    await source.goto(config.source_url);
    await source.locator('.emoji-copy').evaluate((node, edited) => { node.innerHTML = edited; }, edited);
    await frontend.goto(config.url);
    await frontend.waitForTimeout(500);
    await editor.setViewportSize({ width: width + 500, height: 1000 });
    await editor.locator('iframe[name="editor-canvas"]').evaluate((element, width) => { element.style.width = `${width}px`; element.style.minWidth = `${width}px`; element.style.maxWidth = `${width}px`; }, width);
    await savedCanvas.waitForFunction(width => innerWidth === width, width);
    evidence.saved[width] = { source: await read(source), frontend: await read(frontend), editor: await read(savedCanvas) };
    compare(evidence.saved[width].source, evidence.saved[width].frontend, `saved frontend ${width}`);
    compare(evidence.saved[width].source, evidence.saved[width].editor, `saved editor ${width}`);
  }
  evidence.failures = failures;
  if (evidencePath) writeFileSync(evidencePath, JSON.stringify(evidence, null, 2));
  assert.deepEqual(failures, []);
  console.log(JSON.stringify({ wordpress: config.wordpress, widths, editor: true, saved: true, evidence: evidencePath }));
} finally {
  await browser.close();
}
