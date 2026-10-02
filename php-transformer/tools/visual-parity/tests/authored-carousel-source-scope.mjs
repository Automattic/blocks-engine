import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const compile = (width, height, color) => JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
use Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer;
$width = (int) $argv[2];
$height = (int) $argv[3];
$color = $argv[4];

$html = '<style>'
    . '@supports (--test-custom-property:true){.user-items-list-item-container[data-section-id="review-42"]{--title-font-size-value:1.6;--responsive-scale:1}}'
    . '.user-items-list-item-container[data-section-id="review-42"] .quote{font-size:calc((var(--title-font-size-value) - 1) * 1.2vw + 1rem);text-align:center}'
    . '@media(max-width:767px){.user-items-list-item-container[data-section-id="review-42"] .quote{font-size:calc((var(--title-font-size-value) - 1) * calc(.012 * min(100vh,900px)) + 1rem)}}'
    . '@media(max-width:600px){.user-items-list-item-container[data-section-id="review-42"]{--responsive-scale:2}}'
    . '.user-items-list-item-container[data-section-id="review-42"] .responsive-variable{font-size:calc(var(--responsive-scale)*16px)}'
    . '.runtime-slideshow .slide{transform:translateX(-9999px)}'
    . '.user-items-list-banner-slideshow .slide{transform:translateX(-9999px)}'
    . '.user-items-list-banner-slideshow__arrow-button{width:' . $width . 'px;height:' . $height . 'px;border-radius:5px;background-color:' . $color . ';color:white}'
    . '.user-items-list-banner-slideshow[data-section-id="review-42"] .arrow-background{width:40px;height:40px;background-color:white;position:absolute;border-radius:50%}'
    . '.user-items-list-banner-slideshow .mobile-arrows{justify-content:center;position:absolute;bottom:20px;left:0;width:100%}'
    . '.mobile-arrow-button{width:48px;height:48px;border-radius:50%}.mobile-arrows{display:none}@media(max-width:600px){.mobile-arrows{display:flex}.desktop-arrows{display:none}}'
    . '.arrows-bottom{display:flex;justify-content:center;gap:7px}</style>'
    . '<div class="user-items-list-item-container user-items-list-banner-slideshow runtime-slideshow" data-section-id="review-42" data-navigation-placement="bottom">'
    . '<ul class="slides"><li class="slide"><h2 class="quote">First review</h2><h3 class="responsive-variable">Conditional scale</h3></li>'
    . '<li class="slide" aria-hidden="true"><h2 class="quote">Second review</h2></li></ul>'
    . '<div class="mobile-arrows"><button class="mobile-arrow-button" aria-label="Previous"><div class="arrow-background"></div><svg viewBox="0 0 24 14"><path d="M2 7H22"/></svg></button>'
    . '<button class="mobile-arrow-button" aria-label="Next"><div class="arrow-background"></div><svg viewBox="0 0 24 14"><path d="M2 7H22"/></svg></button></div>'
    . '<div class="desktop-arrows arrows-bottom-outer"><div class="arrows-bottom-wrapper"><div class="arrows-bottom"><button class="user-items-list-banner-slideshow__arrow-button" aria-label="Previous"><div class="arrow-background"></div><svg viewBox="0 0 24 14"><path d="M2 7H22"/></svg></button>'
    . '<button class="user-items-list-banner-slideshow__arrow-button" aria-label="Next"><div class="arrow-background"></div><svg viewBox="0 0 24 14"><path d="M2 7H22"/></svg></button></div></div></div></div>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$definition = $result['source_reports']['generated_blocks'][0] ?? array();
$sourceCss = implode("\\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
echo json_encode(array(
    'markup' => (string) ($result['serialized_blocks'] ?? ''),
    'style' => (string) ($definition['assets']['style.css'] ?? '') . "\\n" . $sourceCss,
));
`, transformerRoot, String(width), String(height), color], { encoding: 'utf8' }));

const variants = [compile(38, 24, 'navy'), compile(52, 30, 'maroon')];

const compileHtml = (html) => JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
use Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer;
$result = (new HtmlTransformer())->transform(base64_decode($argv[2]))->toArray();
$definition = $result['source_reports']['generated_blocks'][0] ?? array();
$sourceCss = implode("\\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
echo json_encode(array('markup' => (string) ($result['serialized_blocks'] ?? ''), 'style' => (string) ($definition['assets']['style.css'] ?? ''), 'sourceStyle' => $sourceCss));
`, transformerRoot, Buffer.from(html).toString('base64')], { encoding: 'utf8' }));

const neutralFixtures = [
  {
    name: 'quartz', threshold: 500,
    narrow: { width: 32, height: 22, color: 'rgb(191, 35, 58)' },
    wide: { width: 56, height: 34, color: 'rgb(24, 74, 153)' },
    html: `<style>
.quartz-compact{display:none}.quartz-expanse{display:flex}
.quartz-compact button{width:32px;height:22px;background:#bf233a;color:white}
.quartz-expanse button{width:56px;height:34px;background:#184a99;color:white}
@media(max-width:500px){.quartz-compact{display:flex}.quartz-expanse{display:none}}
</style><div class="story-slider-quartz"><div role="list"><div role="listitem">Quartz one</div><div role="listitem">Quartz two</div></div>
<div class="quartz-compact"><button aria-label="Previous slide">P</button><button aria-label="Next slide">N</button></div>
<div class="quartz-expanse"><div class="quartz-depth-one"><div class="quartz-depth-two"><button aria-label="Previous slide">P</button><button aria-label="Next slide">N</button></div></div></div></div>`,
  },
  {
    name: 'cedar', threshold: 900,
    narrow: { width: 44, height: 28, color: 'rgb(36, 128, 83)' },
    wide: { width: 68, height: 40, color: 'rgb(179, 105, 24)' },
    html: `<style>
.cedar-pocket{display:none}.cedar-span{display:flex}
.cedar-pocket button{width:44px;height:28px;background:#248053;color:white}
.cedar-span button{width:68px;height:40px;background:#b36918;color:white}
@media(max-width:900px){.cedar-pocket{display:flex}.cedar-span{display:none}}
</style><div class="story-slider-cedar"><div role="list"><div role="listitem">Cedar one</div><div role="listitem">Cedar two</div></div>
<div class="cedar-pocket"><div class="cedar-depth"><button aria-label="Previous slide">P</button><button aria-label="Next slide">N</button></div></div>
<div class="cedar-span"><button aria-label="Previous slide">P</button><button aria-label="Next slide">N</button></div></div>`,
  },
].map((fixture) => ({ ...fixture, compiled: compileHtml(fixture.html) }));

for (const compiled of variants) {
  assert.doesNotMatch(compiled.markup.match(/<div class="blocks-engine-authored-carousel[^>]+>/)?.[0] || '', /runtime-slideshow/);
  assert.match(compiled.markup, /data-section-id="review-42"/);
  assert.doesNotMatch(compiled.markup, /--title-font-size-value:1\.6/);
  assert.match(compiled.markup, /sourceControlClasses[^,]*user-items-list-banner-slideshow/);
  assert.match(compiled.markup, /sourceControlAttributes[^}]*data-section-id/);
  assert.match(compiled.markup, /class="blocks-engine-authored-carousel[^\"]*user-items-list-item-container/);
  assert.doesNotMatch(compiled.markup.match(/<div class="blocks-engine-authored-carousel[^>]+>/)?.[0] || '', /user-items-list-banner-slideshow/);
  assert.match(compiled.markup, /arrow-background/);
  assert.match(compiled.markup, /sourceControlTopology/);
  assert.match(compiled.markup, /class="mobile-arrows"/);
  assert.match(compiled.markup, /class="arrows-bottom"/);
  assert.match(compiled.markup, /sourceControlTopology[^,]*desktop-arrows arrows-bottom-outer/);
  assert.doesNotMatch(compiled.style.match(/\.blocks-engine-authored-carousel__controls\{[^}]*\}/)?.[0] || '', /600px/);
  assert.match(compiled.markup, /<svg/);
  assert.doesNotMatch(compiled.style, /var\(--title-font-size-value,1\.6\)/);
}

const browser = await chromium.launch({ headless: true });
try {
  for (const [variant, expectedControlWidth, expectedControlHeight] of [[variants[0], 38, 24], [variants[1], 52, 30]]) {
    for (const width of [390, 768, 1440]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    await page.setContent(`<style>body{margin:0}${variant.style}</style>${variant.markup}`);
    const result = await page.evaluate(() => {
      const root = document.querySelector('.blocks-engine-authored-carousel');
      const quote = root.querySelector('.quote');
      const responsiveVariable = root.querySelector('.responsive-variable');
      const controls = [...root.querySelectorAll('[data-carousel-previous],[data-carousel-next]')];
      const backgroundShapes = controls.map((control) => control.querySelector('.arrow-background'));
      const quoteRect = quote.getBoundingClientRect();
      const controlRects = controls.map((control) => control.getBoundingClientRect()).filter((rect) => rect.width > 0);
      return {
        quoteX: quoteRect.x,
        quoteFontSize: getComputedStyle(quote).fontSize,
        responsiveScale: getComputedStyle(responsiveVariable).getPropertyValue('--responsive-scale').trim(),
        responsiveVariableFontSize: getComputedStyle(responsiveVariable).fontSize,
        sourceBackgrounds: backgroundShapes.map((shape) => ({ width: getComputedStyle(shape).width, color: getComputedStyle(shape).backgroundColor })),
        controlRects: controls.map((control) => ({ className: control.className, minWidth: getComputedStyle(control).minWidth, cssWidth: getComputedStyle(control).width, ...((({ x, width, height }) => ({ x, width, height }))(control.getBoundingClientRect())) })).filter((rect) => rect.width > 0),
        controlCenter: (controlRects[0].left + controlRects[1].right) / 2,
        controlBottom: Math.max(...controlRects.map((rect) => rect.bottom)),
        rootBottom: root.getBoundingClientRect().bottom,
      };
    });
    assert.ok(result.quoteX >= 0 && result.quoteX < width, `${width}px active quote remains in view: ${JSON.stringify(result)}`);
    const expectedFontSize = width < 768 ? 22.48 : (width < 1280 ? 21.5296 : 26.368);
    assert.ok(Math.abs(Number.parseFloat(result.quoteFontSize) - expectedFontSize) < 0.02, `${width}px source-derived typography survives: ${JSON.stringify(result)}`);
    const expectedScale = width <= 600 ? '2' : '1';
    assert.equal(result.responsiveScale, expectedScale, `${width}px source conditional custom property remains stylesheet-owned: ${JSON.stringify(result)}`);
    assert.equal(result.responsiveVariableFontSize, width <= 600 ? '32px' : '16px', `${width}px source conditional custom property determines presentation: ${JSON.stringify(result)}`);
    assert.ok(result.sourceBackgrounds.every((shape) => shape.width === '40px' && shape.color === 'rgb(255, 255, 255)'), `${width}px source control child shape retains its scoped selector styling: ${JSON.stringify(result)}`);
    assert.ok(Math.abs(result.controlCenter - width / 2) < 1, `${width}px controls are centered: ${JSON.stringify(result)}`);
    assert.equal(result.controlRects[0].width, width < 601 ? 48 : expectedControlWidth, `${width}px source control width is retained: ${JSON.stringify(result)}`);
    assert.equal(result.controlRects[0].height, width < 601 ? 48 : expectedControlHeight, `${width}px source control height is retained`);
    assert.ok(result.controlRects[0].x < result.controlRects[1].x, `${width}px previous precedes next`);
    assert.ok(result.controlBottom <= result.rootBottom + 6, `${width}px source-positioned controls remain inside the slide tolerance: ${JSON.stringify(result)}`);
    await page.close();
    }
  }
  for (const fixture of neutralFixtures) {
    assert.match(fixture.compiled.markup, new RegExp(`quartz|cedar`));
    const generatedStyle = fixture.compiled.style;
    assert.doesNotMatch(generatedStyle, /(?:mobile-arrows|desktop-arrows|arrows-bottom)/);
    for (const width of [390, 768, 1440]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      await page.setContent(`<style>body{margin:0}${fixture.compiled.style}${fixture.compiled.sourceStyle}</style>${fixture.compiled.markup}`);
      const rendered = await page.evaluate(() => {
        const root = document.querySelector('.blocks-engine-authored-carousel');
        const controls = [...root.querySelectorAll('[data-carousel-previous],[data-carousel-next]')].filter((control) => getComputedStyle(control.parentElement).display !== 'none' && control.getBoundingClientRect().width > 0);
        return controls.map((control) => ({ width: control.getBoundingClientRect().width, height: control.getBoundingClientRect().height, background: getComputedStyle(control).backgroundColor, action: control.getAttribute('data-wp-on--click') }));
      });
      const expected = width <= fixture.threshold ? fixture.narrow : fixture.wide;
      assert.equal(rendered.length, 2, `${fixture.name} ${width}px displays one source-derived pair: ${JSON.stringify(rendered)}`);
      assert.ok(rendered.every((control) => control.width === expected.width && control.height === expected.height && control.background === expected.color), `${fixture.name} ${width}px follows the authored ${fixture.threshold}px variant: ${JSON.stringify(rendered)}`);
      assert.deepEqual(rendered.map(({ action }) => action).sort(), ['actions.next', 'actions.previous']);
      await page.close();
    }
  }
} finally {
  await browser.close();
}

console.log('Authored carousel source-scope/browser regression passed');
