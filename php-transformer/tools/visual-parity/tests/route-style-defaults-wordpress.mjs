import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { chromium } from 'playwright';

// Explicit disposable Studio site, retained plans and evidence output.
const [site, plans, output] = process.argv.slice(2);
assert.ok(site && plans && output, 'Usage: node route-style-defaults-wordpress.mjs SITE PLANS.json EVIDENCE.json');
const materializer = new URL('./route-style-defaults-materialize.php', import.meta.url).pathname;
const fixture = new URL('../../../tests/fixtures/route-style-defaults.php', import.meta.url).pathname;
const source = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require $argv[1], JSON_THROW_ON_ERROR);', fixture], { encoding: 'utf8' }));
const evidence = { samples: [], failures: [], validity: [] };
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
await context.route('https://route-defaults.test/**', async (route) => {
  const path = new URL(route.request().url()).pathname.slice(1);
  const content = source.files[path];
  await route.fulfill({ status: content === undefined ? 404 : 200, contentType: path.endsWith('.css') ? 'text/css' : 'text/html', body: content ?? '' });
});
const expected = {
  Home: { font: 'Arial', background: 'rgb(240, 240, 240)', weight: '300', size: 40 },
  Gallery: { font: 'Verdana', background: 'rgb(51, 51, 51)', weight: '500', size: 32 },
  Journal: { font: 'Georgia', background: 'rgb(204, 204, 204)', weight: '700', size: 36 },
};
const check = (actual, wanted, label) => {
  try { assert.equal(actual, wanted, label); } catch (error) { evidence.failures.push(error.message); }
};
const measure = async (root, bodySelector, headingSelector) => {
  const body = await root.locator(bodySelector).evaluate((element) => {
    const css = getComputedStyle(element);
    return { font: css.fontFamily.split(',')[0].replaceAll('"', ''), background: css.backgroundColor, viewport: window.innerWidth, width: element.getBoundingClientRect().width };
  });
  const heading = await root.locator(headingSelector).first().evaluate((element) => {
    const css = getComputedStyle(element);
    return { font: css.fontFamily.split(',')[0].replaceAll('"', ''), weight: css.fontWeight, size: parseFloat(css.fontSize) };
  });
  return { body, heading };
};
try {
  for (const order of [0, 1]) {
    const text = execFileSync('studio', ['wp', `--path=${site}`, 'eval-file', materializer, plans, String(order)], { encoding: 'utf8' });
    const runtime = JSON.parse(text.slice(text.indexOf('{')));
    evidence.wordpress = runtime.wordpress;
    const origin = new URL(runtime.pages[0].url).origin;
    if (order === 0) {
      await page.goto(`${origin}/wp-login.php`);
      await page.locator('#user_login').fill('admin');
      await page.locator('#user_pass').fill(process.env.BE_RUNTIME_PASSWORD || 'be2512-disposable');
      await Promise.all([page.waitForURL(/wp-admin/), page.locator('#wp-submit').click()]);
    }
    for (const route of runtime.pages) {
      const wanted = expected[route.title];
      for (const width of [390, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`https://route-defaults.test/${route.source_path}`, { waitUntil: 'load' });
        const original = await measure(page, 'body', 'h1');
        evidence.samples.push({ order, route: route.title, surface: 'source', width, ...original });
        check(original.body.font, wanted.font, `${route.title} source body font ${width}`);
        check(original.body.background, wanted.background, `${route.title} source background ${width}`);
        check(original.heading.weight, wanted.weight, `${route.title} source heading weight ${width}`);
        check(original.heading.size, route.title === 'Home' && width < 600 ? 24 : wanted.size, `${route.title} source heading size ${width}`);
        await page.goto(route.url, { waitUntil: 'load' });
        const sample = await measure(page, 'body', 'h1');
        evidence.samples.push({ order, route: route.title, surface: 'frontend', width, ...sample });
        check(sample.body.font, wanted.font, `${order} ${route.title} frontend body font ${width}`);
        check(sample.body.background, wanted.background, `${order} ${route.title} frontend background ${width}`);
        check(sample.heading.font, wanted.font, `${order} ${route.title} frontend heading font ${width}`);
        check(sample.heading.weight, wanted.weight, `${order} ${route.title} frontend weight ${width}`);
        check(sample.heading.size, route.title === 'Home' && width < 600 ? 24 : wanted.size, `${order} ${route.title} frontend size ${width}`);
        check(JSON.stringify(sample.heading), JSON.stringify(original.heading), `${order} ${route.title} source/frontend heading ${width}`);
      }
      await page.setViewportSize({ width: 1600, height: 1000 });
      await page.goto(`${origin}/wp-admin/post.php?post=${route.id}&action=edit`, { waitUntil: 'load' });
      await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0);
      // Validate actual editor blocks and save/reload through the core store.
      const valid = await page.evaluate(() => {
        const walk = (blocks) => blocks.every((block) => block.isValid !== false && walk(block.innerBlocks));
        return walk(wp.data.select('core/block-editor').getBlocks());
      });
      evidence.validity.push({ order, route: route.title, valid });
      check(valid, true, `${route.title} Gutenberg validity`);
      const frame = page.frameLocator('iframe[name="editor-canvas"]');
      await frame.locator('h1.wp-block-heading').waitFor();
      for (const width of [390, 768, 1440]) {
        await page.locator('iframe[name="editor-canvas"]').evaluate((element, width) => {
          element.style.setProperty('width', `${width}px`, 'important');
          element.style.setProperty('min-width', `${width}px`, 'important');
          element.style.setProperty('max-width', `${width}px`, 'important');
          element.style.setProperty('transition', 'none', 'important');
        }, width);
        await page.frames().find((candidate) => candidate.name() === 'editor-canvas').waitForFunction((width) => window.innerWidth === width, width);
        const sample = await measure(frame, '.editor-styles-wrapper', 'h1.wp-block-heading');
        evidence.samples.push({ order, route: route.title, surface: 'editor', width, ...sample });
        check(sample.body.font, wanted.font, `${order} ${route.title} editor body font ${width}`);
        check(sample.body.background, wanted.background, `${order} ${route.title} editor background ${width}`);
        check(sample.heading.font, wanted.font, `${order} ${route.title} editor heading font ${width}`);
        check(sample.heading.weight, wanted.weight, `${order} ${route.title} editor weight ${width}`);
        check(sample.heading.size, route.title === 'Home' && width < 600 ? 24 : wanted.size, `${order} ${route.title} editor size ${width}`);
      }
      const originalParagraph = await page.evaluate(async () => {
        const flatten = (blocks) => blocks.flatMap((block) => [block, ...flatten(block.innerBlocks)]);
        const paragraph = flatten(wp.data.select('core/block-editor').getBlocks()).find((block) => block.name === 'core/paragraph');
        const content = paragraph.attributes.content;
        wp.data.dispatch('core/block-editor').updateBlockAttributes(paragraph.clientId, { content: `${content} Saved route proof.` });
        await wp.data.dispatch('core/editor').savePost();
        return content;
      });
      await page.reload({ waitUntil: 'load' });
      await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0);
      check(await page.evaluate(() => {
        const walk = (blocks) => blocks.every((block) => block.isValid !== false && walk(block.innerBlocks));
        return walk(wp.data.select('core/block-editor').getBlocks());
      }), true, `${route.title} validity after save/reload`);
      check(await page.evaluate(() => wp.data.select('core/editor').getEditedPostContent().includes('Saved route proof.')), true, `${route.title} edited content persisted`);
      await page.evaluate(async (content) => {
        const flatten = (blocks) => blocks.flatMap((block) => [block, ...flatten(block.innerBlocks)]);
        const paragraph = flatten(wp.data.select('core/block-editor').getBlocks()).find((block) => block.name === 'core/paragraph');
        wp.data.dispatch('core/block-editor').updateBlockAttributes(paragraph.clientId, { content });
        await wp.data.dispatch('core/editor').savePost();
      }, originalParagraph);
    }
  }
} finally {
  writeFileSync(output, JSON.stringify(evidence, null, 2));
  await browser.close();
}
console.log(JSON.stringify({ wordpress: evidence.wordpress, samples: evidence.samples.length, failures: evidence.failures, evidence: output }));
assert.equal(evidence.failures.length, 0, 'All route defaults survive frontend/editor and page-order permutations');
