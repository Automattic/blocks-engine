import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ?? 'playwright');
const browser = await chromium.launch({ headless: true });
const evidence = [];
try {
  for (const width of [390, 768, 1440]) for (const colorScheme of ['light', 'dark']) {
    const page = await browser.newPage({ viewport: { width, height: 900 }, colorScheme });
    await page.goto(process.env.READABILITY_TEST_URL, { waitUntil: 'load' });
    const rows = await page.evaluate(() => {
      const rgb = value => value.match(/[\d.]+/g).map(Number);
      const luminance = c => c.slice(0, 3).map(v => v / 255).map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4).reduce((s, v, i) => s + v * [.2126, .7152, .0722][i], 0);
      const measure = (el, text) => {
        const style = getComputedStyle(el);
        if (!el.getClientRects().length || style.visibility === 'hidden') return null;
        const layers = [];
        for (let ancestor = el; ancestor; ancestor = ancestor.parentElement) layers.unshift(rgb(getComputedStyle(ancestor).backgroundColor));
        let background = [255, 255, 255];
        for (const layer of layers) background = background.map((v, i) => layer[i] * (layer[3] ?? 1) + v * (1 - (layer[3] ?? 1)));
        const foreground = rgb(style.color);
        const painted = background.map((v, i) => foreground[i] * (foreground[3] ?? 1) + v * (1 - (foreground[3] ?? 1)));
        const a = luminance(painted), b = luminance(background);
        return { text, foreground, background, ratio: (Math.max(a, b) + .05) / (Math.min(a, b) + .05) };
      };
      const rows = [];
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
      while (walker.nextNode()) {
        const node = walker.currentNode;
        if (node.textContent.trim() && !node.parentElement.closest('script,style,noscript')) rows.push(measure(node.parentElement, node.textContent.trim()));
      }
      for (const control of document.querySelectorAll('input,button,select,textarea')) rows.push(measure(control, control.value || control.textContent));
      return rows.filter(Boolean);
    });
    assert.ok(rows.length >= 10, 'WordPress rendered text and controls');
    const ink = colorScheme === 'dark' ? [255, 255, 255] : [17, 17, 17];
    for (const text of ['Questions', 'Frequently asked question', 'Message']) {
      assert.ok(rows.some(row => row.text.includes(text) && JSON.stringify(row.foreground.slice(0, 3)) === JSON.stringify(ink)), `${text} follows ${colorScheme}: ${JSON.stringify(rows)}`);
    }
    for (const row of rows) assert.ok(row.ratio >= 4.5, `${width}/${colorScheme}: ${JSON.stringify(row)}`);
    assert.equal(await page.locator('body').evaluate(el => getComputedStyle(el).fontSize), '16px');
    evidence.push({ width, colorScheme, rows });
    await page.close();
  }
  console.log(JSON.stringify({ schema: 'blocks-engine/readability/v1', result: 'pass', evidence }));
} finally { await browser.close(); }
