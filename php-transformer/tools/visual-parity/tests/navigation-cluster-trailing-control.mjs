import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const root = new URL('../../..', import.meta.url).pathname;
const source = readFileSync(`${root}/tests/fixtures/navigation-cluster-trailing-control.html`, 'utf8');
const result = JSON.parse(execFileSync('php', ['-r', 'require $argv[1] . "/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());', root, Buffer.from(source).toString('base64')], { encoding: 'utf8' }));
const find = (blocks, name) => blocks.flatMap(block => [...(block.blockName === name ? [block] : []), ...find(block.innerBlocks ?? [], name)]);
const menu = find(result.blocks, 'core/navigation')[0];
assert.equal(menu.innerBlocks.length, 3);
// Materialize the dynamic navigation block using its emitted classes and links,
// as in the other product-neutral navigation browser regressions.
const nav = `<nav class="wp-block-navigation ${menu.attrs.className ?? ''}"><ul class="wp-block-navigation__container">${menu.innerBlocks.map(link => `<li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content" href="${link.attrs.url}">${link.attrs.label}</a></li>`).join('')}</ul></nav>`;
const core = '.wp-block-navigation__container{display:flex;list-style:none;padding:0;margin:0}.wp-block-image img{max-width:100%;height:auto}.wp-block-image{margin:0}';
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
const target = `<style>${core}\n${css}</style>${result.serialized_blocks.replace(/<!-- wp:navigation\s[^]*?<!-- \/wp:navigation -->/, nav)}`;
const browser = await chromium.launch({ headless: true });
try {
  for (const width of [390, 1440]) {
    const measurements = [];
    for (const html of [source, target]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      await page.setContent(html);
      const measured = await page.evaluate(() => {
        const logo = document.querySelector('.navbar-brand img').getBoundingClientRect();
        const panel = document.querySelector('.navbar-collapse');
        const links = [...panel.querySelectorAll('a')].map(a => { const r = a.getBoundingClientRect(); return { x: r.x, y: r.y }; });
        return { logo: [logo.width, logo.height], panelDisplay: getComputedStyle(panel).display, links };
      });
      assert.deepEqual(measured.logo, [200, 36]);
      if (width === 390) assert.equal(measured.panelDisplay, 'none');
      else {
        assert.equal(measured.panelDisplay, 'flex');
        assert.equal(new Set(measured.links.map(link => link.y)).size, 1);
        assert.ok(measured.links[0].x < measured.links[1].x && measured.links[1].x < measured.links[2].x);
      }
      measurements.push(measured);
      await page.close();
    }
    console.log(JSON.stringify({ width, source: measurements[0], converted: measurements[1] }));
  }
} finally {
  await browser.close();
}
