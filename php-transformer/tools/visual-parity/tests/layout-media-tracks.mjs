import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { chromium } from 'playwright';

const root = new URL('../../..', import.meta.url).pathname;
let source = readFileSync(process.env.BE_SOURCE_FILE || `${root}/tests/fixtures/layout-media-tracks.html`, 'utf8');
if (process.env.BE_SOURCE_ROOT) {
  source = source.replace(/<link\b[^>]*rel=["']stylesheet["'][^>]*>/gi, tag => {
    const href = tag.match(/href=["']([^"']+)["']/i)?.[1];
    return `<style>${readFileSync(resolve(process.env.BE_SOURCE_ROOT, `.${href}`), 'utf8')}</style>`;
  });
}
if (process.env.BE_FIXTURE_VARIANT === 'responsive') {
  // This breakpoint is authored by the control fixture, not inferred by the
  // converter. It proves source CSS can replace the table's default topology.
  source = source.replace('</style>', '@media(max-width:613px){.album>tbody>tr>td{display:block;width:100%;padding:4px;text-align:right;vertical-align:top}.thumb{max-width:100%}}</style>');
  source = source.replace('Portrait caption', '<p style="margin:12px 0">Portrait caption</p>');
}
if (process.env.BE_SOURCE_ROOT) source = source.replace('<head>', '<head><base href="https://layout.example/">');
const code = 'require $argv[1]."/vendor/autoload.php";echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, process.env.BE_TRANSFORMER_ROOT || root, Buffer.from(source).toString('base64')], { encoding: 'utf8' }));
// Read actual Core styles, not a hand-written approximation of its breakpoint.
const wp = process.env.WORDPRESS_ROOT;
assert.ok(wp, 'WORDPRESS_ROOT must identify a read-only WordPress distribution');
const coreCss = ['columns', 'image', 'group'].map(name => {
  try { return readFileSync(`${wp}/wp-includes/blocks/${name}/style.css`, 'utf8'); }
  catch { return readFileSync(`${wp}/wp-includes/blocks/${name}/style.min.css`, 'utf8'); }
}).join('\n');
const css = result.assets.map(asset => asset.content || '').join('\n');
const candidate = process.env.BE_RENDERED_HTML ? readFileSync(process.env.BE_RENDERED_HTML, 'utf8') : result.serialized_blocks;
const browser = await chromium.launch();
const evidence = { source, candidate, css, samples: {} };
const boxes = page => page.locator('img').evaluateAll(images => images.map(img => {
  const r = img.getBoundingClientRect();
  return { alt: img.alt, x: r.x, y: r.y, width: r.width, height: r.height };
}));
const topology = page => page.locator('table,tr,td,.blocks-engine-layout-table-table,.blocks-engine-layout-table-row,.blocks-engine-layout-table-cell').evaluateAll(elements => elements.map(element => {
  const r = element.getBoundingClientRect();
  const s = getComputedStyle(element);
  return { tag: element.tagName, classes: element.className, text: element.textContent.trim().slice(0, 30), x: r.x, y: r.y, width: r.width, height: r.height, padding: s.padding, borderSpacing: s.borderSpacing, display: s.display, font: s.font, lineHeight: s.lineHeight };
}));
const tableTracks = (page, native) => page.locator(native ? '.blocks-engine-layout-table-table' : 'table').evaluateAll((tables, native) => tables
  .filter(table => native || !table.querySelector('th,caption,thead,tfoot'))
  .map(table => {
    const r = table.getBoundingClientRect();
    const rows = native ? [...table.children].filter(child => child.classList.contains('blocks-engine-layout-table-row'))
      : [...table.querySelectorAll('tr')].filter(row => row.closest('table') === table);
    const rowCells = native && !rows.length ? [table] : rows;
    return { x: r.x, y: r.y, width: r.width, height: r.height,
      cells: rowCells.map(row => [...row.children].filter(cell => native ? cell.classList.contains('blocks-engine-layout-table-cell') : cell.tagName === 'TD').map(cell => {
        const c = cell.getBoundingClientRect();
        return { x: c.x, y: c.y, width: c.width, height: c.height };
      })) };
  }), native);
try {
  for (const width of [390, 768, 1440]) {
    const original = await browser.newPage({ viewport: { width, height: 900 } });
    const imported = await browser.newPage({ viewport: { width, height: 900 } });
    if (process.env.BE_SOURCE_ROOT) {
      for (const page of [original, imported]) {
        await page.route('https://layout.example/**', route => {
          try { return route.fulfill({ body: readFileSync(resolve(process.env.BE_SOURCE_ROOT, `.${new URL(route.request().url()).pathname}`)) }); }
          catch { return route.abort(); }
        });
      }
    }
    await original.setContent(source);
    await imported.setContent(`<!doctype html><html><head><base href="https://layout.example/"><style>${coreCss}\n${css}</style></head><body>${candidate}</body></html>`);
    await Promise.all([original, imported].map(page => page.locator('img').evaluateAll(images => Promise.all(images.map(img => img.decode())))));
    const a = await boxes(original);
    const b = await boxes(imported);
    evidence.samples[width] = { source: a, candidate: b, sourceTopology: await topology(original), candidateTopology: await topology(imported), sourceTracks: await tableTracks(original, false), candidateTracks: await tableTracks(imported, true) };
    await original.close();
    await imported.close();
  }
  for (const [width, sample] of Object.entries(evidence.samples)) {
    assert.deepEqual(sample.candidateTracks, sample.sourceTracks, `${width}px table, row and cell track topology/geometry`);
    for (let i = 0; i < sample.source.length; i++) {
      for (const dimension of ['x', 'y', 'width', 'height']) {
        assert.ok(Math.abs(sample.source[i][dimension] - sample.candidate[i][dimension]) <= 0.6,
          `${width}px ${sample.source[i].alt} ${dimension}: source=${sample.source[i][dimension]}, imported=${sample.candidate[i][dimension]}`);
      }
    }
  }
  console.log(JSON.stringify(Object.fromEntries(Object.entries(evidence.samples).map(([width, sample]) => [width, { source: sample.source, candidate: sample.candidate }]))));
} finally {
  if (process.env.BE_EVIDENCE_PATH) {
    writeFileSync(process.env.BE_EVIDENCE_PATH, JSON.stringify(evidence, null, 2));
    writeFileSync(`${process.env.BE_EVIDENCE_PATH}.css`, css);
    writeFileSync(`${process.env.BE_EVIDENCE_PATH}.html`, candidate.replaceAll('><', '>\n<'));
  }
  await browser.close();
}
