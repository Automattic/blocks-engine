import { describe, expect, it, vi } from 'vitest';

import type { FixResult, WorkerPool } from '../../pool/types.js';
import {
  buildThemeConversionDiagnostics,
  deriveThemeConversionDiagnostics,
} from '../conversion-diagnostics';
import type { SectionBlocks } from '../types.js';

function fixResult(overrides: Partial<FixResult> = {}): FixResult {
  return {
    html: '<!-- wp:paragraph --><p>Canonical</p><!-- /wp:paragraph -->',
    changed: false,
    fixedIssues: [],
    blockCount: 1,
    htmlIslands: [],
    htmlIslandCount: 0,
    degraded: false,
    ...overrides,
  };
}

function section(blocks: string): SectionBlocks {
  return {
    spec: {} as SectionBlocks['spec'],
    blocks,
    coverage: 1,
  };
}

function poolReturning(results: FixResult[]): WorkerPool {
  return {
    rawConvert: vi.fn<WorkerPool['rawConvert']>(async (_items) => {
      throw new Error('rawConvert should not be used for conversion diagnostics');
    }),
    canonicalize: vi.fn<WorkerPool['canonicalize']>(async (_items) => results),
    stop: vi.fn<WorkerPool['stop']>(async () => undefined),
  };
}

describe('deriveThemeConversionDiagnostics', () => {
  it('derives per-page and aggregate conversion diagnostics from report findings', async () => {
    const pageOneBlocks = [
      '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->',
      '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
    ].join('\n\n');
    const pool = poolReturning([
      fixResult({
        html: pageOneBlocks,
        blockCount: 2,
      }),
      fixResult({
        html: '<!-- wp:html --><div>Fallback</div><!-- /wp:html -->',
        blockCount: 1,
        htmlIslands: [{ index: 0, html: '<div>Fallback</div>' }],
        htmlIslandCount: 3,
        degraded: true,
      }),
    ]);

    const diagnostics = await deriveThemeConversionDiagnostics(
      {
        clean: [section(` ${pageOneBlocks} `), section('  ')],
        fallback: [section('<!-- wp:html --><div>Fallback</div><!-- /wp:html -->')],
      },
      pool,
    );

    expect(pool.rawConvert).not.toHaveBeenCalled();
    expect(pool.stop).not.toHaveBeenCalled();
    expect(pool.canonicalize).toHaveBeenCalledWith([
      pageOneBlocks,
      '<!-- wp:html --><div>Fallback</div><!-- /wp:html -->',
    ]);
    expect(diagnostics).toEqual({
      pages: [
        {
          slug: 'clean',
          status: 'success',
          fallbackCount: 0,
          degraded: false,
        },
        {
          slug: 'fallback',
          status: 'success_with_warnings',
          fallbackCount: 3,
          degraded: true,
        },
      ],
      totalFallbacks: 3,
      pagesWithFallbacks: 1,
      degradedPages: 1,
    });
  });

  it('builds diagnostics from explicit page inputs without owning the worker pool', async () => {
    const pool = poolReturning([
      fixResult({
        html: '',
        blockCount: 0,
      }),
    ]);

    const result = await buildThemeConversionDiagnostics(
      [
        {
          slug: 'empty-output',
          inputHtml: '<main><p>Source content</p></main>',
          sections: [section('  ')],
        },
      ],
      pool,
    );

    expect(pool.canonicalize).toHaveBeenCalledWith(['']);
    expect(pool.stop).not.toHaveBeenCalled();
    expect(result).toEqual({
      pages: [
        {
          slug: 'empty-output',
          status: 'success_with_warnings',
          fallbackCount: 0,
          degraded: false,
        },
      ],
      totalFallbacks: 0,
      pagesWithFallbacks: 0,
      degradedPages: 0,
    });
  });
});
