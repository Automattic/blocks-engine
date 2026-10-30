import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = fileURLToPath(new URL('../../../', import.meta.url));
const script = execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
echo (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\Generators\\LayoutShellBlockGenerator())->definition('custom/layout-shell')['assets']['index.js'];
`, root], { encoding: 'utf8' });
let definition;
let hooks = [];
const child = { tag: 'p', props: { className: 'b' } };
const wp = {
  blocks: { registerBlockType: (_, value) => { definition = value; } },
  element: { createElement: (tag, props, ...children) => ({ tag, props, children: children.length ? children : props?.children }) },
  blockEditor: {
    InnerBlocks: { Content: 'SavedChildren' },
    useBlockProps: () => {
      hooks.push('block');
      return { className: 'wp-block block-editor-block-list__block wp-block-custom-layout-shell', style: { position: 'relative', color: 'blue' }, ref: () => {} };
    },
    useInnerBlocksProps: (props) => {
      hooks.push('inner');
      return { ...props, className: `${props.className || ''} block-editor-block-list__layout`.trim(), children: [child] };
    },
  },
};
vm.runInNewContext(script, { window: { wp } });
for (const wrappers of [[], [{ tagName: 'section', attributes: { class: 'a', style: 'color:red' } }], [{ tagName: 'article', attributes: { class: 'outer' } }, { tagName: 'section', attributes: { class: 'a' } }]]) {
  hooks = [];
  const rendered = definition.edit({ attributes: { wrappers } });
  assert.deepEqual(hooks, ['block', 'inner'], 'hook order does not depend on wrapper count');
  let element = rendered;
  for (let index = 0; index < wrappers.length; index++) {
    assert.equal(element.tag, wrappers[index].tagName);
    assert.ok(element.props.className.startsWith(wrappers[index].attributes.class), 'authored classes precede editor classes');
    if (index < wrappers.length - 1) element = element.children[0];
  }
  assert.equal(element.children[0], child, 'children are direct descendants of the innermost authored wrapper');
  assert.ok(element.props.className.includes('block-editor-block-list__layout'));
  if (wrappers.length === 1) assert.equal(element.props.style.color, 'red', 'authored style wins');
  if (!wrappers.length) assert.ok(rendered.props.className.includes('block-editor-block-list__block'), 'empty chains retain conventional block props');
}
console.log('Layout-shell editor DOM and hook composition passed');
