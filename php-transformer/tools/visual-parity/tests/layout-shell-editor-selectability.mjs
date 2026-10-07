import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

// Regression coverage for https://github.com/Automattic/blocks-engine/issues/2511:
//
//   1. layout-shell blocks must be selectable in the editor canvas: the
//      block's own tracked DOM node (the one useBlockProps() attaches to)
//      must generate a real box, not `display: contents`, or there is
//      nothing for the rect-based selection/outline/hover system to measure
//      and nothing for a click to land on.
//   2. A wrapper's own authored geometry (captured as an inline `style`
//      attribute, e.g. an explicit width/height or position) must survive
//      exactly in the editor's rendered output. Gutenberg's own useBlockProps()
//      props are merged onto the wrapper, not the other way around, so the
//      wrapper's authored declarations must win over anything Gutenberg adds
//      for the same property.
//
// Both regressions trace to the same mechanism: generating a *separate*,
// newly-created `display:contents` carrier for useBlockProps() instead of
// merging it onto the real, visible outermost wrapper. This test compiles a
// real fixture through the actual generator (so the asserted JS shape can
// never silently drift from what php-transformer emits) and then exercises
// representative "isolated" (pre-fix) vs "merged" (post-fix) DOM shapes in a
// real browser to prove the box-model claims empirically, the same way
// layout-shell-editor-geometry.mjs already does for the InnerBlocks marker.

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const generated = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$html = '<p>Editable content</p>';
for ($depth = 0; $depth < 8; ++$depth) $html = '<div id="shell-' . $depth . '" class="shell-' . $depth . ' blocks-engine-source-div-fixture-3">' . $html . '</div>';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($html)->toArray();
$shellDefinition = null;
foreach ( ($result['source_reports']['generated_blocks'] ?? array()) as $definition ) {
    if ( 'Layout Shell' === ($definition['block_json']['title'] ?? null) ) { $shellDefinition = $definition; break; }
}
echo json_encode(array('script' => (string) ($shellDefinition['assets']['index.js'] ?? '')));
`, transformerRoot], { encoding: 'utf8' }));

assert.match(generated.script, /function sourceBlockProps\( props \)/, 'the generator merges useBlockProps() onto the real wrapper via a named helper');
assert.match(generated.script, /var blockProps = useBlockProps\(\);/, "sourceBlockProps() reads Gutenberg's own block props rather than isolating them");
assert.match(generated.script, /merged\.style = Object\.assign\( \{\}, blockProps\.style \|\| \{\}, props\.style \|\| \{\} \);/, "the wrapper's own authored style is applied after (and so wins over) Gutenberg's style for shared properties");
assert.match(generated.script, /merged\.ref = props\.ref \? mergeRefs\( \[ blockProps\.ref, props\.ref \] \) : blockProps\.ref;/, "Gutenberg's ref is preserved (and composed with the wrapper's own ref, if any) rather than dropped");
assert.doesNotMatch(generated.script, /useBlockProps\( \{ style: \{ display: 'contents' \} \} \)/, 'the generator no longer isolates its own tracked node behind a boxless display:contents carrier');

// --- Symptom 1: selectability --------------------------------------------
//
// A layout-shell with one captured wrapper. The pre-fix shape applies
// useBlockProps() to a *separate* outer div with `display:contents`; the
// real wrapper is only its child. The post-fix shape applies useBlockProps()
// (merged) directly onto the real wrapper, which is what sourceBlockProps()
// above produces.
const isolatedWrapperShape = `
<div class="wp-block-ssi-layout-shell" style="display:contents">
  <section id="hero" style="width:300px;height:150px;position:relative">
    <div class="blocks-engine-layout-shell-editor-inner-blocks" style="display:contents">Copy</div>
  </section>
</div>`;
const mergedWrapperShape = `
<section id="hero" class="wp-block-ssi-layout-shell" style="width:300px;height:150px;position:relative">
  <div class="blocks-engine-layout-shell-editor-inner-blocks" style="display:contents">Copy</div>
</section>`;

// --- Symptom 2: author geometry inflation under a grid/flex ancestor -----
//
// A layout-shell with *no* captured wrappers, directly wrapping a native
// child (mirrors issue #2511's confirmed icon/hero-image reproduction: "a
// layout-shell nested directly inside [a grid-item] ancestor, with the
// [oversized element] as one of its InnerBlocks"). `display:contents` makes
// an element invisible to CSS Grid/Flexbox item placement — its children are
// promoted to become direct items of the nearest non-contents ancestor
// instead. Stacking two contents layers (the isolated block carrier *and*
// the InnerBlocks marker) promotes the native child two levels up, past the
// block's own boundary, into the grid directly; stacking only one (the
// marker alone, post-fix) stops the promotion at the block's own now-boxed
// node, matching how every other block already behaves as a grid item.
const gridCss = '.grid{display:grid;grid-template-columns:200px 200px;grid-auto-rows:400px;align-items:stretch;justify-items:stretch}figure{margin:0}';
const isolatedEmptyShellShape = `
<div class="grid"><div class="wp-block-ssi-layout-shell" style="display:contents"><div class="blocks-engine-layout-shell-editor-inner-blocks" style="display:contents"><figure class="wp-block-image" data-anchor="figure"><img width="200" height="200" style="width:200px;height:200px" data-anchor="icon"></figure></div></div></div>`;
const mergedEmptyShellShape = `
<div class="grid"><div class="wp-block-ssi-layout-shell"><div class="blocks-engine-layout-shell-editor-inner-blocks" style="display:contents"><figure class="wp-block-image" data-anchor="figure"><img width="200" height="200" style="width:200px;height:200px" data-anchor="icon"></figure></div></div></div>`;

const browser = await chromium.launch({ headless: true });
try {
  const trackedNodeRect = async (markup) => {
    const page = await browser.newPage({ viewport: { width: 900, height: 400 } });
    await page.setContent(`<style>*{box-sizing:border-box}body{margin:0}</style>${markup}`);
    const rect = await page.locator('.wp-block-ssi-layout-shell').evaluate((element) => {
      const box = element.getBoundingClientRect();
      return { width: box.width, height: box.height };
    });
    const authoredStyle = await page.locator('.wp-block-ssi-layout-shell').evaluate((element) => {
      const style = getComputedStyle(element);
      return { width: style.width, height: style.height, position: style.position };
    });
    await page.close();
    return { rect, authoredStyle };
  };

  const isolated = await trackedNodeRect(isolatedWrapperShape);
  const merged = await trackedNodeRect(mergedWrapperShape);

  assert.deepEqual(isolated.rect, { width: 0, height: 0 }, 'sanity check: the pre-fix isolated carrier is confirmed boxless (nothing for selection/outline to measure)');
  assert.ok(merged.rect.width > 0 && merged.rect.height > 0, "the block's own tracked node must generate a real, non-empty box so it can be clicked and its selection outline measured");
  assert.deepEqual(merged.rect, { width: 300, height: 150 }, "the tracked node's box must reflect the wrapper's own authored geometry");
  assert.equal(merged.authoredStyle.width, '300px', "the wrapper's own authored width style survives unclobbered by Gutenberg's useBlockProps() props");
  assert.equal(merged.authoredStyle.height, '150px', "the wrapper's own authored height style survives unclobbered by Gutenberg's useBlockProps() props");
  assert.equal(merged.authoredStyle.position, 'relative', "the wrapper's own authored position style survives unclobbered by Gutenberg's useBlockProps() props");

  const captureIcon = async (markup) => {
    const page = await browser.newPage({ viewport: { width: 900, height: 400 } });
    await page.setContent(`<style>*{box-sizing:border-box}body{margin:0}${gridCss}</style>${markup}`);
    const iconRect = await page.locator('[data-anchor="icon"]').evaluate((element) => {
      const box = element.getBoundingClientRect();
      return { width: box.width, height: box.height };
    });
    const figureRect = await page.locator('[data-anchor="figure"]').evaluate((element) => {
      const box = element.getBoundingClientRect();
      return { width: box.width, height: box.height };
    });
    await page.close();
    return { iconRect, figureRect };
  };

  const isolatedGrid = await captureIcon(isolatedEmptyShellShape);
  const mergedGrid = await captureIcon(mergedEmptyShellShape);

  assert.deepEqual(isolatedGrid.figureRect, { width: 200, height: 400 }, 'sanity check: stacking two display:contents layers promotes the native child two levels up, past its own block boundary, into the grid cell directly (full 400px row height)');
  assert.deepEqual(mergedGrid.figureRect, { width: 200, height: 200 }, "merging useBlockProps() onto the block's own node stops the grid-item promotion at the block's own boundary, matching how every other block behaves as a grid item");
  assert.deepEqual(isolatedGrid.iconRect, { width: 200, height: 200 }, "the icon's own explicit inline size is preserved in both shapes (this asserts the fix narrows exactly the grid-item-promotion gap, not an unrelated regression)");
  assert.deepEqual(mergedGrid.iconRect, { width: 200, height: 200 }, "the icon's own explicit inline size is preserved in both shapes (this asserts the fix narrows exactly the grid-item-promotion gap, not an unrelated regression)");
} finally {
  await browser.close();
}

console.log('Layout-shell editor selectability and geometry-promotion passed');
