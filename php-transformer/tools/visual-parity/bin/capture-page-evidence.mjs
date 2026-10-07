import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import { basename, join } from 'node:path';

const outDir = process.argv[2];
if (!outDir) {
  console.error('usage: node capture-page.mjs <out-dir> <url> [label]');
  process.exit(2);
}
const url = process.argv[3] ?? 'https://nickdiego.com/speaking';
const label = process.argv[4] ?? basename(url).replace(/[^a-z0-9.-]+/gi, '-');

const VIEWPORTS = [
  { name: 'desktop', width: 1280, height: 720 },
  { name: 'mobile', width: 390, height: 844 },
];

const probeJs = () => {
  const styleProps = [
    'display', 'flexDirection', 'flexWrap', 'gap', 'rowGap', 'columnGap',
    'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
    'marginTop', 'marginBottom', 'borderTopWidth', 'borderTopStyle', 'borderRadius',
    'backgroundColor', 'color', 'fontSize', 'fontWeight', 'lineHeight', 'width', 'height',
    'position', 'overflow', 'gridTemplateColumns', 'alignItems', 'justifyContent', 'boxSizing',
  ];
  const pick = (el) => {
    const cs = getComputedStyle(el);
    const r = el.getBoundingClientRect();
    const styles = {};
    for (const p of styleProps) styles[p] = cs[p];
    return {
      tag: el.tagName.toLowerCase(),
      id: el.id || undefined,
      role: el.getAttribute('role') || undefined,
      cls: (el.getAttribute('class') || '').slice(0, 220) || undefined,
      box: { x: +r.x.toFixed(1), y: +r.y.toFixed(1), w: +r.width.toFixed(1), h: +r.height.toFixed(1) },
      styles,
    };
  };
  const sectionOf = (el) => {
    for (let n = el; n; n = n.parentElement) {
      if (n.tagName === 'SECTION') {
        const h2 = n.querySelector(':scope > h2');
        return h2 ? h2.textContent.trim() : 'section';
      }
    }
    return null;
  };
  const cards = [...document.querySelectorAll('main article, main [role="button"]')].map((el) => ({
    section: sectionOf(el),
    ...pick(el),
  }));
  const yearHeadings = [...document.querySelectorAll('main h2')].map((el) => {
    const r = el.getBoundingClientRect();
    return { text: el.textContent.trim(), box: { x: +r.x.toFixed(1), y: +r.y.toFixed(1), w: +r.width.toFixed(1), h: +r.height.toFixed(1) } };
  });
  const cardTitles = [...document.querySelectorAll('main article h3, main [role="button"] h3')].map((el) => {
    const a = el.querySelector('a');
    const r = el.getBoundingClientRect();
    return {
      section: sectionOf(el),
      text: el.textContent.trim(),
      href: a ? a.getAttribute('href') : undefined,
      box: { x: +r.x.toFixed(1), y: +r.y.toFixed(1), w: +r.width.toFixed(1), h: +r.height.toFixed(1) },
    };
  });
  const icons = [...document.querySelectorAll('main article svg, main [role="button"] svg')].map((el) => {
    const r = el.getBoundingClientRect();
    return { section: sectionOf(el), w: +r.width.toFixed(1), h: +r.height.toFixed(1), visible: r.width > 0 && r.height > 0 };
  });
  const h1 = document.querySelector('main h1, h1');
  return { url: location.href, title: document.title, h1: h1 ? h1.textContent.trim() : null, cards, yearHeadings, cardTitles, icons };
};

mkdirSync(outDir, { recursive: true });
mkdirSync(join(outDir, 'screenshots'), { recursive: true });

const browser = await chromium.launch();
const manifest = { url, label, viewports: {} };
try {
  for (const vp of VIEWPORTS) {
    const page = await browser.newPage({ viewport: { width: vp.width, height: vp.height } });
    await page.goto(url, { waitUntil: 'networkidle', timeout: 90000 });
    await page.waitForTimeout(2500);
    const dom = await page.content();
    writeFileSync(join(outDir, `${label}.${vp.name}.dom.html`), dom);
    const probes = await page.evaluate(probeJs);
    const shot = join(outDir, 'screenshots', `${label}.${vp.name}.png`);
    await page.screenshot({ path: shot, fullPage: true });
    manifest.viewports[vp.name] = { viewport: vp, probes, screenshot: shot };
    await page.close();
  }
} finally {
  await browser.close();
}
writeFileSync(join(outDir, `${label}.probes.json`), JSON.stringify(manifest, null, 2));
console.log(JSON.stringify({ ok: true, outDir, label }));
