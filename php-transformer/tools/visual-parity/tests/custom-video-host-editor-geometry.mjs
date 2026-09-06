import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const transformed = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform('<media-host class="video-frame"><video src="hero.mp4" poster="hero.jpg"><track kind="captions" src="captions.vtt"></video><div><img src="hero.jpg" alt=""></div></media-host>')->toArray();
echo json_encode($result['blocks'][0]);
`, transformerRoot], { encoding: 'utf8' }));

assert.equal(transformed.blockName, 'core/group', 'a non-transparent custom host retains a core wrapper');
assert.equal(transformed.attrs.className, 'video-frame', 'the host class stays on the wrapper');
assert.equal(transformed.innerBlocks[0]?.blockName, 'core/video', 'the playable descendant is a native video block');

const css = `
  * { box-sizing:border-box } body { margin:0 } figure { margin:0 }
  .video-frame { display:block; width:360px; padding:18px; border:6px solid #246; overflow:hidden }
  .video-frame video { display:block; width:100%; height:180px }
  .block-editor-block-list__block { position:relative }
`;
const sourceMarkup = '<media-host class="video-frame" data-anchor="wrapper"><video data-anchor="video" src="hero.mp4"></video></media-host>';
const editorMarkup = '<div class="wp-block-group video-frame block-editor-block-list__block" data-anchor="wrapper"><figure class="wp-block-video"><video data-anchor="video" src="hero.mp4"></video></figure></div>';
const flattenedMarkup = '<div class="block-editor-block-list__block" data-anchor="wrapper"><figure class="wp-block-video"><video class="video-frame" data-anchor="video" src="hero.mp4"></video></figure></div>';

const browser = await chromium.launch({ headless: true });
try {
  const source = await browser.newPage({ viewport: { width: 900, height: 400 } });
  const editor = await browser.newPage({ viewport: { width: 900, height: 400 } });
  const flattened = await browser.newPage({ viewport: { width: 900, height: 400 } });
  await source.setContent(`<style>${css}</style>${sourceMarkup}`);
  await editor.setContent(`<style>${css}</style><div class="editor-styles-wrapper">${editorMarkup}</div>`);
  await flattened.setContent(`<style>${css}</style><div class="editor-styles-wrapper">${flattenedMarkup}</div>`);
  const capture = (page) => page.locator('[data-anchor]').evaluateAll((elements) => Object.fromEntries(elements.map((element) => {
    const rect = element.getBoundingClientRect();
    return [element.dataset.anchor, { width: rect.width, height: rect.height, x: rect.x, y: rect.y }];
  })));
  const sourceGeometry = await capture(source);
  const editorGeometry = await capture(editor);
  const flattenedGeometry = await capture(flattened);

  assert.deepEqual(editorGeometry, sourceGeometry, 'the core/group wrapper preserves custom-host frontend geometry in the editor');
  assert.notDeepEqual(flattenedGeometry.wrapper, sourceGeometry.wrapper, 'moving the host class to video loses the host wrapper geometry');
} finally {
  await browser.close();
}

console.log('Custom video host editor geometry passed');
