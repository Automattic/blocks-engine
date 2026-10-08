import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

// #2189: editing a default-variant block must carry its text to the declared
// counterpart without replacing the counterpart's own inline formatting.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const script = JSON.parse(execFileSync('php', ['-r', 'require $argv[1] . "/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\ResponsiveCounterpartEditorModule())->module()["content"]);', root], { encoding: 'utf8' }));

const browser = await chromium.launch();
const page = await browser.newPage();
await page.setContent('<!doctype html><html><body></body></html>');
const results = await page.evaluate((moduleScript) => {
  const blocks = {
    desktop: { clientId: 'desktop', name: 'core/heading', attributes: { className: 'data-liberation-responsive-counterpart-aaaaaaaaaaaa', content: '' } },
    mobile: { clientId: 'mobile', name: 'core/heading', attributes: { className: 'data-liberation-responsive-counterpart-aaaaaaaaaaaa', content: '' } },
  };
  const roots = [
    { clientId: 'desktop-root', attributes: { className: 'data-liberation-desktop-document' }, innerBlocks: [ blocks.desktop ] },
    { clientId: 'mobile-root', attributes: { className: 'data-liberation-mobile-document' }, innerBlocks: [ blocks.mobile ] },
  ];
  const parents = { desktop: [ 'desktop-root' ], mobile: [ 'mobile-root' ] };
  let blockEditFilter = null;
  window.wp = {
    hooks: { addFilter: (name, namespace, callback) => { if ('editor.BlockEdit' === name) blockEditFilter = callback; } },
    element: { createElement: (type, props) => { if ('BlockEdit' === type) window.__renderedProps = props; return { type, props }; }, Fragment: 'Fragment' },
    components: { PanelBody: 'PanelBody', Button: 'Button' },
    blockEditor: { InspectorControls: 'InspectorControls', useBlockEditingMode: () => {} },
    data: {
      select: () => ({
        getBlocks: () => roots,
        getBlockParents: (clientId) => parents[clientId] || [],
        getBlock: (clientId) => roots.find((block) => block.clientId === clientId) || blocks[clientId] || null,
      }),
      dispatch: () => ({
        updateBlockAttributes: (clientId, attributes) => Object.assign(blocks[clientId].attributes, attributes),
        createNotice: () => {},
      }),
    },
  };
  new Function(moduleScript)();

  const edit = (desktopContent, mobileContent, nextDesktopContent) => {
    blocks.desktop.attributes.content = desktopContent;
    blocks.mobile.attributes.content = mobileContent;
    blockEditFilter('BlockEdit')({ clientId: 'desktop', name: 'core/heading', attributes: blocks.desktop.attributes, setAttributes: (next) => Object.assign(blocks.desktop.attributes, next) });
    // The hook renders BlockEdit with mirrored props; typing calls their setAttributes.
    window.__renderedProps.setAttributes({ content: nextDesktopContent });
    return blocks.mobile.attributes.content;
  };

  return {
    sameShape: edit(
      '<mark style="font-size:29px;--blocks-engine-richtext-marker:m-7"><mark style="font-weight:bold">Old heading</mark></mark>',
      '<mark style="font-size:27px;--blocks-engine-richtext-marker:m-31"><mark style="font-weight:bold">Old heading</mark></mark>',
      '<mark style="font-size:29px;--blocks-engine-richtext-marker:m-7"><mark style="font-weight:bold">New heading</mark></mark>'
    ),
    addedRun: edit(
      '<mark style="font-size:29px"><mark style="font-weight:bold">Old</mark></mark>',
      '<mark style="font-size:27px"><mark style="font-weight:bold">Old</mark></mark>',
      '<mark style="font-size:29px"><mark style="font-weight:bold">Old</mark></mark> and more'
    ),
    plain: edit('Old', 'Old', 'New &amp; improved'),
    // Gutenberg passes RichText attributes as RichTextData objects, not strings.
    richTextData: (() => { class RichTextData { constructor(html) { this.html = html; } toString() { return this.html; } }
      blocks.mobile.attributes.content = new RichTextData('<mark style="font-size:27px">Old</mark>');
      blocks.desktop.attributes.content = new RichTextData('<mark style="font-size:29px">Old</mark>');
      blockEditFilter('BlockEdit')({ clientId: 'desktop', name: 'core/heading', attributes: blocks.desktop.attributes, setAttributes: (next) => Object.assign(blocks.desktop.attributes, next) });
      window.__renderedProps.setAttributes({ content: new RichTextData('<mark style="font-size:29px">Newer</mark>') });
      return String(blocks.mobile.attributes.content); })(),
  };
}, script);
await browser.close();

const failures = [];
const expect = (condition, message) => { if (!condition) failures.push(message); };
expect('<mark style="font-size:27px;--blocks-engine-richtext-marker:m-31"><mark style="font-weight:bold">New heading</mark></mark>' === results.sameShape, `same-shape edits keep the counterpart's own formatting and marker: ${results.sameShape}`);
expect('<mark style="font-size:27px"><mark style="font-weight:bold">Old and more</mark></mark>' === results.addedRun, `a changed text shape still keeps the counterpart's formatting: ${results.addedRun}`);
expect('<mark style="font-size:27px">Newer</mark>' === results.richTextData, `RichTextData attribute values are handled: ${results.richTextData}`);
expect('New &amp; improved' === results.plain, `plain text carries over with entities intact: ${results.plain}`);

if (failures.length) {
  console.error(failures.map((failure) => `FAIL: ${failure}`).join('\n'));
  process.exit(1);
}
console.log('responsive counterpart text sync: pass');
