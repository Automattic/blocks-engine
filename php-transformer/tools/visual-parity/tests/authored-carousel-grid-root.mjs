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
  'ID-scoped important mirror': (html) => html.replace('class="slideshow"', 'id="hero" class="slideshow"').replace('.next{justify-self:end;transform:scaleX(-1)}', '.next{justify-self:end}#hero .next{transform:scaleX(-1)!important}'),
  'responsive mirror': (html) => html.replace('</style>', '@media(max-width:600px){.next{transform:scaleX(1)}} </style>'),
  'layered combined transform': (html) => html.replace('transform:scaleX(-1)', 'transform:translateY(-50%) scaleX(-1)').replace('.next{justify-self:end;transform:translateY(-50%) scaleX(-1)}', '@layer source{.next{justify-self:end;transform:translateY(-50%) scaleX(-1)}}'),
  'combined transform': (html) => html.replace('transform:scaleX(-1)', 'transform:translateY(-50%) scaleX(-1)'),
  'important mirror': (html) => html.replace('transform:scaleX(-1)}', 'transform:scaleX(-1)!important}'),
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
    for (const width of [390, 768, 1440]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      await page.setContent(`<style>${output.style}</style><div id="source">${source}</div><div id="generated">${output.markup}</div>`);
      const geometry = await page.evaluate(() => {
        const rect = (element) => { const box = element.getBoundingClientRect(); return { x: box.x, y: box.y, width: box.width, height: box.height }; };
        const transform = (element) => getComputedStyle(element).transform;
        const mirrored = (element) => new DOMMatrixReadOnly(transform(element)).a < 0;
        const read = (root, stage, previous, next) => ({
          stage: rect(root.querySelector(stage)),
          image: rect(root.querySelector('img')),
          previous: { ...rect(root.querySelector(previous)), mirrored: mirrored(root.querySelector(previous)) },
          next: { ...rect(root.querySelector(next)), mirrored: mirrored(root.querySelector(next)), transform: transform(root.querySelector(next)) },
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
      assert.equal(after.next.mirrored, before.next.mirrored, `next arrow keeps its authored mirror: ${label}`);
      assert.ok(!before.previous.mirrored && !after.previous.mirrored, `previous arrow is not mirrored: ${label}`);
      const middle = (box, stage) => box.y + box.height / 2 - stage.y;
      if (name === 'responsive mirror' || name.includes('combined transform')) {
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
