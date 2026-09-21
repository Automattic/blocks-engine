import { beforeAll, describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { setupDomGlobals } from '../dom-globals.js';
import { canonicalize } from '../canonicalize.js';

const require = createRequire(import.meta.url);

type ParsedBlock = {
  attributes: { text?: { toString(): string } };
  innerBlocks: ParsedBlock[];
};

type WpRuntime = {
  registerCoreBlocks(): void;
  createBlock(name: string, attributes: Record<string, unknown>): unknown;
  serialize(blocks: unknown[]): string;
  parse(markup: string): ParsedBlock[];
  validateBlock(block: unknown): [boolean, unknown[]];
};

describe('core/button save validity', () => {
  let wp: WpRuntime;

  beforeAll(() => {
    setupDomGlobals();
    wp = require('@wordpress/blocks') as WpRuntime;
    const library = require('@wordpress/block-library') as Pick<WpRuntime, 'registerCoreBlocks'>;
    library.registerCoreBlocks();
  });

  it('keeps the generated control carrier on the supported block wrapper', () => {
    const button = wp.createBlock('core/button', {
      className: 'blocks-engine-control-fixture',
      text: '<span class="product-row__name">Product</span><span>$25</span>',
      url: '/product',
      style: { color: { background: '#123456' } },
    });
    const persisted = wp.serialize([button]);
    const reloaded = wp.parse(persisted);

    expect(persisted).toContain('<div class="wp-block-button blocks-engine-control-fixture">');
    expect(persisted).toContain('<a class="wp-block-button__link has-background wp-element-button"');
    expect(persisted).not.toContain('wp-block-button product-row');
    expect(persisted).not.toContain('wp-block-button__link has-background product-row');
    expect(reloaded).toHaveLength(1);
    expect(wp.validateBlock(reloaded[0])[0]).toBe(true);
  });

  it('saves per-side border styles on the button anchor', () => {
    const button = wp.createBlock('core/button', {
      text: 'Pre-order now',
      url: '/contact',
      style: {
        border: {
          top: { width: '1px', style: 'solid', color: '#ffffff' },
          bottom: { width: '1px', style: 'solid', color: '#ffffff' },
        },
      },
    });
    const persisted = wp.serialize([button]);
    const reloaded = wp.parse(persisted);

    expect(persisted).toContain('border-top-color:#ffffff');
    expect(persisted).toContain('border-top-style:solid');
    expect(persisted).toContain('border-top-width:1px');
    expect(persisted).toContain('border-bottom-color:#ffffff');
    expect(persisted).toContain('border-bottom-style:solid');
    expect(persisted).toContain('border-bottom-width:1px');
    expect(wp.validateBlock(reloaded[0])[0]).toBe(true);
  });

  it('preserves per-side border styles when parsing imported markup', () => {
    const imported = `<!-- wp:button {"tagName":"a","type":"button","url":"/contact","text":"Pre-order now","linkTarget":"_self","className":"blocks-engine-control-fixture","style":{"color":{"background":"transparent"},"border":{"top":{"width":"1px","style":"solid","color":"#ffffff"},"bottom":{"width":"1px","style":"solid","color":"#ffffff"}},"spacing":{"padding":{"top":"1px","right":"1px","bottom":"1px","left":"1px"}}},"anchor":"fixture"} -->
<div class="wp-block-button blocks-engine-control-fixture"><a class="wp-block-button__link has-background wp-element-button" style="background-color:transparent;padding-top:1px;padding-right:1px;padding-bottom:1px;padding-left:1px" href="/contact" target="_self">Pre-order now</a></div>
<!-- /wp:button -->`;
    const persisted = canonicalize(imported).html;
    const parsed = wp.parse(persisted);

    expect(persisted).toContain('border-top-color:#ffffff');
    expect(persisted).toContain('border-bottom-color:#ffffff');
    expect(wp.validateBlock(parsed[0])[0]).toBe(true);
  });

  it('round-trips separate visible and hidden button label markers from the PHP fixture', () => {
    const fixture = JSON.parse(readFileSync(new URL(
      '../../../../../php-transformer/tests/fixtures/parity/html-button-sibling-marker-labels.json',
      import.meta.url,
    ), 'utf8')) as { expect: Array<{ path: string; assert: string; value?: unknown }> };
    const markup = fixture.expect.find(({ path, assert }) =>
      path === 'serialized_blocks' && assert === 'equals',
    )?.value;
    expect(typeof markup).toBe('string');

    const parsed = wp.parse(markup as string);
    const button = parsed[0].innerBlocks[0];
    expect(wp.validateBlock(button)[0]).toBe(true);

    const roundTripped = wp.parse(wp.serialize(parsed))[0].innerBlocks[0];
    expect(wp.validateBlock(roundTripped)[0]).toBe(true);
    expect(String(roundTripped.attributes.text)).toBe(String(button.attributes.text));

    const label = document.createElement('div');
    label.innerHTML = String(roundTripped.attributes.text);
    const markers = label.querySelectorAll(':scope > mark');
    expect(markers).toHaveLength(2);
    expect(markers[0].innerHTML).toBe('Order <strong>now</strong>');
    expect(markers[1].textContent).toBe('Added!');
    expect((markers[1] as HTMLElement).style.display).toBe('none');
  });
});
