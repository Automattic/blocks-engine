import { beforeAll, describe, expect, it } from 'vitest';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { setupDomGlobals } from '../dom-globals.js';

const require = createRequire(import.meta.url) as { (id: string): any; resolve(id: string): string };
const phpRoot = fileURLToPath(new URL('../../../../../php-transformer/', import.meta.url));
const source = readFileSync(`${phpRoot}tests/fixtures/associated-label.html`, 'utf8');
const compile = (html: string) => JSON.parse(execFileSync('php', ['-r',
  'require $argv[1] . "/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());',
  phpRoot, Buffer.from(html).toString('base64')], { encoding: 'utf8' }));

describe('associated label save contract', () => {
  let wp: any;
  beforeAll(() => {
    setupDomGlobals();
    wp = require('@wordpress/blocks');
    require('@wordpress/block-library').registerCoreBlocks();
  });

  const registerCompanions = (result: any) => {
    const definitions = result.source_reports.generated_blocks;
    wp.unstable__bootstrapServerSideBlockDefinitions(Object.fromEntries(
      definitions.map((definition: any) => [definition.block_json.name, definition.block_json]),
    ));
    const libraryRequire = createRequire(require.resolve('@wordpress/block-library')) as typeof require;
    const nativeWp = {
      blocks: wp, blockEditor: libraryRequire('@wordpress/block-editor'),
      components: libraryRequire('@wordpress/components'), element: libraryRequire('@wordpress/element'),
      richText: libraryRequire('@wordpress/rich-text'),
    };
    for (const definition of definitions) {
      if (!wp.getBlockType(definition.block_json.name)) {
        new Function('window', definition.assets['index.js'])({ wp: nativeWp });
      }
    }
    return nativeWp;
  };

  it('proves core paragraph and group cannot save an associated label', () => {
    const paragraph = wp.serialize([wp.createBlock('core/paragraph', {
      tagName: 'label', for: 'choice', content: 'Choice',
    })]);
    expect(paragraph).toContain('<p');
    expect(paragraph).not.toContain('<label');
    const group = wp.serialize([wp.createBlock('core/group', {
      tagName: 'label', for: 'choice', htmlFor: 'choice',
    })]);
    // Group's tagName can change its host, but its save schema has no for.
    expect(group).toContain('<label');
    expect(group).not.toContain('for="choice"');
  });

  it('reopens compiler output with registered native Gutenberg saves and edits RichText', () => {
    const result = compile(source);
    const nativeWp = registerCompanions(result);
    const blocks = wp.parse(result.serialized_blocks);
    const flatten = (items: any[]): any[] => items.flatMap(block => [block, ...flatten(block.innerBlocks)]);
    for (const block of flatten(blocks)) expect(wp.validateBlock(block)[0]).toBe(true);
    const saved = wp.serialize(blocks);
    const reopened = wp.parse(saved);
    for (const block of flatten(reopened)) expect(wp.validateBlock(block)[0]).toBe(true);
    const labels = flatten(reopened).filter(block => block.name === 'custom/authored-label');
    expect(labels).toHaveLength(2);
    expect(labels[0].attributes.htmlFor).toBe('after');
    expect(labels[1].attributes.content).toContain('<strong>Science</strong> &amp; <mark class="caption-run"');
    expect(labels[1].attributes.content).toContain('role="none"><em>Health</em></mark>');
    const host = document.createElement('div');
    host.innerHTML = saved;
    expect(host.querySelector('#after + label')?.getAttribute('for')).toBe('after');
    expect(host.querySelector('label + #before')?.previousElementSibling?.id).toBe('before-caption');

    // Render the registered edit with the actual block-editor RichText component.
    // useBlockProps needs editor context; supply only that hook's neutral props.
    const definition = result.source_reports.generated_blocks.find((item: any) => item.name === 'authored-label');
    let settings: any;
    new Function('window', definition.assets['index.js'])({ wp: {
      ...nativeWp,
      blocks: { registerBlockType: (_name: string, value: any) => { settings = value; } },
      blockEditor: { ...nativeWp.blockEditor, useBlockProps: (props: any) => props },
    } });
    const label = labels[1];
    const edit = settings.edit({ attributes: label.attributes, setAttributes: (next: any) => Object.assign(label.attributes, next) });
    expect(edit.type).toBe(nativeWp.blockEditor.RichText);
    expect(edit.props.tagName).toBe('label');
    expect(edit.props.htmlFor).toBe('before');
    edit.props.onChange('<strong>Edited</strong> &amp; <em>editable</em>');
    const edited = wp.parse(wp.serialize(reopened));
    const editedLabel = flatten(edited).find(block => block.attributes.id === 'before-caption');
    expect(wp.validateBlock(editedLabel)[0]).toBe(true);
    expect(editedLabel.attributes.content).toBe('<strong>Edited</strong> &amp; <em>editable</em>');
    expect(editedLabel.attributes.htmlFor).toBe('before');
  });

  it('preserves exact browser geometry, checked selectors, and native label clicks', async () => {
    const { chromium } = require('playwright');
    const result = compile(source);
    registerCompanions(result);
    const saved = wp.serialize(wp.parse(result.serialized_blocks));
    const css = result.assets.filter((asset: any) => asset.kind === 'css').map((asset: any) => asset.content || '').join('\n');
    let rendered: string | undefined;
    if (process.env.BLOCKS_ENGINE_WP_CLI && process.env.BLOCKS_ENGINE_WP_PATH) {
      const payload = Buffer.from(JSON.stringify({
        markup: saved, definitions: result.source_reports.generated_blocks.map((definition: any) => definition.block_json),
      })).toString('base64');
      // Register static companion metadata in this process only. No site content is saved.
      const code = `$input=json_decode(base64_decode('${payload}'),true); foreach($input['definitions'] as $definition) { register_block_type($definition['name'],$definition); } echo json_encode(array('html'=>do_blocks(serialize_blocks(parse_blocks($input['markup']))),'version'=>get_bloginfo('version')));`;
      const native = JSON.parse(execFileSync(process.env.BLOCKS_ENGINE_WP_CLI, [
        `--path=${process.env.BLOCKS_ENGINE_WP_PATH}`, '--skip-plugins', '--skip-themes', 'eval', code,
      ], { encoding: 'utf8' }));
      expect(native.version).toMatch(/^7\./);
      rendered = native.html;
    }
    const browser = await chromium.launch({ headless: true });
    try {
      for (const width of [390, 768, 1440]) {
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        const probe = async (html: string, styles = '') => {
          await page.setContent(`<!doctype html><style>${styles}</style>${html}`);
          const measure = () => page.evaluate(() => Array.from(document.querySelectorAll('.caption')).map(label => {
            const range = document.createRange();
            range.selectNodeContents(label);
            const box = label.getBoundingClientRect();
            const text = range.getBoundingClientRect();
            const style = getComputedStyle(label);
            const pseudo = getComputedStyle(label, '::after');
            return {
              id: label.id, for: label.getAttribute('for'),
              box: [box.x, box.y, box.width, box.height], text: [text.x, text.y, text.width, text.height],
              font: [style.fontFamily, style.fontSize, style.fontWeight, style.lineHeight],
              paint: [style.color, style.backgroundColor, pseudo.content, pseudo.top, pseudo.left],
              control: (label as HTMLLabelElement).control?.id,
              before: label.previousElementSibling?.tagName, after: label.nextElementSibling?.tagName,
            };
          }));
          const closed = await measure();
          if (width === 390) await page.locator('.filters').evaluate((node: Element) => node.classList.add('open'));
          const open = await measure();
          const states = [];
          for (const id of ['after-caption', 'before-caption']) {
            await page.locator(`#${id}`).click();
            states.push(await page.locator('.choice input').evaluateAll((nodes: HTMLInputElement[]) => nodes.map(input => input.checked)));
            states.push(await measure());
          }
          return { closed, open, states };
        };
        const original = await probe(source);
        expect(await probe(saved, css)).toEqual(original);
        if (rendered !== undefined) expect(await probe(rendered, css)).toEqual(original);
        expect(original.states[0]).toEqual([false, false]);
        expect(original.states[2]).toEqual([false, true]);
        expect(original.open[0].font.slice(1)).toEqual(['14px', '400', '20px']);
        if (width === 390) expect(original.closed[0].box[2]).toBe(0);
        await page.close();
      }
    } finally {
      await browser.close();
    }
  }, 60000);
});
