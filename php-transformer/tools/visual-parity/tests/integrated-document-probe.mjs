import fs from 'node:fs/promises';
import http from 'node:http';
import path from 'node:path';
import { chromium } from 'playwright';

const [sourceRoot, destination, output, candidateDirectory] = process.argv.slice(2);
if (!sourceRoot || !destination || !output) throw new Error('Usage: integrated-document-probe.mjs <read-only source root> <served WP URL> <fresh output>');
await fs.mkdir(output, { recursive: false });
const types = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.avif': 'image/avif', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.webp': 'image/webp', '.woff': 'font/woff', '.woff2': 'font/woff2' };
const server = http.createServer(async (request, response) => {
  try {
    const url = new URL(request.url, 'http://localhost');
    const file = path.resolve(sourceRoot, '.' + decodeURIComponent(url.pathname === '/' ? '/index.html' : url.pathname));
    if (!file.startsWith(path.resolve(sourceRoot) + path.sep)) throw new Error('Outside source root');
    response.setHeader('Content-Type', types[path.extname(file).toLowerCase()] ?? 'application/octet-stream');
    response.end(await fs.readFile(file));
  } catch { response.statusCode = 404; response.end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const source = `http://127.0.0.1:${server.address().port}/`;
const browser = await chromium.launch({ headless: true });
const profiles = [
  { name: 'desktop', userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36', isMobile: false, hasTouch: false },
  { name: 'phone', userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1', isMobile: true, hasTouch: true },
  { name: 'tablet', userAgent: 'Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1', isMobile: true, hasTouch: true },
];
const all = [];
const candidate = candidateDirectory ? {
  head: await fs.readFile(path.join(candidateDirectory, 'head.html'), 'utf8'),
  summary: JSON.parse(await fs.readFile(path.join(candidateDirectory, 'summary.json'), 'utf8')),
  plan: JSON.parse(await fs.readFile(path.join(candidateDirectory, 'plan.json'), 'utf8')),
} : null;
const alignMarkers = text => {
  if (!candidate) return text;
  const { candidate_seeds: from, served_seeds: to } = candidate.summary;
  if (from.length !== 1 || to.length !== 1) throw new Error('Ambiguous verification source marker identity');
  return text.replaceAll(from[0], to[0]);
};
try {
  for (const profile of profiles) {
    const context = await browser.newContext({ ...profile, viewport: { width: 768, height: 900 }, deviceScaleFactor: 1 });
    const pair = {};
    for (const [kind, url] of [['source', source], ['wordpress', destination]]) {
      const page = await context.newPage();
      if (kind === 'wordpress' && candidate) {
        const stylePattern = /<style\b[^>]*>([\s\S]*?)<\/style\s*>/gi;
        const styles = [...candidate.head.matchAll(stylePattern)].map(match => alignMarkers(match[1]));
        await page.route(destination, async route => {
          const response = await route.fetch();
          let index = 0;
          const html = (await response.text()).replace(/(<style\b[^>]*\bdata-dla-[^>]*>)([\s\S]*?)(<\/style\s*>)/gi, (match, open, css, close) => {
            if (index >= styles.length) throw new Error('Served head has more declared styles than candidate');
            return open + styles[index++] + close;
          });
          if (index !== styles.length) throw new Error(`Ordered head style mismatch: ${index}/${styles.length}`);
          await route.fulfill({ response, body: html });
        });
        const origin = new URL(destination).origin;
        await page.route(`${origin}/wp-content/themes/**`, async route => {
          const url = new URL(route.request().url());
          if (!url.pathname.endsWith('.css')) return route.continue();
          const asset = candidate.plan.writes.find(write => write.kind === 'theme_asset' && url.pathname.endsWith('/' + write.target_path));
          if (!asset) return route.continue();
          await route.fulfill({ contentType: 'text/css', body: alignMarkers(asset.payload.data) });
        });
      }
      const errors = []; page.on('pageerror', error => errors.push(error.message));
      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForFunction(() => document.documentElement.hasAttribute('data-dla-selected-document'), { timeout: 15000 });
      await page.evaluate(() => Promise.race([document.fonts.ready, new Promise(resolve => setTimeout(resolve, 5000))]));
      await page.waitForTimeout(800);
      pair[kind] = await page.evaluate(() => {
        const selected = document.documentElement.getAttribute('data-dla-selected-document');
        const root = document.querySelector(`[data-dla-device-document="${selected}"]`);
        const props = ['display', 'position', 'width', 'min-width', 'max-width', 'height', 'min-height', 'left', 'right', 'transform', 'overflow-x', 'box-sizing', 'flex-basis', 'flex-shrink', 'grid-template-columns', 'margin-left', 'margin-right', 'padding-left', 'padding-right', 'font-size'];
        const describe = node => {
          if (!node) return null;
          const style = getComputedStyle(node), rect = node.getBoundingClientRect();
          const variables = {}; for (let i = 0; i < style.length; i++) if (style[i].startsWith('--') && /(?:width|margin|site|grid|page|mesh)/i.test(style[i])) variables[style[i]] = style.getPropertyValue(style[i]);
          return { tag: node.tagName, id: node.id, classes: node.getAttribute('class'), attrs: Object.fromEntries([...node.attributes].filter(attr => attr.name.startsWith('data-')).map(attr => [attr.name, attr.value])), rect: { x: rect.x, y: rect.y, width: rect.width, height: rect.height, right: rect.right }, styles: Object.fromEntries(props.map(prop => [prop, style.getPropertyValue(prop)])), variables };
        };
        const targets = root ? [...root.querySelectorAll('[id]')].filter(node => /^(?:masterPage|SITE_CONTAINER|main_MF|site-root)(?:$|--)/.test(node.id)) : [];
        const ancestors = []; for (let node = root; node; node = node.parentElement) ancestors.push(describe(node));
        const rules = [];
        const walk = (list, sheet, order, conditions = []) => {
          for (const rule of list ?? []) {
            if (rule.selectorText) {
              const matched = targets.filter(node => { try { return node.matches(rule.selectorText); } catch { return false; } });
              if (matched.length && props.some(prop => rule.style.getPropertyValue(prop)) || matched.length && rule.style.cssText.includes('--')) rules.push({ sheet, order, conditions, selector: rule.selectorText, css: rule.style.cssText, targets: matched.map(node => node.id) });
            } else if (rule.cssRules) {
              const condition = rule.conditionText ? { text: rule.conditionText, active: rule.type === CSSRule.MEDIA_RULE ? matchMedia(rule.conditionText).matches : true } : null;
              walk(rule.cssRules, sheet, order, condition ? [...conditions, condition] : conditions);
            } else if (rule.styleSheet) { try { walk(rule.styleSheet.cssRules, rule.href, order, conditions); } catch {} }
          }
        };
        const sheets = [...document.styleSheets].map((sheet, order) => {
          const node = sheet.ownerNode;
          const state = { order, href: sheet.href, media: sheet.media.mediaText, disabled: sheet.disabled, ownerTag: node?.tagName, attributes: node ? Object.fromEntries([...node.attributes].map(attr => [attr.name, attr.value])) : {} };
          try { walk(sheet.cssRules, sheet.href ?? `inline:${order}`, order, [{ text: sheet.media.mediaText, active: !sheet.media.mediaText || matchMedia(sheet.media.mediaText).matches }]); } catch (error) { state.inaccessible = error.message; }
          return state;
        });
        const overflow = [...document.body.querySelectorAll('*')].map(node => ({ node, rect: node.getBoundingClientRect() })).filter(row => row.rect.width > 0 && row.rect.right > document.documentElement.clientWidth + 1).sort((a, b) => b.rect.right - a.rect.right).slice(0, 35).map(row => describe(row.node));
        return { selected, viewport: [...document.querySelectorAll('meta[name=viewport]')].map(node => ({ content: node.content, id: node.id, attributes: Object.fromEntries([...node.attributes].map(attr => [attr.name, attr.value])) })), innerWidth, innerHeight, clientWidth: document.documentElement.clientWidth, visualWidth: visualViewport?.width, documentWidth: document.documentElement.scrollWidth, body: describe(document.body), roots: [...document.querySelectorAll('[data-dla-device-document]')].map(describe), targets: targets.map(describe), ancestors, rules, sheets, overflow };
      });
      pair[kind].errors = errors;
      await page.screenshot({ path: path.join(output, `${profile.name}-${kind}.png`), fullPage: false });
      await fs.writeFile(path.join(output, `${profile.name}-${kind}.html`), await page.content());
      await fs.writeFile(path.join(output, `${profile.name}-${kind}.json`), JSON.stringify(pair[kind], null, 2) + '\n');
      await page.close();
    }
    all.push({ profile: profile.name, ...pair });
    await context.close();
  }
  await fs.writeFile(path.join(output, 'paired.json'), JSON.stringify(all, null, 2) + '\n');
  console.log(JSON.stringify(all.map(pair => ({ profile: pair.profile, source: { inner: pair.source.innerWidth, width: pair.source.documentWidth, targets: pair.source.targets.map(node => ({ id: node.id, width: node.rect.width, minWidth: node.styles['min-width'] })), errors: pair.source.errors }, wordpress: { inner: pair.wordpress.innerWidth, width: pair.wordpress.documentWidth, targets: pair.wordpress.targets.map(node => ({ id: node.id, width: node.rect.width, minWidth: node.styles['min-width'] })), errors: pair.wordpress.errors } })), null, 2));
} finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
