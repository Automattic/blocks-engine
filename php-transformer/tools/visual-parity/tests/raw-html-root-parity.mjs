import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);

const root = new URL('../../../', import.meta.url).pathname;
const fixture = readFileSync(`${root}tests/fixtures/raw-html/custom-element-percent-height.html`, 'utf8');
const result = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
echo json_encode((new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(file_get_contents($argv[2]))->toArray());
`, root, `${root}tests/fixtures/raw-html/custom-element-percent-height.html`], { encoding: 'utf8' }));
const definitions = result.source_reports.generated_blocks.filter((block) => block.name.includes('responsive-media'));
assert.ok(definitions.length, 'the public transformer captures the custom image hosts');
const references = [];
const visit = (blocks) => blocks.forEach((block) => { if (block.blockName.endsWith('/responsive-media')) references.push(block); visit(block.innerBlocks || []); });
visit(result.blocks);
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const css = result.assets.filter((asset) => asset.kind === 'css').map((asset) => asset.content).join('\n');
    await page.setContent(fixture.replace(/<style>[\s\S]*?<\/style>/, `<style>${css}</style>`));
    const evidence = await page.evaluate(({ definitions, references }) => {
        const registrations = {};
        let currentAttributes;
        const createElement = (type, props, ...children) => {
            if (typeof type === 'symbol') return children;
            if (typeof type === 'function') return type(props);
            if (type === 'Inspector') return null;
            const node = document.createElement(type);
            for (const [key, value] of Object.entries(props || {})) {
                if (key === 'dangerouslySetInnerHTML') node.innerHTML = value.__html;
                else if (key === 'className') node.className = value;
            }
            children.flat(Infinity).filter(Boolean).forEach((child) => node.append(child));
            return node;
        };
        window.wp = {
            blocks: { registerBlockType: (name, settings) => { registrations[name] = settings; } },
            blockEditor: { useBlockProps: () => ({ className: `editor-block ${currentAttributes.className || ''}` }), InspectorControls: 'Inspector' },
            components: {}, element: { createElement, Fragment: Symbol('Fragment') }
        };
        definitions.forEach((definition) => (0, eval)(definition.assets['index.js']));
        const hosts = [...document.querySelectorAll('x-picture')];
        hosts.forEach((host, index) => {
            const definition = definitions[index] || definitions[0];
            currentAttributes = references[index].attrs;
            const tree = registrations[definition.block_json.name].edit({ attributes: currentAttributes, isSelected: false });
            host.replaceWith(...tree.flat(Infinity).filter(Boolean));
        });
        return [...document.querySelectorAll('.pic')].map((node) => ({ tag: node.tagName, parent: node.parentElement.className, height: node.getBoundingClientRect().height }));
    }, { definitions, references });
    console.log(JSON.stringify(evidence));
    // Exercise generated public edit interfaces against React and a real DOM, not a
    // createElement stub (which cannot detect React's void-element invariant).
    await page.addScriptTag({ path: require.resolve('react/package.json').replace('package.json', 'umd/react.development.js') });
    await page.addScriptTag({ path: require.resolve('react-dom/package.json').replace('package.json', 'umd/react-dom.development.js') });
    const customScript = execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
echo (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\Generators\\CustomBlockGenerator())->assets('ssi-test/collection')['index.js'];
`, root], { encoding: 'utf8' });
    const layoutScript = execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
echo (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\Generators\\ResponsiveLayoutBlockGenerator())->assets('ssi-test/layout')['index.js'];
`, root], { encoding: 'utf8' });
    await page.evaluate(({ customScript, layoutScript, definitions }) => {
        const registrations = {};
        let selectedRef;
        window.wp = {
            blocks: { registerBlockType: (name, settings) => { registrations[name] = settings; } },
            blockEditor: {
                useBlockProps: () => ({ className: 'block-props', ref: (node) => { selectedRef = node; } }),
                InspectorControls: ({ children }) => React.createElement('aside', null, children)
            },
            components: {
                PanelBody: ({ title, children }) => React.createElement('fieldset', null, React.createElement('legend', null, title), children),
                TextareaControl: ({ value, onChange }) => React.createElement('textarea', { value, onChange: (event) => onChange(event.target.value) })
            },
            element: React
        };
        [customScript, layoutScript, ...definitions.map((definition) => definition.assets['index.js'])].forEach((script) => (0, eval)(script));
        const container = document.createElement('div');
        document.body.append(container);
        const root = ReactDOM.createRoot(container);
        const check = (condition, message) => { if (!condition) throw new Error(message); };
        for (const [name, settings] of Object.entries(registrations)) {
            for (const isSelected of [false, true]) {
                for (const content of ['', '<section class="grid"><p>Source</p></section>', '<img class="pic" src="c" srcset="a 1x, b 2x">', '<br>', '<hr>', '<input>', '<source>']) {
                    ReactDOM.flushSync(() => root.render(React.createElement(settings.edit, { attributes: { content }, isSelected, setAttributes: () => {} })));
                    const canvas = container.querySelector('.block-props');
                    const expected = document.createElement('template');
                    expected.innerHTML = content;
                    check(canvas.localName === (expected.content.firstElementChild?.localName || 'div'), 'captured root must be the block root');
                    check(canvas === selectedRef && canvas.classList.contains('block-props'), 'selection ref must target the captured root');
                    check(canvas.innerHTML === (expected.content.firstElementChild?.innerHTML || ''), 'non-empty source HTML must remain intact on the captured root');
                    if (content.includes('<img')) check(canvas.getAttribute('srcset') === 'a 1x, b 2x', 'void root attributes must survive');
                    const textarea = container.querySelector('textarea');
                    if (name === 'ssi-test/collection' || isSelected) {
                        check(textarea && textarea.value === content && container.querySelector('legend').textContent.includes('HTML'), 'non-empty HTML controls must remain in the inspector');
                    }
                }
                ReactDOM.flushSync(() => root.render(React.createElement(settings.edit, { attributes: { content: '<section style="color:red!important">First</section><p>Sibling</p>' }, isSelected, setAttributes: () => {} })));
                const canvas = container.querySelector('.block-props');
                check(canvas.style.getPropertyPriority('color') === 'important', 'root inline priority must survive');
                check(canvas === selectedRef, 'priority handling must forward selection refs');
                check(canvas.nextElementSibling.textContent === 'Sibling' && !canvas.nextElementSibling.classList.contains('block-props'), 'sibling roots intentionally remain outside the selection root');
            }
        }
        ReactDOM.flushSync(() => root.unmount());
        container.remove();
    }, { customScript, layoutScript, definitions });
    assert.deepEqual(evidence, [0, 1].map(() => ({ tag: 'DIV', parent: 'media', height: 250 })), 'editor roots match the wrapper-free sanitized frontend tree and percentage heights');
} finally {
    await browser.close();
}
