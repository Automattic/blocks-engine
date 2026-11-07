import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

// A slideshow whose own root is a grid keeps that display in WordPress. Its
// absolutely positioned slides and mirrored next arrow must still match.
const here = path.dirname(fileURLToPath(import.meta.url));
const transformerRoot = path.resolve(here, '../../..');
const fixture = readFileSync(path.join(here, 'fixtures/grid-slideshow-root.html'), 'utf8');
const variants = {
  base: (html) => html,
  // A source column template must not narrow the stage to one column.
  'source columns': (html) => html.replace('margin:0 auto}', 'margin:0 auto;grid-template-columns:repeat(3,1fr)}'),
  // An equally specific root-scoped rule must not undo the centering.
  'root-scoped mirror': (html) => html.replace('.next{justify-self:end;transform:scaleX(-1)}', '.next{justify-self:end}.slideshow .next{transform:scaleX(-1)}'),
  'responsive mirror': (html) => html.replace('</style>', '@media(max-width:600px){.next{transform:scaleX(1)}} </style>'),
  'combined transform': (html) => html.replace('transform:scaleX(-1)', 'transform:translateY(-50%) scaleX(-1)'),
  'important mirror': (html) => html.replace('transform:scaleX(-1)}', 'transform:scaleX(-1)!important}'),
  'id important mirror': (html) => html.replace('.next{justify-self:end;transform:scaleX(-1)}', '#slideshow .next{justify-self:end;transform:scaleX(-1)!important}').replace('<div class="slideshow"', '<div id="slideshow" class="slideshow"'),
  'layered mirror': (html) => html.replace('.next{justify-self:end;transform:scaleX(-1)}', '@layer source{.next{justify-self:end;transform:scaleX(-1)}}'),
  'layered important mirror': (html) => html.replace('.next{justify-self:end;transform:scaleX(-1)}', '@layer source{.next{justify-self:end;transform:scaleX(-1)!important}}'),
  'individual rotation': (html) => html.replace('transform:scaleX(-1)', 'rotate:30deg;transform:scaleX(-1)'),
  'inline important mirror': (html) => html.replace('transform:scaleX(-1)', '').replace('<button class="arrow next"', '<button style="transform:scaleX(-1)!important" class="arrow next"'),
  'rotated arrow': (html) => html.replace('transform:scaleX(-1)', 'transform:rotate(30deg)'),
  // A rotation after one flip turns the other way once it becomes a setting.
  'flip then rotation': (html) => html.replace('transform:scaleX(-1)', 'transform:scaleX(-1) rotate(30deg)'),
  // A size change has no setting, so the source rule keeps it.
  'scaled arrow': (html) => html.replace('transform:scaleX(-1)', 'transform:scale(1.5)'),
};
const compile = (source) => JSON.parse(execFileSync('php', ['-r', `
  require $argv[1] . '/vendor/autoload.php';
  $result = (new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(array(
      'entrypoint' => 'index.html',
      'files' => array('index.html' => base64_decode($argv[2])),
  ))->toArray();
  $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
  $markup = (string) ($plan['pages'][0]['canonical_block_markup'] ?? '');
  $definition = (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\Generators\\AuthoredCarouselBlockGenerator())->definition('custom');
  $styles = implode("\\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $plan['assets'] ?? array()));
  echo json_encode(array('markup' => $markup, 'style' => (string) ($definition['assets']['style.css'] ?? '') . "\\n" . $styles));
`, transformerRoot, Buffer.from(source).toString('base64')], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));

const browser = await chromium.launch({ headless: true });
try {
  for (const [name, variant] of Object.entries(variants)) {
    const source = variant(fixture);
    assert.ok(name === 'base' || source !== fixture, `${name} variant changes the fixture`);
    const output = compile(source);
    assert.match(output.markup, /blocks-engine-authored-carousel--slideshow/);
    assert.ok(!output.markup.includes('"transform"') && !output.markup.includes('control-transform'), `${name} stores no raw transform: ${output.markup.slice(0, 200)}`);
    if (name === 'base' || name.includes('important mirror') || name === 'layered mirror') {
      assert.match(output.markup, /"nextControlOrientation":\{"flipHorizontal":true,"flipVertical":false,"rotation":0\}/);
      assert.match(output.markup, /blocks-engine-authored-carousel__next[^"]*is-flip-horizontal/);
    }
    if (name === 'flip then rotation') {
      assert.match(output.markup, /"nextControlOrientation":\{"flipHorizontal":true,"flipVertical":false,"rotation":330\}/);
    }
    if (name === 'scaled arrow' || name === 'responsive mirror' || name === 'individual rotation') {
      assert.doesNotMatch(output.markup, /__control--oriented/);
    }
    for (const width of [390, 768, 1440]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      await page.setContent(`<style>${output.style}</style><div id="source">${source}</div><div id="generated">${output.markup}</div>`);
      const geometry = await page.evaluate(() => {
        const rect = (element) => { const box = element.getBoundingClientRect(); return { x: box.x, y: box.y, width: box.width, height: box.height }; };
        const transform = (element) => getComputedStyle(element).transform;
        const orientation = (element) => {
          const style = getComputedStyle(element);
          const scale = 'none' === style.scale ? [1, 1] : style.scale.split(' ').map(Number);
          const matrix = new DOMMatrix().rotate('none' === style.rotate ? 0 : parseFloat(style.rotate)).scale(scale[0], scale[1] ?? scale[0]).multiply(new DOMMatrix(transform(element)));
          return [matrix.a, matrix.b, matrix.c, matrix.d].map((value) => Math.round(value * 1000) / 1000 + 0);
        };
        const mirrored = (element) => orientation(element)[0] < 0;
        const read = (root, stage, previous, next) => ({
          stage: rect(root.querySelector(stage)),
          image: rect(root.querySelector('img')),
          previous: { ...rect(root.querySelector(previous)), mirrored: mirrored(root.querySelector(previous)) },
          next: { ...rect(root.querySelector(next)), mirrored: mirrored(root.querySelector(next)), orientation: orientation(root.querySelector(next)), transform: transform(root.querySelector(next)) },
        });
        return {
          source: read(document.querySelector('#source'), '.slides', '.prev', '.next'),
          generated: read(document.querySelector('#generated'), '.blocks-engine-authored-carousel__viewport', '.blocks-engine-authored-carousel__previous', '.blocks-engine-authored-carousel__next'),
        };
      });
      const { source: before, generated: after } = geometry;
      const label = `${name} @ ${width}px ${JSON.stringify(geometry)}`;
      assert.ok(Math.abs(before.stage.width - after.stage.width) < 1, `stage fills the grid root: ${label}`);
      assert.ok(Math.abs(after.image.width - after.stage.width) < 1, `active slide image fills the stage: ${label}`);
      assert.deepEqual(after.next.orientation, before.next.orientation, `next arrow keeps its authored orientation: ${label}`);
      assert.ok(!before.previous.mirrored && !after.previous.mirrored, `previous arrow is not mirrored: ${label}`);
      const middle = (box, stage) => box.y + box.height / 2 - stage.y;
      if (name === 'responsive mirror' || name === 'scaled arrow' || name.includes('combined transform') || name === 'individual rotation') {
        assert.equal(after.next.transform, before.next.transform, `uncaptured source transform survives: ${label}`);
      } else {
        assert.ok(Math.abs(middle(after.next, after.stage) - after.stage.height / 2) < 1, `next arrow stays vertically centered: ${label}`);
      }
      await page.close();
    }
  }
} finally {
  await browser.close();
}
console.log('Authored slideshow on a grid source root keeps its stage width and arrow orientation');
