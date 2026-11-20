const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

let block;
let editing = false;
const mounted = [];
const Fragment = Symbol('Fragment');
const RawHTML = Symbol('RawHTML');
const createElement = (type, props, ...children) => {
    props = props || {};
    if (editing && props.style !== undefined) {
        assert.equal(typeof props.style, 'object', 'React rejects declaration strings in editor props');
    }
    return { type, props, children };
};
const RichText = props => createElement(props.tagName, props, props.value);
RichText.Content = RichText;
const noop = () => null;
const wp = {
    blocks: { registerBlockType: (name, settings) => { block = settings; } },
    blockEditor: {
        RichText,
        InspectorControls: noop,
        // Gutenberg composes this ref with its own block wrapper ref.
        useBlockProps: props => props,
    },
    components: { TextControl: noop, TextareaControl: noop, PanelBody: noop },
    element: { createElement, Fragment, RawHTML },
};
vm.runInNewContext(fs.readFileSync(process.argv[2], 'utf8'), { window: { wp } });

function mount(node) {
    if (!node || typeof node !== 'object') return;
    if (typeof node.type === 'function') return mount(node.type(node.props));
    if (node.type === 'a') {
        const dom = { style: { cssText: '' } };
        if (node.props.ref) node.props.ref(dom);
        mounted.push({ node, dom });
    }
    node.children.forEach(mount);
}

const escape = value => String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
// Serialize the legacy save tree, including its verbatim style string. This is
// deliberately separate from the editor's React-style validation above.
function serialize(node) {
    if (typeof node === 'string') return node;
    if (node.type === RawHTML) return node.children.join('');
    if (typeof node.type === 'function') return serialize(node.type(node.props));
    const ignored = ['tagName', 'value'];
    const attrs = Object.entries(node.props)
        .filter(([key, value]) => value !== undefined && !ignored.includes(key))
        .map(([key, value]) => ` ${key === 'className' ? 'class' : key}="${escape(value)}"`).join('');
    return `<${node.type}${attrs}>${node.children.map(serialize).join('')}</${node.type}>`;
}

for (const contentMode of ['raw-source', 'rich-text', 'inline-rich-text']) {
    const attributes = {
        href: '/x', accessibleLabel: 'Mail', className: 'icon',
        style: 'box-shadow:0 0 0 1px inset;--x:1px',
        contentMode, content: '<b>Mail</b>',
    };
    editing = true;
    mounted.length = 0;
    assert.doesNotThrow(() => mount(block.edit({ attributes, setAttributes: noop })));
    assert.equal(mounted.length, 1, `${contentMode}: link rendered`);
    const { node, dom } = mounted[0];
    assert.equal(node.props.style, undefined);
    assert.equal(dom.style.cssText, attributes.style, `${contentMode}: full declaration list applied outside React`);
    node.props.ref(null); // Unmounting must also be safe.

    // A new render updates or clears the style rather than leaving stale CSS.
    for (const style of ['color:red;--x:2px', '']) {
        mounted.length = 0;
        mount(block.edit({ attributes: { ...attributes, style }, setAttributes: noop }));
        mounted[0].node.props.ref(dom);
        assert.equal(dom.style.cssText, style);
    }

    editing = false;
    const content = contentMode === 'rich-text' ? '<span><b>Mail</b></span>' : '<b>Mail</b>';
    assert.equal(
        serialize(block.save({ attributes })),
        `<a href="/x" aria-label="Mail" class="icon" style="box-shadow:0 0 0 1px inset;--x:1px">${content}</a>`,
        `${contentMode}: saved bytes remain unchanged`,
    );
}
console.log('OK: accessible-link editor styles and legacy save output');
