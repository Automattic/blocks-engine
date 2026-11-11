import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');
const browser = await chromium.launch();
try {
  const page = await browser.newPage({ viewport: { width: 1440, height: 400 } });
  for (const band of ['', 'banded']) {
    const out = JSON.parse(execFileSync('php', [new URL('./search-decorative-icon.php', import.meta.url).pathname, band, '--json'], { encoding: 'utf8' }));
    const attrs = JSON.parse(out.html.match(/<!-- wp:search (.*?) \/-->/s)[1]);
    const pre = out.html.slice(0, out.html.indexOf('<!-- wp:search')).replace(/<!--[^>]*-->/g, '');
    const post = out.html.slice(out.html.indexOf('/-->') + 4).replace(/<!--[^>]*-->/g, '');
    const rendered = `${pre}<form class="wp-block-search wp-block-search__no-button ${attrs.className}"><label class="screen-reader-text">${attrs.label}</label><div class="wp-block-search__inside-wrapper"><input class="wp-block-search__input" placeholder="${attrs.placeholder}" type="search" name="s" required></div></form>${post}`;
    const coreCss = '.wp-block-search__inside-wrapper{display:flex;flex:auto;flex-wrap:nowrap;max-width:100%}.wp-block-search__input{appearance:none;border:1px solid #949494;flex-grow:1;margin:0;min-width:3rem;padding:8px;text-decoration:unset!important}.screen-reader-text{clip:rect(1px,1px,1px,1px);position:absolute!important;height:1px;width:1px;overflow:hidden}';
    const measure = async () => {
      const box = await page.locator('input').boundingBox();
      const png = (await page.screenshot({ clip: box })).toString('base64');
      const icon = await page.evaluate(async src => {
        const img = new Image(); img.src = 'data:image/png;base64,' + src; await img.decode();
        const c = document.createElement('canvas'); c.width = img.width; c.height = img.height;
        const g = c.getContext('2d'); g.drawImage(img, 0, 0);
        const d = g.getImageData(0, 0, c.width, c.height).data;
        let x0 = 1e9, y0 = 1e9, x1 = -1, y1 = -1;
        for (let y = 0; y < c.height; y++) for (let x = 0; x < c.width; x++) {
          const i = (y * c.width + x) * 4;
          if ([0, 1, 2].every(j => Math.abs(d[i + j] - 90) < 25)) {
            x0 = Math.min(x0, x); y0 = Math.min(y0, y); x1 = Math.max(x1, x); y1 = Math.max(y1, y);
          }
        }
        return x1 < 0 ? null : [x0, y0, x1 - x0 + 1, y1 - y0 + 1];
      }, png);
      const indent = await page.locator('input').evaluate(el => Math.round(parseFloat(getComputedStyle(el).paddingLeft)));
      return { icon, indent, height: Math.round(box.height) };
    };
    await page.setContent(out.src); const want = await measure();
    await page.setContent(`<!doctype html><style>${coreCss}</style><style>${out.css}</style>${rendered}`); const got = await measure();
    const near = (a, b) => Math.abs(a - b) <= 2;
    if (!(got.icon && want.icon && got.icon.every((v, i) => near(v, want.icon[i])) && near(got.indent, want.indent) && near(got.height, want.height))) {
      throw new Error(`${band || 'unbanded'}: ${JSON.stringify(got)} source ${JSON.stringify(want)}`);
    }
    console.log(`PASS search icon (${band || 'unbanded'})`, got);
  }
} finally { await browser.close(); }
