import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

const sourceCss = [
  '*,::before,::after{box-sizing:border-box}',
  'body{margin:0}',
  'form.contact{width:752px}',
  '.grid{display:grid}',
  '.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}',
  '.gap-6{gap:1.5rem}',
  '.w-full{width:100%}',
  '@media (width>=768px){.md\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}',
].join('');

const sourceFixture = [
  '<form class="contact">',
  '<div class="grid grid-cols-1 md:grid-cols-2 gap-6">',
  '<div><label>Name *</label><input class="w-full" type="text" required></div>',
  '<div><label>Phone *</label><input class="w-full" type="tel" required></div>',
  '</div>',
  '<div><label>Email *</label><input class="w-full" type="email" required></div>',
  '<div><label>Project Details *</label><textarea class="w-full" rows="5" required></textarea></div>',
  '<button type="submit">Submit</button>',
  '</form>',
].join('');

const transformed = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''));
echo json_encode(array(
  'serializedBlocks' => (string) ($result['serialized_blocks'] ?? ''),
  'css' => implode("\\n", array_column($css, 'content')),
  'formTag' => false !== strpos((string) ($result['serialized_blocks'] ?? ''), '<form'),
  'textarea' => false !== strpos((string) ($result['serialized_blocks'] ?? ''), '<textarea'),
  'submit' => 1 === preg_match('/<button\\b[^>]*type="submit"/', (string) ($result['serialized_blocks'] ?? '')),
));
`, transformerRoot, Buffer.from(`<style>${sourceCss}</style>${sourceFixture}`).toString('base64')], { encoding: 'utf8' }));

assert.equal(transformed.formTag, true, 'degraded form keeps a real <form>');
assert.equal(transformed.textarea, true, 'degraded form keeps a real <textarea>');
assert.equal(transformed.submit, true, 'degraded form keeps a native submit button');
assert.match(transformed.serializedBlocks, /grid grid-cols-1 md:grid-cols-2 gap-6/, 'row wrapper class survives on the layout-shell');

const browser = await chromium.launch({ headless: true });
try {
  const page = await browser.newPage({ viewport: { width: 900, height: 800 } });
  const measure = async (html) => {
    await page.setContent(html);
    return page.evaluate(() => {
      const form = document.querySelector('form, .contact');
      const grid = document.querySelector('.grid');
      const name = [...document.querySelectorAll('label')].find((label) => (label.textContent || '').includes('Name'));
      const phone = [...document.querySelectorAll('label')].find((label) => (label.textContent || '').includes('Phone'));
      const itemBox = (label) => {
        if (!label || !grid) return null;
        let node = label;
        while (node && node.parentElement !== grid) node = node.parentElement;
        const rect = (node || label).getBoundingClientRect();
        return { x: Math.round(rect.x), y: Math.round(rect.y), width: Math.round(rect.width), height: Math.round(rect.height) };
      };
      return {
        formWidth: form ? Math.round(form.getBoundingClientRect().width) : 0,
        name: itemBox(name),
        phone: itemBox(phone),
      };
    });
  };

  const source = await measure(`<!doctype html><style>${sourceCss}</style>${sourceFixture}`);
  const imported = await measure(
    `<!doctype html><style>${sourceCss}${transformed.css}</style>${transformed.serializedBlocks}`
  );

  assert.equal(source.formWidth, 752, `source form width: ${source.formWidth}`);
  assert.equal(source.name?.width, 364, `source name width: ${JSON.stringify(source.name)}`);
  assert.equal(source.phone?.width, 364, `source phone width: ${JSON.stringify(source.phone)}`);
  assert.equal(source.name?.y, source.phone?.y, 'source Name/Phone share a row');
  assert.equal(imported.formWidth, 752, `imported form width: ${imported.formWidth}`);
  assert.equal(imported.name?.width, 364, `imported name width: ${JSON.stringify(imported.name)}`);
  assert.equal(imported.phone?.width, 364, `imported phone width: ${JSON.stringify(imported.phone)}`);
  assert.equal(imported.name?.y, imported.phone?.y, 'imported Name/Phone share a row');
  assert.equal(imported.name?.width, source.name?.width, 'name column drifted from source');
  assert.equal(imported.phone?.width, source.phone?.width, 'phone column drifted from source');
  console.log(`Form row geometry: source=${JSON.stringify(source)} imported=${JSON.stringify(imported)}`);
} finally {
  await browser.close();
}

console.log('Form row two-column geometry passed');
