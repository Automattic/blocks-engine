import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const fixtures = [
  { minHeight: '50vh', expectedAt900: 450, kind: 'svg' },
  { minHeight: 'calc(30vh + 2rem)', expectedAt900: 302, kind: 'text' },
  { minHeight: 'min(40vh, 360px)', expectedAt900: 360, kind: 'empty' },
  { minHeight: 'var(--stage-size)', expectedAt900: (width) => width <= 600 ? 450 : 270, kind: 'text', conditional: true },
];
const compile = (fixture) => {
  const previousVisual = fixture.kind === 'svg' ? '<svg viewBox="0 0 20 10"><path d="M19 5H1"/></svg>' : fixture.kind === 'text' ? 'Previous' : '';
  const nextVisual = fixture.kind === 'svg' ? '<svg viewBox="0 0 20 10"><path d="M1 5H19"/></svg>' : fixture.kind === 'text' ? 'Next' : '';
  const html = `<style>
    *{box-sizing:border-box}.story-slider-amber{position:relative;width:100%;margin:0 0 18px;--stage-size:30vh}@media(max-width:600px){.story-slider-amber{--stage-size:50vh}}
    .stage-gutter{padding-inline:2vw}.stage-holder{width:100%}.slides{margin:0;padding:0;list-style:none}
    .slides>li{list-style:none}.quote{width:90%;margin:0 auto;text-align:center;font:500 20px/1.4 sans-serif}
    .actions{position:absolute;inset:auto 0 12px;display:flex;justify-content:center;gap:12px}
    .actions button{width:44px;height:40px;border:0;border-radius:50%;background:#263b59;color:white}
    .action-next::before{content:"›"}.action-previous::before{content:"‹"}
    .below{height:40px;background:#ddd}
  </style><section class="story-slider-amber"><div class="stage-gutter"><div class="stage-holder"><ul class="slides" style="min-height:${fixture.minHeight}"><li><h2 class="quote">A responsive authored testimonial stays centered and wraps from its source-owned quote width.</h2></li><li aria-hidden="true"><h2 class="quote">A second authored testimonial keeps the same stable viewport geometry.</h2></li></ul></div></div><div class="actions"><button class="action-previous" aria-label="Previous slide">${previousVisual}</button><button class="action-next" aria-label="Next slide">${nextVisual}</button></div></section><div class="below"></div>`;
  const output = JSON.parse(execFileSync('php', ['-r', `
    require $argv[1] . '/vendor/autoload.php';
    $result = (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray();
    $definition = $result['source_reports']['generated_blocks'][0] ?? array();
    $styles = implode("\\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
    echo json_encode(array('markup' => (string) ($result['serialized_blocks'] ?? ''), 'style' => (string) ($definition['assets']['style.css'] ?? '') . "\\n" . $styles));
  `, transformerRoot, Buffer.from(html).toString('base64')], { encoding: 'utf8' }));
  assert.match(output.markup, /blocks-engine-authored-carousel/);
  assert.match(output.markup, new RegExp(`--blocks-engine-carousel-stage-min-height:${fixture.minHeight.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`));
  assert.match(output.markup, /stage-gutter/);
  assert.match(output.markup, /stage-holder/);
  return { html, ...output };
};

const browser = await chromium.launch({ headless: true });
try {
  for (const fixture of fixtures) {
    const compiled = compile(fixture);
    for (const width of [390, 768, 1440]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      await page.setContent(`<style>body{margin:0}${compiled.style}</style><div id="source">${compiled.html}</div><div id="generated">${compiled.markup}<div class="below"></div></div>`);
      const geometry = await page.evaluate(() => {
        const read = (scope) => {
          const root = scope.querySelector('.story-slider-amber, .blocks-engine-authored-carousel');
          const stage = root.querySelector('.slides, .blocks-engine-authored-carousel__viewport');
          const track = root.querySelector('.blocks-engine-authored-carousel__track');
          const quote = root.querySelector('.quote');
          const buttons = [...root.querySelectorAll('button')];
          const rect = (element) => { const r = element.getBoundingClientRect(); return { x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom }; };
          const next = scope.querySelector('.below');
          return { root:rect(root),rootClass:root.className,rootMargin:getComputedStyle(root).marginBottom,stage:rect(stage),track:track?rect(track):null,quote:rect(quote),quoteText:quote.innerText,controls:buttons.map(rect),controlCenter:(buttons[0].getBoundingClientRect().left+buttons[1].getBoundingClientRect().right)/2,belowY:next.getBoundingClientRect().top,buttons:buttons.map(b=>({pseudo:getComputedStyle(b,'::before').content,svg:!!b.querySelector('svg'),text:b.innerText.trim()})) };
        };
        return { source:read(document.querySelector('#source')), generated:read(document.querySelector('#generated')) };
      });
      const source = geometry.source, generated = geometry.generated;
      const label = `${fixture.minHeight} @ ${width}`;
      const expectedHeight = typeof fixture.expectedAt900 === 'function' ? fixture.expectedAt900(width) : fixture.expectedAt900;
      assert.ok(Math.abs(source.stage.height - expectedHeight) < 1, `${label} source stage is authored height: ${JSON.stringify(source)}`);
      assert.ok(Math.abs(source.stage.height - generated.stage.height) < 1, `${label} stage rect matches source: ${JSON.stringify(geometry)}`);
      assert.ok(Math.abs(source.stage.x - generated.stage.x) < 1 && Math.abs(source.stage.width - generated.stage.width) < 1, `${label} holder gutter/width matches source: ${JSON.stringify(geometry)}`);
      assert.ok(Math.abs(source.quote.width-generated.quote.width)<1 && Math.abs(source.quote.height-generated.quote.height)<1, `${label} quote width/wrapping matches source: ${JSON.stringify(geometry)}`);
      assert.ok(Math.abs((source.belowY-source.root.y)-(generated.belowY-generated.root.y))<1, `${label} following section flow matches source: ${JSON.stringify(geometry)}`);
      assert.ok(Math.abs(source.controlCenter-width/2)<1 && Math.abs(generated.controlCenter-width/2)<1, `${label} controls remain centered`);
      if (fixture.kind === 'svg') {
        assert.ok(generated.buttons.every((button)=>button.svg && button.pseudo==='none'), `${label} SVG artwork owns controls without runtime duplicate glyphs: ${JSON.stringify(generated.buttons)}`);
      } else if (fixture.kind === 'text') {
        assert.ok(generated.buttons.every((button)=>!button.svg && button.text && button.pseudo==='none'), `${label} plain labels own controls without pseudo glyph fallback: ${JSON.stringify(generated.buttons)}`);
      } else {
        assert.ok(generated.buttons.every((button)=>!button.svg && !button.text && button.pseudo!=='none'), `${label} empty source controls retain generic fallback glyphs: ${JSON.stringify(generated.buttons)}`);
      }
      await page.close();
    }
  }
} finally { await browser.close(); }
console.log('Authored carousel source/generated stage geometry and artwork ownership passed');
