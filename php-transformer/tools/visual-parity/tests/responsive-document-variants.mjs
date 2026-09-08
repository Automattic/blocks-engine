import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const compiled = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
use Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'index.html',
    'document_variants' => array(array(
        'source_path' => 'index.html',
        'variants' => array(array(
            'id' => 'mobile',
            'source_path' => '.variants/mobile/index.html',
            'media' => '(max-width: 768px)',
        )),
    )),
    'files' => array(
        array('path' => 'index.html', 'content' => '<!doctype html><html><head></head><body><main><p class="default-rule">Desktop</p></main></body></html>'),
        array('path' => '.variants/mobile/index.html', 'content' => '<!doctype html><html><head><style>.mobile-rule{display:flex;color:rgb(1, 2, 3)}</style></head><body><main><p class="mobile-rule">Mobile</p></main></body></html>'),
    ),
))->toArray();

echo json_encode(array(
    'css' => implode("\\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter($result['assets'] ?? array(), 'is_array'))),
    'markup' => (string) ($result['serialized_blocks'] ?? ''),
));
`, transformerRoot], { encoding: 'utf8' }));

assert.match(compiled.markup, /site-document-variant-default/);
assert.match(compiled.markup, /site-document-variant-mobile/);
assert.match(compiled.css, /@media \(max-width: 768px\)/);

const browser = await chromium.launch({ headless: true });
try {
  const capture = async (width) => {
    const page = await browser.newPage({ viewport: { width, height: 600 } });
    await page.setContent(`<!doctype html><style>body{margin:0}${compiled.css}</style>${compiled.markup}`);
    const result = await page.evaluate(() => {
      const defaultWrapper = document.querySelector('.site-document-variant-default');
      const mobileWrapper = document.querySelector('.site-document-variant-mobile');
      const mobileRule = document.querySelector('.mobile-rule');
      return {
        defaultDisplay: getComputedStyle(defaultWrapper).display,
        mobileDisplay: getComputedStyle(mobileWrapper).display,
        mobileRuleDisplay: getComputedStyle(mobileRule).display,
        mobileRuleColor: getComputedStyle(mobileRule).color,
      };
    });
    await page.close();
    return result;
  };

  const desktop = await capture(1280);
  assert.notEqual(desktop.defaultDisplay, 'none', 'desktop viewport keeps the default wrapper visible');
  assert.equal(desktop.mobileDisplay, 'none', 'desktop viewport hides the mobile wrapper');

  const mobile = await capture(390);
  assert.equal(mobile.defaultDisplay, 'none', 'mobile viewport hides the default wrapper');
  assert.notEqual(mobile.mobileDisplay, 'none', 'mobile viewport keeps the mobile wrapper visible');
  assert.equal(mobile.mobileRuleDisplay, 'flex', 'the scoped mobile rule applies at the mobile viewport');
  assert.equal(mobile.mobileRuleColor, 'rgb(1, 2, 3)', 'the scoped mobile rule retains its computed color');
} finally {
  await browser.close();
}

console.log('Responsive document variant browser regression passed');
