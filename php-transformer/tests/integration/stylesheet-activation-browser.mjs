import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ?? 'playwright');
const origin = process.env.STYLESHEET_TEST_URL;
assert.ok(origin, 'STYLESHEET_TEST_URL points to the disposable generated WordPress site');
const browser = await chromium.launch({ headless: true });
const fixture = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require $argv[1]);', new URL('../fixtures/stylesheet-activation.php', import.meta.url).pathname], { encoding: 'utf8' }));
try {
  for (const width of [390, 768, 1440]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const probe = () => page.evaluate(() => ({
      background: getComputedStyle(document.body).backgroundColor,
      family: getComputedStyle(document.body).fontFamily,
      weight: getComputedStyle(document.querySelector('h1')).fontWeight,
    }));
    await page.goto(origin, { waitUntil: 'load' });
    assert.deepEqual(await probe(), { background: 'rgb(86, 87, 93)', family: 'Times', weight: '700' }, `initial preferred paint at ${width}px`);
    await page.emulateMedia({ media: 'print' });
    assert.equal((await probe()).background, 'rgb(0, 255, 0)', 'source print condition is preserved');
    await page.emulateMedia({ media: 'screen' });
    const alternate = await page.locator('link[title="light"]').evaluate(link => ({ rel: link.rel, disabled: link.disabled, media: link.media }));
    assert.deepEqual(alternate, { rel: 'alternate stylesheet', disabled: true, media: 'all' });
    // Source-style selection uses native link title/disabled properties.
    await page.evaluate(() => {
      for (const link of document.querySelectorAll('link[title]')) link.disabled = link.title !== 'light';
    });
    await page.waitForFunction(() => getComputedStyle(document.body).backgroundColor === 'rgb(255, 255, 255)');
    assert.deepEqual(await probe(), { background: 'rgb(255, 255, 255)', family: 'Georgia', weight: '300' }, `selected alternate paint at ${width}px`);
    const selected = await probe();
    const source = await browser.newPage({ viewport: { width, height: 900 } });
    await source.route('http://stylesheet-source.test/**', route => {
      const path = new URL(route.request().url()).pathname.slice(1) || 'index.html';
      return route.fulfill({ body: fixture.files[path] ?? '', contentType: path.endsWith('.css') ? 'text/css' : 'text/html' });
    });
    await source.goto('http://stylesheet-source.test/');
    await source.evaluate(() => { for (const link of document.querySelectorAll('link[title]')) link.disabled = link.title !== 'light'; });
    await source.waitForFunction(() => getComputedStyle(document.body).backgroundColor === 'rgb(255, 255, 255)');
    assert.deepEqual(await source.evaluate(() => ({ background: getComputedStyle(document.body).backgroundColor, family: getComputedStyle(document.body).fontFamily, weight: getComputedStyle(document.querySelector('h1')).fontWeight })), selected, 'selected WordPress paint matches the native source cascade');
    await source.close();
    await page.evaluate(() => {
      for (const link of document.querySelectorAll('link[title]')) link.disabled = link.title !== 'dark';
    });
    await page.waitForFunction(() => getComputedStyle(document.body).backgroundColor === 'rgb(86, 87, 93)');
    assert.deepEqual(await probe(), { background: 'rgb(86, 87, 93)', family: 'Times', weight: '700' }, `preferred restored at ${width}px`);
    await page.goto(new URL('/?pagename=second', origin).href, { waitUntil: 'load' });
    assert.equal(await page.locator('h1').textContent(), 'Second route', 'independent WordPress page renders');
    assert.equal(await page.locator('link[rel~="stylesheet"][title]').count(), 0, `stylesheet sets stay on their declaring route at ${width}px`);
    assert.equal(await page.locator('link[href*="light.css"]').count(), 0, 'inactive assets are retained without leaking delivery onto unrelated routes');
    await page.close();
  }
  console.log('PASS: actual WordPress initial paint, native stylesheet selection, save/reload, and independent route × 3 viewports');
} finally {
  await browser.close();
}
