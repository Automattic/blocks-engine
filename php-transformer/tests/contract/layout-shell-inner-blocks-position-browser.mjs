import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

// #7416: the layout-shell inner-blocks carrier receives Gutenberg's
// `block-editor-block-list__layout` class, which core's content.css styles
// `position:relative`. A wrapper that is static on the frontend then becomes
// the containing block of an absolute child sized against an outer ancestor,
// and that child collapses to 0px in the canvas.
//
// Neutral reduction: a relative 240x60 box > absolute link (inset:0) > static
// div > static div (the carrier) > absolute figure (inset:0; height:100%).
// The transformer compiles the reduction (author CSS with its editor position
// copies); the generated layout-shell script renders those same wrappers as
// one shell, both its editor tree and its saved markup.
const transformerRoot = fileURLToPath(new URL('../..', import.meta.url));
const generated = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
use Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\Generators\\LayoutShellBlockGenerator;
use Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\Style\\EngineSupportCss;
$html = '<style>.logo{position:relative;width:240px;height:60px}.logo .link{position:absolute;inset:0;display:block}.logo .pic{position:absolute;inset:0;height:100%;margin:0}.logo .pic img{width:100%;height:100%;object-fit:cover}</style>'
    . '<div class="logo"><a class="link" href="/"><div class="inner"><div class="media"><figure class="pic"><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" alt="Logo"></figure></div></div></a></div>';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($html)->toArray();
$css = implode("\\n", array_map(static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $result['assets'] ?? array()));
$document = new DOMDocument();
@$document->loadHTML('<?xml encoding="utf-8"?><body>' . preg_replace('/<!--.*?-->/s', '', (string) $result['serialized_blocks']) . '</body>');
$node = $document->getElementsByTagName('body')->item(0)->firstElementChild;
$wrappers = array();
for ($index = 0; $index < 4; ++$index) {
    $attributes = array();
    foreach ($node->attributes as $attribute) $attributes[$attribute->name] = $attribute->value;
    $wrappers[] = array('tagName' => strtolower($node->tagName), 'attributes' => $attributes);
    $node = $node->firstElementChild;
}
if ('figure' !== strtolower($node->tagName)) throw new RuntimeException('the reduction must keep four wrappers around its figure');
$shell = '<!-- wp:custom/layout-shell ' . json_encode(array('wrappers' => $wrappers)) . ' -->';
echo json_encode(array(
    'css' => $css,
    'shellSupportCss' => implode("\\n", (new EngineSupportCss())->coreDefaultResetCss($shell)),
    'carrierRule' => LayoutShellBlockGenerator::EDITOR_INNER_BLOCKS_CARRIER_CSS,
    'script' => (new LayoutShellBlockGenerator())->definition('custom/layout-shell')['assets']['index.js'],
    'wrappers' => $wrappers,
    'figure' => $document->saveHTML($node),
));
`, transformerRoot], {encoding: 'utf8'}));

assert.ok(generated.shellSupportCss.includes(generated.carrierRule), 'a document with a layout shell ships the inner-blocks carrier rule');
const coreEditorCss = '.block-editor-block-list__layout{position:relative}.block-editor-block-list__layout .block-editor-block-list__block{position:relative}';
const withoutFix = generated.shellSupportCss.split(generated.carrierRule).join('');

// Minimal wp.* runtime: elements are plain trees rendered to DOM, the hooks
// add exactly the classes Gutenberg adds.
const runtime = ({script, wrappers, figure}) => {
    const tree = (type, props, ...children) => ({type, props: props || {}, children});
    const inner = {type: 'inner'};
    let settings;
    window.wp = {
        element: {createElement: tree},
        blocks: {registerBlockType: (_, value) => { settings = value; }},
        blockEditor: {
            InnerBlocks: {Content: 'inner'},
            useBlockProps: () => ({className: 'block-editor-block-list__block wp-block wp-block-custom-layout-shell'}),
            useInnerBlocksProps: props => ({...props, className: [props.className, 'block-editor-block-list__layout'].filter(Boolean).join(' '), children: inner}),
        },
    };
    new Function(script)();
    const render = (node, editor) => {
        if (null === node || undefined === node || false === node) return document.createTextNode('');
        if ('inner' === node.type || 'inner' === node) {
            const template = document.createElement('template');
            template.innerHTML = figure;
            if (editor) template.content.firstElementChild.classList.add('block-editor-block-list__block');
            return template.content;
        }
        const element = document.createElement(node.type);
        for (const [name, value] of Object.entries(node.props)) {
            if ('children' === name || 'ref' === name || 'function' === typeof value) continue;
            if ('className' === name) element.setAttribute('class', value);
            else if ('style' === name) for (const [key, declaration] of Object.entries(value)) element.style.setProperty(key.startsWith('--') ? key : key.replace(/[A-Z]/g, letter => '-' + letter.toLowerCase()), declaration);
            else if (true === value) element.setAttribute(name, '');
            else element.setAttribute(name === 'tabIndex' ? 'tabindex' : name, value);
        }
        for (const child of [...node.children, ...(undefined === node.props.children ? [] : [node.props.children])]) element.append(render(child, editor));
        return element;
    };
    const attributes = {wrappers};
    return {
        editor: render(settings.edit({attributes}), true).outerHTML,
        saved: render(settings.save({attributes}), false).outerHTML,
    };
};

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({headless: true});
try {
    const page = await browser.newPage({viewport: {width: 600, height: 300}});
    await page.setContent('<!doctype html><html><body></body></html>');
    const markup = await page.evaluate(runtime, {script: generated.script, wrappers: generated.wrappers, figure: generated.figure});
    assert.ok(!markup.saved.includes('blocks-engine-layout-shell-inner-blocks') && !markup.saved.includes('block-editor'), 'saved markup carries no editor-only classes');
    assert.match(markup.editor, /class="[^"]*\bblock-editor-block-list__layout\b[^"]*\bblocks-engine-layout-shell-inner-blocks\b/, 'the carrier keeps Gutenberg\'s layout class and gains the editor marker');

    const measure = async (css, body) => {
        await page.setContent(`<!doctype html><html><head><style>*{box-sizing:border-box}body{margin:0}${css}</style></head><body>${body}</body></html>`);
        return page.locator('figure').evaluate(figure => {
            const rect = figure.getBoundingClientRect();
            return {width: rect.width, height: rect.height};
        });
    };
    const frontend = await measure(generated.css, markup.saved);
    const canvas = await measure(coreEditorCss + generated.shellSupportCss + generated.css, `<div class="editor-styles-wrapper">${markup.editor}</div>`);
    const counterfactual = await measure(coreEditorCss + withoutFix + generated.css, `<div class="editor-styles-wrapper">${markup.editor}</div>`);
    console.log(JSON.stringify({frontend, canvas, counterfactual}));

    assert.deepEqual(frontend, {width: 240, height: 60}, 'the frontend figure fills the 240x60 box');
    assert.deepEqual(canvas, {width: 240, height: 60}, 'the canvas figure matches the frontend');
    assert.equal(counterfactual.height, 0, 'without the carrier rule, Gutenberg\'s position:relative collapses the figure');
} finally {
    await browser.close();
}
console.log('layout-shell inner-blocks position browser check passed');
