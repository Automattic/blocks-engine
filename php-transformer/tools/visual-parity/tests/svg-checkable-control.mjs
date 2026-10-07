import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const icon = '<svg width="24" height="24" viewBox="0 0 24 24"><path d="M2 2h20v20H2z" fill-rule="evenodd"/></svg>';
const control = (state, disabled = '') => `<div class="reaction"><span class="reaction-row"><button role="checkbox" aria-checked="${state}" tabindex="0" title="Favorite" class="reaction-control" ${disabled}>${icon}</button><i class="reaction-count">1</i></span></div>`;
const source = control('false') + control('true') + control('false', 'disabled');
const css = '.reaction{display:block;width:120px;padding:7px}.reaction-row{display:inline-flex;align-items:center;gap:9px}.reaction-row>button{width:32px;height:32px;padding:4px;border:0;background:transparent;color:navy}.reaction-row>i{font-size:18px;font-style:normal}.reaction-control[aria-checked=true]{color:maroon}svg{display:block;fill:currentColor}@media(max-width:600px){.reaction{padding:11px}.reaction-row{gap:13px}}';
const compiled = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
if ('' !== $argv[4]) foreach (array('src/HtmlToBlocks/HtmlCompilation.php', 'src/HtmlToBlocks/Generators/AuthoredButtonBlockGenerator.php') as $path) {
    $source = shell_exec('git -C ' . escapeshellarg($argv[1]) . ' show ' . escapeshellarg($argv[4] . ':php-transformer/' . $path));
    if (!is_string($source) || !str_starts_with($source, '<?php')) throw new RuntimeException('Baseline source cannot be loaded.');
    eval(substr($source, 5));
}
$result = (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]), array('static_css' => base64_decode($argv[3])))->toArray();
$button = current(array_filter($result['source_reports']['generated_blocks'] ?? array(), static fn(array $definition): bool => 'authored-button' === $definition['name']));
echo json_encode(array('markup' => $result['serialized_blocks'], 'css' => implode("\\n", array_column($result['assets'], 'content')), 'view' => $button['assets']['view.js'] ?? '', 'editor' => $button['assets']['index.js'] ?? '', 'validity' => $result['source_reports']['wp_block_validity']['status'] ?? null));
`, root, Buffer.from(source).toString('base64'), Buffer.from(css).toString('base64'), process.env.BLOCKS_ENGINE_TEST_BASELINE || ''], { encoding: 'utf8' }));

if (!process.env.BLOCKS_ENGINE_TEST_BASELINE) {
assert.doesNotMatch(compiled.markup, /<!-- wp:html/);
assert.equal(compiled.validity, 'pass');
assert.ok(compiled.view, 'Compiler ships the authored-button runtime');
}
const browser = await chromium.launch({ headless: true });
try {
  for (const width of [390, 1440]) {
    const page = await browser.newPage({ viewport: { width, height: 800 } });
    await page.setContent(`<style>body{margin:0}${css}</style>${source}`);
    const measure = () => page.locator('.reaction').evaluateAll((nodes) => nodes.map((node) => {
      const box = (element) => { const { x, y, width, height } = element.getBoundingClientRect(); return { x, y, width, height }; };
      return { root: box(node), row: box(node.querySelector('.reaction-row')), button: box(node.querySelector('button')), svg: box(node.querySelector('svg')), count: box(node.querySelector('i')), color: getComputedStyle(node.querySelector('button')).color };
    }));
    const baseline = await measure();
    await page.setContent(`<style>body{margin:0}${compiled.css}</style>${compiled.markup}`);
    if (compiled.view) await page.addScriptTag({ content: compiled.view });
    const actual = await measure();
    if (!process.env.BLOCKS_ENGINE_TEST_BASELINE) assert.deepEqual(actual, baseline, `${width}px source wrapper, button, icon, and counter geometry/styles are retained`);
    const checkboxes = page.getByRole('checkbox', { name: 'Favorite', exact: true });
    assert.equal(await checkboxes.count(), 3, 'Title remains the accessible name without visible text');
    for (const [index, initial] of [[0, 'false'], [1, 'true']]) {
      const checkbox = checkboxes.nth(index);
      const opposite = initial === 'false' ? 'true' : 'false';
      assert.equal(await checkbox.getAttribute('aria-checked'), initial);
      await checkbox.locator('path').click();
      assert.equal(await checkbox.getAttribute('aria-checked'), opposite, 'Click on SVG descendant toggles');
      await checkbox.focus();
      await page.keyboard.press('Space');
      assert.equal(await checkbox.getAttribute('aria-checked'), initial, 'Space toggles exactly once');
      await page.keyboard.press('Enter');
      assert.equal(await checkbox.getAttribute('aria-checked'), opposite, 'Enter retains native button activation');
    }
    await checkboxes.nth(2).dispatchEvent('click');
    assert.equal(await checkboxes.nth(2).getAttribute('aria-checked'), 'false', 'Disabled control cannot toggle');
    assert.deepEqual(await page.locator('i.reaction-count').allTextContents(), ['1', '1', '1'], 'Local checked state does not invent remote counter updates');
    await page.close();
  }

  // Exercise the actual generated editor implementation and save/reload output
  // in the browser; edit callbacks update typed attributes, rather than raw HTML.
  const page = await browser.newPage();
  await page.evaluate(() => {
    window.buttonDefinition = null;
    window.wp = {
      blocks: { registerBlockType: (_name, definition) => { window.buttonDefinition = definition; } },
      blockEditor: { InspectorControls: 'inspector' },
      components: { PanelBody: 'panel', TextControl: 'text-control', SelectControl: 'select-control', ToggleControl: 'toggle-control' },
      element: { Fragment: 'fragment', RawHTML: 'raw-html', createElement: (type, props, ...children) => ({ type, props: props || {}, children }) },
    };
  });
  await page.addScriptTag({ content: compiled.editor });
  const editor = await page.evaluate((iconSvg) => {
    let attrs = { type: 'button', role: 'checkbox', ariaChecked: 'false', title: 'Favorite', tabIndex: '0', iconSvg };
    const render = () => window.buttonDefinition.edit({ attributes: attrs, setAttributes: (next) => { attrs = { ...attrs, ...next }; } });
    const find = (node, predicate) => predicate(node) ? node : node.children?.map((child) => child && typeof child === 'object' ? find(child, predicate) : null).find(Boolean);
    find(render(), (node) => node.type === 'toggle-control' && node.props.label === 'Checked').props.onChange(true);
    const saved = window.buttonDefinition.save({ attributes: attrs }).children[0];
    find(render(), (node) => node.type === 'button').props.onClick();
    return { saved, checkedAfterClick: attrs.ariaChecked };
  }, icon);
  assert.match(editor.saved, /role="checkbox" aria-checked="true"/);
  assert.match(editor.saved, /title="Favorite" tabindex="0"/);
  assert.equal(editor.checkedAfterClick, 'false');
  await page.close();
} finally {
  await browser.close();
}
console.log('SVG checkable control browser regression passed: initial states, click/Space/Enter, disabled, editor save, 390/1440px exact geometry.');
