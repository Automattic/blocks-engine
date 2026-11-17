import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

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
    assert.deepEqual(evidence, [0, 1].map(() => ({ tag: 'DIV', parent: 'media', height: 250 })), 'editor roots match the wrapper-free sanitized frontend tree and percentage heights');
} finally {
    await browser.close();
}
