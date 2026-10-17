import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ?? 'playwright');
// Inline declarations with no block support render on the converted group (#2974).
const origin = process.env.INLINE_CARRIER_TEST_URL ?? process.env.STYLESHEET_TEST_URL;
assert.ok(origin, 'INLINE_CARRIER_TEST_URL points to the disposable generated WordPress site');
const fixture = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require $argv[1]);', new URL('../fixtures/inline-unsupported-declarations.php', import.meta.url).pathname], { encoding: 'utf8' }));
const routes = [
  { source: 'index.html', candidate: '/', subject: 'p', expected: { backdropFilter: 'blur(14px)', rotated: true, painted: true } },
  { source: 'nested-header.html', candidate: '/?pagename=nested-header', subject: 'p', expected: { backdropFilter: 'blur(14px)', rotated: false, painted: false } },
];
// The converted box is the closest ancestor of the styled paragraph `a` that
// declares a backdrop.
const probe = page => page.evaluate(() => {
  const paragraph = [...document.querySelectorAll('p')].find(p => p.textContent.trim() === 'a');
  let box = paragraph?.parentElement;
  while (box && getComputedStyle(box).backdropFilter === 'none' && box !== document.body) box = box.parentElement;
  if (!box || box === document.body) return { backdropFilter: 'none', rotated: false, painted: false, inlineStyle: paragraph ? paragraph.closest('header,div')?.outerHTML ?? '' : 'missing paragraph' };
  const style = getComputedStyle(box);
  return {
    backdropFilter: style.backdropFilter,
    rotated: style.transform !== 'none' && Math.abs(new DOMMatrix(style.transform).b - Math.sin(2 * Math.PI / 180)) < 1e-4,
    painted: style.backgroundColor !== 'rgba(0, 0, 0, 0)',
    inlineStyle: box.getAttribute('style') ?? '',
  };
});
const browser = await chromium.launch({ headless: true });
try {
  for (const width of [390, 1440]) {
    for (const route of routes) {
      const source = await browser.newPage({ viewport: { width, height: 900 } });
      await source.route('http://inline-carrier-source.test/**', request => {
        const path = new URL(request.request().url()).pathname.slice(1) || 'index.html';
        return request.fulfill({ body: fixture.files[path] ?? '', contentType: 'text/html' });
      });
      await source.goto(`http://inline-carrier-source.test/${route.source}`);
      const sourceProbe = await probe(source);
      await source.close();
      const candidate = await browser.newPage({ viewport: { width, height: 900 } });
      await candidate.goto(new URL(route.candidate, origin).href, { waitUntil: 'load' });
      const candidateProbe = await probe(candidate);
      await candidate.close();
      const { inlineStyle: _sourceInline, ...sourceComputed } = sourceProbe;
      const { inlineStyle, ...candidateComputed } = candidateProbe;
      assert.deepEqual(sourceComputed, route.expected, `${route.source} source paint at ${width}px`);
      assert.deepEqual(candidateComputed, route.expected, `${route.source} converted group renders every inline declaration at ${width}px`);
      assert.ok(!/backdrop|rotate/.test(inlineStyle), `${route.source} unsupported declarations ride the carrier, not a raw inline style`);
    }
  }
  console.log('PASS: inline declarations without a block support render on the converted group in WordPress × 2 viewports');
} finally {
  await browser.close();
}
