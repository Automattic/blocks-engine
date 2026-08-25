import { beforeAll, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { setupDomGlobals } from '../dom-globals.js';

const require = createRequire(import.meta.url);

type ParsedBlock = { attributes: { fileName?: { toString(): string } } };
type ParityFixture = { expect: Array<{ path: string; assert: string; value?: unknown }> };

type WpRuntime = {
  registerCoreBlocks(): void;
  parse(markup: string): ParsedBlock[];
  validateBlock(block: unknown): [boolean, unknown[]];
};

const fixture = JSON.parse(
  readFileSync(
    new URL(
      '../../../../../php-transformer/tests/fixtures/parity/html-file-rich-text-label.json',
      import.meta.url,
    ),
    'utf8',
  ),
) as ParityFixture;

const serializedBlocks = fixture.expect.find(
  ({ path, assert }) => path === 'serialized_blocks' && assert === 'equals',
)?.value;

describe('WordPress 7.1 core/file save validity', () => {
  let wp: WpRuntime;

  beforeAll(() => {
    setupDomGlobals();
    wp = require('@wordpress/blocks') as WpRuntime;
    const library = require('@wordpress/block-library') as Pick<WpRuntime, 'registerCoreBlocks'>;
    library.registerCoreBlocks();
  });

  it('reproduces the invalid nested-span serialization', () => {
    const markup = '<!-- wp:file {"href":"/docs/privacy.pdf","text":"<span class=\\"pdf-label\\">Privacy policy</span>"} --><div class="wp-block-file"><a href="/docs/privacy.pdf"><span class="pdf-label">Privacy policy</span></a></div><!-- /wp:file -->';
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const consoleWarn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    const blocks = wp.parse(markup);

    expect(blocks).toHaveLength(1);
    expect(wp.validateBlock(blocks[0])[0]).toBe(false);
    expect(consoleError).toHaveBeenCalled();
    consoleError.mockRestore();
    consoleWarn.mockRestore();
  });

  it('accepts the transformer fixture output as sourced RichText', () => {
    expect(typeof serializedBlocks).toBe('string');
    const blocks = wp.parse(serializedBlocks as string);

    expect(blocks).toHaveLength(3);
    expect(blocks[0].attributes.fileName?.toString()).toBe('<mark class="pdf-label" style="--blocks-engine-richtext-marker:label-1"><strong>Privacy</strong> policy</mark>');
    expect(blocks.map((block) => wp.validateBlock(block)[0])).toEqual([true, true, true]);
  });
});
