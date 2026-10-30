import { beforeAll, describe, expect, it, vi } from 'vitest';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { setupDomGlobals } from '../dom-globals.js';

const require = createRequire(import.meta.url) as { (id: string): any; resolve(id: string): string };
type Block = { name: string; innerBlocks: Block[]; attributes: Record<string, unknown> };
type Wp = {
  parse(markup: string): Block[];
  validateBlock(block: Block): [boolean, unknown[]];
};
const transformer = fileURLToPath(new URL('../../../../../php-transformer/', import.meta.url));

describe('text/video author grid Gutenberg save validity', () => {
  let wp: Wp;
  beforeAll(() => {
    setupDomGlobals();
    wp = require('@wordpress/blocks') as Wp;
    require('@wordpress/block-library').registerCoreBlocks();
  });

  it('detects the former media-text video attribute mismatch', () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    try {
      const blocks = wp.parse('<!-- wp:media-text {"mediaType":"video"} --><div class="wp-block-media-text is-stacked-on-mobile"><figure class="wp-block-media-text__media"><video controls src="clip.mp4" poster="poster.jpg" preload="metadata" loop="loop" muted="muted" playsinline="playsinline"></video></figure><div class="wp-block-media-text__content"></div></div><!-- /wp:media-text -->');
      expect(wp.validateBlock(blocks[0])[0]).toBe(false);
    } finally {
      error.mockRestore();
      warn.mockRestore();
    }
  });

  it.each([true, false])('validates the public transformer output (controls=%s)', (controls) => {
    const markup = execFileSync('php', ['-r', `
      require 'vendor/autoload.php';
      $html = file_get_contents('tests/fixtures/text-video-grid.html');
      if (${controls ? 'false' : 'true'}) $html = str_replace(' controls', '', $html);
      echo (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($html)->toArray()['serialized_blocks'];
    `], { cwd: transformer, encoding: 'utf8' });
    const flatten = (blocks: Block[]): Block[] => blocks.flatMap((block) => [block, ...flatten(block.innerBlocks)]);
    const blocks = flatten(wp.parse(markup));
    expect(blocks.map((block) => wp.validateBlock(block)[0])).toEqual(blocks.map(() => true));
    expect(blocks.some((block) => block.name === 'core/media-text')).toBe(false);
    const video = blocks.find((block) => block.name === 'core/video');
    expect(video?.attributes).toMatchObject({ src: 'clip.mp4', poster: 'poster.jpg', preload: 'metadata', controls, loop: true, muted: true, playsInline: true });
  });
});
