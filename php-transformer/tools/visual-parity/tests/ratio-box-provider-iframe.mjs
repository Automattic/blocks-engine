import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../../..', import.meta.url).pathname;
const code = 'require $argv[1] . "/vendor/autoload.php"; $html = require $argv[1] . "/tests/fixtures/ratio-box-provider-iframe.php"; $result = (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($html)->toArray(); echo json_encode(array("html" => $html, "result" => $result));';
const { html, result } = JSON.parse(execFileSync('php', ['-r', code, root], { encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content ?? '').join('\n');
// Core's oEmbed render of the saved URL, and the embed block's responsive CSS.
const markup = result.serialized_blocks.replace(/\nhttps:\/\/www\.youtube\.com\/watch\?v=demo123\n/, '<iframe width="500" height="281" src="about:blank" title="Video"></iframe>');
assert.ok(markup.includes('<iframe'), 'the saved provider URL renders an oEmbed iframe');
const coreCss = '.wp-block-embed iframe{max-width:100%}.wp-block-embed__wrapper{position:relative}'
  + '.wp-embed-responsive .wp-has-aspect-ratio .wp-block-embed__wrapper:before{content:"";display:block;padding-top:50%}'
  + '.wp-embed-responsive .wp-has-aspect-ratio iframe{position:absolute;inset:0;height:100%;width:100%}'
  + '.wp-embed-responsive .wp-embed-aspect-16-9 .wp-block-embed__wrapper:before{padding-top:56.25%}'
  + ':where(figure){margin:0 0 1em}';
const browser = await chromium.launch({ headless: true });
try {
  for (const width of [390, 768, 1440]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const measure = async content => {
      await page.setContent(content);
      return page.evaluate(() => {
        const box = document.querySelector('iframe').getBoundingClientRect();
        const after = [...document.querySelectorAll('p')].pop().getBoundingClientRect();
        return { left: box.left, top: box.top, width: box.width, height: box.height, afterTop: after.top };
      });
    };
    const source = await measure(html);
    const candidate = await measure(`<!doctype html><head><style>${coreCss}${css}</style></head><body class="wp-embed-responsive">${markup}</body>`);
    console.log(JSON.stringify({ width, source, candidate }));
    for (const key of Object.keys(source)) {
      assert.ok(Math.abs(source[key] - candidate[key]) < 1, `${width}px: frame ${key} matches the source (${source[key]} vs ${candidate[key]})`);
    }
    await page.close();
  }
} finally {
  await browser.close();
}
