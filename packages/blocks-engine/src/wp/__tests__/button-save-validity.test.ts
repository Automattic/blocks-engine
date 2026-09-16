import { beforeAll, describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { setupDomGlobals } from '../dom-globals.js';

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
