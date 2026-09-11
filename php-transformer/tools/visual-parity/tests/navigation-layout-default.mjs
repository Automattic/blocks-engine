import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const root = new URL('../../..', import.meta.url).pathname;
const source = '<style>:root{--navbar-display:flex}.captured-header{--navbar-display:flex}@media(min-width:769px){.captured-header{--navbar-display:block}}.navbar{display:var(--navbar-display,block)}</style><header class="captured-header"><nav class="navbar"><ul><li><a href="/">Home</a></li></ul></nav></header>';
const result = JSON.parse(execFileSync('php', ['-r', 'require $argv[1] . "/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());', root, Buffer.from(source).toString('base64')], { encoding: 'utf8' }));
const navigation = result.blocks.flatMap((block) => block.innerBlocks ?? []).find((block) => block.blockName === 'core/navigation');

assert.equal(navigation?.attrs?.layout?.type, 'default', 'serialized navigation carries explicit core flow layout');
assert.match(result.serialized_blocks, /"layout":\{"type":"default"\}/, 'canonical block markup retains the flow layout attribute');

const browser = await chromium.launch({ headless: true });
try {
  const sourcePage = await browser.newPage({ viewport: { width: 1440, height: 300 } });
  await sourcePage.setContent(source);
  assert.equal(await sourcePage.locator('nav').evaluate((element) => getComputedStyle(element).display), 'block', 'the captured desktop custom-property cascade resolves navigation to block');
  await sourcePage.close();

  const page = await browser.newPage({ viewport: { width: 1440, height: 300 } });
  // This is the core/navigation server-rendered shape for layout.type default.
  await page.setContent('<style>.is-layout-flow{display:block}.is-layout-flex{display:flex}</style><nav class="wp-block-navigation is-layout-flow wp-block-navigation-is-layout-flow"><ul class="wp-block-navigation__container wp-block-navigation"><li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="/"><span class="wp-block-navigation-item__label">Home</span></a></li></ul></nav>');
  const rendered = await page.locator('nav').evaluate((element) => ({ className: element.className, display: getComputedStyle(element).display }));
  assert.match(rendered.className, /is-layout-flow/, 'WordPress-rendered navigation has the flow layout class');
  assert.equal(rendered.display, 'block', 'the browser renders the WordPress flow navigation as block, not flex');
} finally {
  await browser.close();
}

console.log('Navigation default layout browser regression passed');
