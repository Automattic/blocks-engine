import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const root = new URL('../../..', import.meta.url).pathname;
const css = `
body{margin:0} p{margin:0;height:20px}
:root :where(.is-layout-flow)>:not(.never){margin-block:24px 0}
.wp-block-group{border-top:3px solid rgb(20,40,60)}
.wp-block-group:hover{border-top-color:rgb(80,100,120)}
.enabled .wp-block-group{border-bottom:5px solid rgb(40,60,80)}
@media(min-width:700px){.wp-block-group{border-left:7px solid rgb(60,80,100)}}
.is-layout-constrained{padding-top:13px}
:is(.wp-block-group):has(.activated){border-right:9px solid rgb(30,50,70)}
.wp-block-group/* .is-layout-flow */{outline-color:rgb(11,22,33)}
.wp-block-\\67 roup{outline-style:solid;outline-width:2px}
[data-note=".is-layout-flow"]{color:rgb(10,20,30)}
`;
const source = '<main id="canvas"><section id="authored" class="is-layout-flow wp-block-group"><p id="one">One</p><p id="two">Two</p></section><section id="plain"><p id="three">Three</p><p id="four">Four</p></section></main>';
const code = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, root, Buffer.from(`<style>${css}</style>${source}`).toString('base64')], { encoding: 'utf8' }));
assert.deepEqual(result.fallbacks, []);
assert.equal(result.source_reports.wp_block_validity.status, 'pass', 'source-owned classes retain valid native serialization');
const projected = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content ?? '').join('\n');
assert.ok(projected.includes('[data-note=".is-layout-flow"]'), 'quoted attribute values stay source text');
const browser = await chromium.launch({ headless: true });
try {
  for (const width of [390, 768, 1440]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const measure = async (html, styles, destination) => {
      await page.setContent(`<!doctype html><style>${styles}</style>${html}`);
      if (destination) {
        // Core adds layout classes during rendering; source provenance must not
        // let that enlarge the carried author rule's subject set.
        await page.locator('.wp-block-group').evaluateAll(groups => groups.forEach(group => group.classList.add('is-layout-flow', 'is-layout-constrained')));
      }
      await page.mouse.move(width - 1, 899);
      const snapshot = () => page.evaluate(() => Object.fromEntries(['authored', 'one', 'two', 'plain', 'three', 'four'].map(id => {
        const element = document.getElementById(id);
        const box = element.getBoundingClientRect();
        const style = getComputedStyle(element);
        return [id, { y: box.y, height: box.height, margin: style.marginBlockStart, padding: style.paddingTop, top: style.borderTopWidth, left: style.borderLeftWidth, right: style.borderRightWidth, bottom: style.borderBottomWidth, color: style.borderTopColor, outline: style.outlineWidth }];
      })));
      const resting = await snapshot();
      await page.locator('#authored').hover();
      const hovering = await snapshot();
      await page.evaluate(() => document.body.classList.add('enabled'));
      const enabled = await snapshot();
      await page.locator('#one').evaluate(element => element.classList.add('activated'));
      const relational = await snapshot();
      return { resting, hovering, enabled, relational };
    };
    const before = await measure(source, css, false);
    const after = await measure(result.serialized_blocks, projected, true);
    console.log(JSON.stringify({ width, source: before, destination: after }));
    assert.equal(before.resting.one.margin, '24px', 'source flow spacing exists');
    assert.equal(before.resting.three.margin, '0px', 'source plain subject has no flow spacing');
    assert.equal(before.hovering.authored.color, 'rgb(80, 100, 120)', 'source hover fires');
    assert.equal(before.enabled.authored.bottom, '5px', 'source dormant ancestor state fires');
    assert.equal(before.relational.authored.right, '9px', 'unsupported relational selector fires on its source owner');
    assert.equal(before.resting.authored.outline, '2px', 'hex-escaped source class fires');
    assert.deepEqual(after, before, `source-owned cascade and geometry at ${width}px`);
    await page.close();
  }
  console.log('OK: source class selector ownership (3 viewports, rest/hover/ancestor/relational state)');
} finally {
  await browser.close();
}
