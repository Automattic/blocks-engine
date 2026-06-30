import { describe, expect, it } from 'vitest';

import { createWorker } from '../../pool/pool';
import { FALLBACK_INVENTORY_CAP } from '../../report/schema';
import { canonicalize } from '../canonicalize';

function htmlBlock(html: string): string {
  return `<!-- wp:html -->${html}<!-- /wp:html -->`;
}

async function withWorkerTestMode<T>(fn: () => Promise<T>): Promise<T> {
  const previousMode = process.env.BLOCKS_ENGINE_WORKER_TEST_MODE;
  process.env.BLOCKS_ENGINE_WORKER_TEST_MODE = '1';

  try {
    return await fn();
  } finally {
    if (previousMode === undefined) {
      delete process.env.BLOCKS_ENGINE_WORKER_TEST_MODE;
    } else {
      process.env.BLOCKS_ENGINE_WORKER_TEST_MODE = previousMode;
    }
  }
}

describe('canonicalize inventory metadata', () => {
  it('walks grammar-level parser blocks and records raw core/html islands', () => {
    const firstIsland = '<section data-fallback="1"><span>Raw</span></section>';
    const secondIsland = '<script type="application/json">{"x":1}</script>';
    const markup = [
      '<!-- wp:group -->',
      '<div class="wp-block-group">',
      '<!-- wp:paragraph -->',
      '<p>Intro</p>',
      '<!-- /wp:paragraph -->',
      htmlBlock(firstIsland),
      '<!-- wp:group -->',
      '<div class="wp-block-group">',
      htmlBlock(secondIsland),
      '</div>',
      '<!-- /wp:group -->',
      '</div>',
      '<!-- /wp:group -->',
    ].join('');

    expect(canonicalize(markup)).toMatchObject({
      blockCount: 5,
      htmlIslands: [
        { index: 0, html: `\n${firstIsland}\n` },
        { index: 1, html: `\n${secondIsland}\n` },
      ],
      htmlIslandCount: 2,
      degraded: false,
    });
  });

  it('caps htmlIslands while preserving the true htmlIslandCount', () => {
    const total = FALLBACK_INVENTORY_CAP + 2;
    const markup = Array.from({ length: total }, (_value, index) =>
      htmlBlock(`<div>Fallback ${index}</div>`),
    ).join('');

    const result = canonicalize(markup);

    expect(result.blockCount).toBe(total);
    expect(result.htmlIslandCount).toBe(total);
    expect(result.htmlIslands).toHaveLength(FALLBACK_INVENTORY_CAP);
    expect(result.htmlIslands[0]).toEqual({ index: 0, html: '\n<div>Fallback 0</div>\n' });
    expect(result.htmlIslands.at(-1)).toEqual({
      index: FALLBACK_INVENTORY_CAP - 1,
      html: `\n<div>Fallback ${FALLBACK_INVENTORY_CAP - 1}</div>\n`,
    });
    expect(result.degraded).toBe(false);
  });

  it('returns degraded safe defaults when the pool emits a canonicalize sentinel', async () =>
    withWorkerTestMode(async () => {
      const input = `<!-- BLOCKS_ENGINE_TEST_HANG -->${htmlBlock('<div>never parsed</div>')}`;
      const pool = createWorker({
        size: 1,
        maxReroutes: 0,
        itemTimeoutMs: 50,
      });

      try {
        await expect(pool.canonicalize([input])).resolves.toEqual([
          {
            html: input,
            changed: false,
            fixedIssues: [],
            blockCount: 0,
            htmlIslands: [],
            htmlIslandCount: 0,
            degraded: true,
          },
        ]);
      } finally {
        await pool.stop();
      }
    }), 20_000);
});
