import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const generated = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$html = '<section class="hero"><div class="background"></div><div class="content">Copy</div></section>';
for ($depth = 0; $depth < 8; ++$depth) $html = '<div id="shell-' . $depth . '" class="shell-' . $depth . ' blocks-engine-source-div-fixture-3">' . $html . '</div>';
$html = '<style>.hero{position:relative;width:600px;height:220px}.background{position:absolute;inset:0}.content{position:relative;padding:40px}</style>' . $html;
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($html)->toArray();
echo json_encode(array('css' => implode("\\n", array_map(static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $result['assets'] ?? array()))));
`, transformerRoot], { encoding: 'utf8' }));

const shellClass = generated.css.match(/wp-block-[a-z0-9-]*layout-shell/)?.[0];
assert.ok(shellClass, 'the reproduction must compile a layout shell');

const sourceMarkup = '<div class="shell blocks-engine-source-div-outer-3"><section class="hero blocks-engine-source-section-branch-3" data-anchor="hero"><div class="background" data-anchor="background"></div><div class="content" data-anchor="content">Copy</div></section></div>';
const editorMarkup = `<div class="${shellClass}" style="display:contents"><div class="shell blocks-engine-source-div-outer-3"><section class="hero blocks-engine-source-section-branch-3" data-anchor="hero"><div class="blocks-engine-layout-shell-editor-inner-blocks block-editor-block-list__layout"><div class="background block-editor-block-list__block block-editor-block-list__layout" data-anchor="background"></div><div class="content block-editor-block-list__block block-editor-block-list__layout" data-anchor="content">Copy</div></div></section></div></div>`;
const authorCss = `
  * { box-sizing:border-box } body { margin:0 }
  .hero { position:relative; width:600px; height:220px }
  .background { position:absolute; inset:0; background:#222 }
  .content { position:relative; padding:40px; color:white }
`;
// This is the owning Gutenberg editor layer. Before the compat rule reaches it,
// it becomes the positioned containing block and shrinks the absolute sibling.
const editorCss = '.blocks-engine-layout-shell-editor-inner-blocks{position:relative;width:360px}';

const browser = await chromium.launch({ headless: true });
try {
  const source = await browser.newPage({ viewport: { width: 900, height: 400 } });
  const editor = await browser.newPage({ viewport: { width: 900, height: 400 } });
  await source.setContent(`<style>${authorCss}</style>${sourceMarkup}`);
  await editor.setContent(`<style>${authorCss}${editorCss}${generated.css}</style>${editorMarkup}`);

  const capture = (page) => page.locator('[data-anchor]').evaluateAll((elements) => Object.fromEntries(elements.map((element) => {
    const rect = element.getBoundingClientRect();
    const style = getComputedStyle(element);
    return [element.dataset.anchor, {
      rect: { x: rect.x, y: rect.y, width: rect.width, height: rect.height },
      position: style.position,
      display: style.display,
    }];
  })));
  const sourceGeometry = await capture(source);
  const editorGeometry = await capture(editor);
  const editorLayer = await editor.locator('.blocks-engine-layout-shell-editor-inner-blocks').evaluate((element) => ({
    display: getComputedStyle(element).display,
    position: getComputedStyle(element).position,
  }));

  assert.deepEqual(editorGeometry, sourceGeometry, 'editor anchors preserve frontend geometry for an absolute background/content sibling pair');
  assert.deepEqual(editorLayer, { display: 'contents', position: 'relative' }, 'the owning editor layer is box-neutral while retaining Gutenberg computed styles');
  assert.equal(editorGeometry.background.display, 'block', 'native child block carriers retain authored boxes');
  assert.equal(editorGeometry.content.display, 'block', 'native child layout carriers are not mistaken for editor wrappers');
  assert.equal(editorGeometry.background.position, 'absolute');
  assert.equal(editorGeometry.content.position, 'relative');
} finally {
  await browser.close();
}

console.log('Layout-shell editor geometry passed');
