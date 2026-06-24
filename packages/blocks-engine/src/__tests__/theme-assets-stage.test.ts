import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { assets, ingest, type StageCtx } from '../theme/index.js';

const fixtureRoot = join(import.meta.dirname, 'fixtures/site');

function fixtureCtx(): StageCtx {
  const site = ingest(fixtureRoot);

  return {
    srcDir: fixtureRoot,
    site,
    themeMeta: {
      name: 'Fixture Theme',
      slug: 'fixture-theme',
    },
    warn() {},
  };
}

describe('theme assets stage', () => {
  it('returns static inventory without wiring font CSS in P1-1', async () => {
    const result = await assets(fixtureCtx(), {});

    expect(result.inventory.assets).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          relPath: 'assets/logo.png',
        }),
      ])
    );
    expect(result.fontCss).toBe('');
    expect(result.warnings).toEqual([]);
  });
});
