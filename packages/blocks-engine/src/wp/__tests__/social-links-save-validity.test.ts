import { beforeAll, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { setupDomGlobals } from '../dom-globals.js';

const require = createRequire(import.meta.url) as { (id: string): any };
let wp: any;
beforeAll(() => {
  setupDomGlobals();
  wp = require('@wordpress/blocks');
  require('@wordpress/block-library').registerCoreBlocks();
});

it('validates the reduced literal icon-font social link against core save()', () => {
  const fixture = JSON.parse(readFileSync(new URL(
    '../../../../../php-transformer/tests/fixtures/parity/html-social-links-literal-icon-font.json',
    import.meta.url,
  ), 'utf8'));
  const markup = fixture.expect.find((e: any) => e.path === 'serialized_blocks').value;
  const blocks = wp.parse(markup);
  const social = blocks[0].innerBlocks[1].innerBlocks[0];
  expect(social.name).toBe('core/social-links');
  expect(wp.validateBlock(social)[0]).toBe(true);
  const reloaded = wp.parse(wp.serialize(blocks))[0].innerBlocks[1].innerBlocks[0];
  expect(wp.validateBlock(reloaded)[0]).toBe(true);
  expect(reloaded.originalContent).toBe(social.originalContent);
  expect(social.attributes.size).toBe('has-normal-icon-size');
  expect(social.attributes.className).toContain('blocks-engine-social-source-cluster');
});
