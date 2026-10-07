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
const compileDocument = (html: string) => JSON.parse(execFileSync('php', ['-r',
  'require $argv[1] . "/vendor/autoload.php"; $r=(new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(array("entrypoint"=>"index.html","block_namespace"=>"custom","files"=>array("index.html"=>base64_decode($argv[2]))))->toArray(); $plan=$r["source_reports"]["wordpress_site_plan"]; $page=$plan["pages"][0]; echo json_encode(array("serialized_blocks"=>$page["canonical_block_markup"],"assets"=>$plan["assets"],"source_reports"=>array("generated_blocks"=>$r["source_reports"]["companion_plugin_payload"]["blocks"]),"document"=>$page,"bootstrap"=>Automattic\\BlocksEngine\\PhpTransformer\\WordPressSitePlan\\DocumentRootContext::bootstrap(array($page)),"canvas"=>Automattic\\BlocksEngine\\PhpTransformer\\WordPressSitePlan\\DocumentRootContext::canvas()));',
  phpRoot, Buffer.from(html).toString('base64')], { encoding: 'utf8' }));

const renderWordPress = (result: any, markup: string): string | undefined => {
  if (!process.env.BLOCKS_ENGINE_WP_CLI || !process.env.BLOCKS_ENGINE_WP_PATH) return undefined;
  const payload = Buffer.from(JSON.stringify({
    markup, definitions: result.source_reports.generated_blocks.map((definition: any) => definition.block_json),
    document: result.document, bootstrap: result.bootstrap, canvas: result.canvas,
    css: result.assets.filter((asset: any) => asset.kind === 'css' && asset.stylesheet_target !== 'editor').map((asset: any) => asset.content || '').join('\n'),
  })).toString('base64');
  // All registrations and query fixtures are process-local; no content is saved.
  const code = `$input=json_decode(base64_decode('${payload}'),true); foreach($input['definitions'] as $definition) { register_block_type($definition['name'],$definition); } $markup=serialize_blocks(parse_blocks($input['markup'])); if(isset($input['document'])) { eval($input['bootstrap']); global $wp_query; $wp_query=new WP_Query(); $wp_query->is_singular=true; $wp_query->is_page=true; $wp_query->queried_object_id=900001; $wp_query->queried_object=(object)array('ID'=>900001,'post_type'=>'page'); add_filter('get_post_metadata',static function($value,$id,$key)use($input){return $id===900001&&$key==='_blocks_engine_reconciliation_identity'?$input['document']['reconciliation_identity']:$value;},10,3); foreach(array('wp_head','wp_footer','wp_body_open')as $hook)remove_all_actions($hook); add_action('wp_head',static function()use($input){echo '<style>'.$input['css'].'</style>';}); $GLOBALS['_wp_current_template_content']=$markup; $GLOBALS['_wp_current_template_id']=''; ob_start(); eval('?>'.$input['canvas']); $html=ob_get_clean(); } else { $html=do_blocks($markup); } echo json_encode(array('html'=>$html,'version'=>get_bloginfo('version')));`;
  const native = JSON.parse(execFileSync(process.env.BLOCKS_ENGINE_WP_CLI, [
    `--path=${process.env.BLOCKS_ENGINE_WP_PATH}`, '--skip-plugins', '--skip-themes', 'eval', code,
  ], { encoding: 'utf8' }));
  expect(native.version).toMatch(/^7\./);
  return native.html;
};

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

    // Construct the edit element using native RichText. This exercises the
    // callback/save contract, not a mounted or persistent wp-admin editor.
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
    const rendered = renderWordPress(result, saved);
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

  it('retains static CSS subjects through registered saves and native document rendering', async () => {
    const { chromium } = require('playwright');
    const documentSource = readFileSync(`${phpRoot}tests/fixtures/associated-label-static-attributes.html`, 'utf8');
    const result = compileDocument(documentSource);
    registerCompanions(result);
    const parsed = wp.parse(result.serialized_blocks);
    const flatten = (items: any[]): any[] => items.flatMap(block => [block, ...flatten(block.innerBlocks)]);
    for (const block of flatten(parsed)) expect(wp.validateBlock(block)[0]).toBe(true);
    const saved = wp.serialize(parsed);
    const reopened = wp.parse(saved);
    for (const block of flatten(reopened)) expect(wp.validateBlock(block)[0]).toBe(true);
    const label = flatten(reopened).find(block => block.attributes.id === 'choice-caption');
    expect(label.attributes.sourceAttributes).toMatchObject({
      'data-role': 'caption', 'data-present': '', 'aria-label': 'Choice caption',
      lang: 'en-CA', dir: 'ltr', title: 'Choose "Arts" & Science', tabindex: '0',
    });
    expect(saved).not.toContain('blocks-engine-attribute-');

    // The registered save also filters caller-supplied attribute objects, not
    // just compiler input. Owned fields cannot be overridden through this map.
    const hostile = wp.createBlock('custom/authored-label', {
      htmlFor: 'choice', id: 'hostile-probe', content: '<strong>Choice</strong>',
      sourceAttributes: {
        'data-role': 'caption', title: 'A "quoted" & title', hidden: '',
        onclick: 'alert(1)', 'data-action': 'run()', 'data-on': '', 'data-event': 'run()',
        'data-wp-on--click': 'actions.run', srcdoc: '<script>run()</script>', href: 'javascript:run()',
        for: 'wrong', id: 'wrong', class: 'wrong', style: 'display:none', 'data-object': { nested: true },
      },
    });
    const hostileSaved = wp.serialize([hostile]);
    expect(wp.validateBlock(wp.parse(hostileSaved)[0])[0]).toBe(true);
    const host = document.createElement('div');
    host.innerHTML = hostileSaved;
    const safeHost = host.querySelector('label')!;
    expect(Array.from(safeHost.attributes).map(attribute => attribute.name).sort()).toEqual(['data-role', 'for', 'hidden', 'id', 'title']);
    expect(safeHost.getAttribute('for')).toBe('choice');
    expect(safeHost.getAttribute('title')).toBe('A "quoted" & title');

    const css = result.assets.filter((asset: any) => asset.kind === 'css' && asset.stylesheet_target !== 'editor').map((asset: any) => asset.content || '').join('\n');
    expect(css).toContain('label[data-role=caption]');
    expect(css).toContain('body[data-document=choices]');
    const native = renderWordPress(result, saved);
    const browser = await chromium.launch({ headless: true });
    try {
      for (const width of [390, 768, 1440]) {
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        const probe = async (html: string, styles = '', metadata?: any) => {
          await page.setContent(html);
          if (styles) await page.addStyleTag({ content: styles });
          if (metadata) await page.evaluate((state: any) => {
            for (const [element, attributes] of [[document.documentElement, state.root_attributes], [document.body, state.body_attributes]] as const) {
              for (const [name, value] of Object.entries(attributes)) element.setAttribute(name, String(value));
            }
          }, metadata);
          const measure = () => page.evaluate(() => Array.from(document.querySelectorAll('.attribute-caption')).map(label => {
            const style = getComputedStyle(label);
            const range = document.createRange(); range.selectNodeContents(label);
            const box = label.getBoundingClientRect(); const text = range.getBoundingClientRect();
            const control = (label as HTMLLabelElement).control! as HTMLInputElement;
            const inputBox = control.getBoundingClientRect(); const inputStyle = getComputedStyle(control);
            return {
              id: label.id, for: label.getAttribute('for'), control: control.id, checked: control.checked,
              attributes: Array.from(label.attributes).filter(attribute => attribute.name !== 'style').map(attribute => [attribute.name, attribute.value]).sort(),
              box: [box.x, box.y, box.width, box.height], text: [text.x, text.y, text.width, text.height],
              font: [style.fontFamily, style.fontSize, style.fontWeight, style.lineHeight, style.letterSpacing],
              paint: [style.color, style.backgroundColor, style.border, style.textDecorationLine, getComputedStyle(label, '::after').content],
              input: [inputBox.x, inputBox.y, inputBox.width, inputBox.height, inputStyle.outline],
            };
          }));
          const rest = await measure();
          await page.locator('#choice-caption').evaluate((node: Element) => node.setAttribute('data-role', 'helper'));
          const changedPredicate = await measure();
          await page.locator('#choice-caption').evaluate((node: Element) => {
            node.setAttribute('data-role', 'caption'); node.setAttribute('data-state', 'expanded');
            node.setAttribute('data-muted', ''); node.removeAttribute('data-present');
          });
          const expanded = await measure();
          await page.locator('#choice-caption').click();
          const clicked = await measure();
          await page.locator('body').evaluate((node: Element) => { node.classList.remove('caption-context'); node.setAttribute('data-document', 'plain'); });
          const changedBody = await measure();
          await page.locator('#other-caption').click();
          const otherClicked = await measure();
          return { rest, changedPredicate, expanded, clicked, changedBody, otherClicked };
        };
        const original = await probe(documentSource);
        expect(await probe(saved, css, result.document.document_metadata)).toEqual(original);
        if (native !== undefined) expect(await probe(native)).toEqual(original);
        expect(original.rest[0].font.slice(1, 4)).toEqual([width === 390 ? '20px' : width === 768 ? '22px' : '24px', '600', width === 390 ? '24px' : width === 768 ? '28px' : '30px']);
        expect(original.rest[0].paint[0]).toBe(width === 1440 ? 'rgb(0, 100, 50)' : 'rgb(255, 0, 0)');
        expect(original.changedPredicate[0].font[1]).toBe('14px');
        expect(original.changedPredicate[0].paint[0]).toBe('rgb(0, 0, 0)');
        expect(original.expanded[0].font[4]).toBe('normal');
        expect(original.clicked[0].checked).toBe(false);
        expect(original.otherClicked[1].checked).toBe(true);
        await page.close();
      }
    } finally { await browser.close(); }
  }, 60000);
});
