import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createRequire } from 'node:module';

import { afterEach, describe, expect, it, vi } from 'vitest';

import type { WorkerPool } from '../pool/types.js';
import type { SectionBlocks, SectionSpec } from '../theme/index.js';

const fixtureRoot = join(import.meta.dirname, 'fixtures/site');
const requireFromHere = createRequire(import.meta.url);
const requireWithCache = requireFromHere as typeof requireFromHere & {
  resolve(id: string): string;
  cache: Record<string, unknown>;
};

function requireCacheEntriesForWordPressBlocks(): string[] {
  return Object.keys(requireWithCache.cache).filter((entry) =>
    entry.includes('/node_modules/@wordpress/blocks/'),
  );
}

function clearWordPressBlocksRequireCache(): void {
  for (const entry of requireCacheEntriesForWordPressBlocks()) {
    delete requireWithCache.cache[entry];
  }
}

async function withTempDir<T>(prefix: string, fn: (dir: string) => Promise<T> | T): Promise<T> {
  const dir = mkdtempSync(join(tmpdir(), prefix));
  try {
    return await fn(dir);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

function copyFixtureSite(dest: string): void {
  for (const file of ['index.html', 'about.html', 'style.css']) {
    writeFileSync(join(dest, file), readFileSync(join(fixtureRoot, file), 'utf8'), 'utf8');
  }
}

function semanticSpec(sectionIndex = 0): SectionSpec {
  return {
    sectionIndex,
    interactionModel: 'static',
    top: 0,
    height: 0,
    headings: ['Semantic heading'],
    bodyText: ['Semantic body copy.'],
    buttonLabels: [],
    images: [],
    icons: [],
    backgroundBrightness: 1,
    backgroundColor: 'transparent',
    gradient: null,
    gradientSource: null,
    motionProfile: {
      motionClass: 'none',
      signals: [],
      animatedElements: 0,
    },
    dividerAbove: null,
    dividerBelow: null,
    layout: {
      containerWidth: 0,
      padding: '0',
      childLayout: 'stack',
      columnCount: 1,
      gap: '0',
    },
    sectionHtml: '<h1>Semantic heading</h1><p>Semantic body copy.</p>',
  };
}

function fakePool(markup: string): WorkerPool & { stopCalls: number } {
  return {
    stopCalls: 0,
    async rawConvert(items: string[]) {
      return items.map(() => ({ html: markup, wpHtmlResidue: 0 }));
    },
    async canonicalize(items: string[]) {
      return items.map((html) => ({ html, changed: false, fixedIssues: [] }));
    },
    async stop() {
      this.stopCalls += 1;
    },
  };
}

afterEach(() => {
  vi.doUnmock('../pool/pool.js');
  vi.resetModules();
});

describe('site-to-theme P0-3 orchestration', () => {
  it('writes a lintable block theme with native block markup for semantic input', async () => {
    const { siteToTheme, lintThemeJson } = await import('../theme/index.js');

    await withTempDir('blocks-engine-site-to-theme-', async (siteDir) => {
      copyFixtureSite(siteDir);
      const outDir = join(siteDir, 'theme-out');

      const result = await siteToTheme(siteDir, {
        outDir,
        sections: { about: [semanticSpec(0)], home: [semanticSpec(0)] },
      });

      expect(result.written).toEqual(
        expect.arrayContaining(['style.css', 'theme.json', 'templates/index.html'])
      );
      expect(existsSync(join(outDir, 'style.css'))).toBe(true);
      expect(existsSync(join(outDir, 'theme.json'))).toBe(true);
      expect(existsSync(join(outDir, 'templates', 'index.html'))).toBe(true);

      const themeJson = JSON.parse(readFileSync(join(outDir, 'theme.json'), 'utf8'));
      expect(lintThemeJson(themeJson)).toEqual({ ok: true, errors: [] });

      const template = readFileSync(join(outDir, 'templates', 'index.html'), 'utf8');
      expect(template).toContain('<!-- wp:heading');
      expect(template).toContain('<!-- wp:paragraph');
      expect(template).not.toMatch(/^<!-- wp:html -->[\s\S]*<!-- \/wp:html -->$/);
    });
  });

  it('treats absent hooks the same as empty and explicit identity hooks byte-for-byte', async () => {
    const { siteToTheme } = await import('../theme/index.js');

    await withTempDir('blocks-engine-site-to-theme-a-', async (rootDir) => {
      const siteDir = join(rootDir, 'site');
      mkdirSync(siteDir);
      copyFixtureSite(siteDir);
      const firstOut = join(rootDir, 'theme-no-hooks');
      const secondOut = join(rootDir, 'theme-empty-hooks');
      const thirdOut = join(rootDir, 'theme-identity-hooks');
      const pool = fakePool('<!-- wp:paragraph -->\n<p>Stable.</p>\n<!-- /wp:paragraph -->');
      const emptyHooksPool = fakePool('<!-- wp:paragraph -->\n<p>Stable.</p>\n<!-- /wp:paragraph -->');
      const identityHooksPool = fakePool('<!-- wp:paragraph -->\n<p>Stable.</p>\n<!-- /wp:paragraph -->');

      const first = await siteToTheme(siteDir, {
        outDir: firstOut,
        pool,
        sections: { about: [semanticSpec(0)], home: [semanticSpec(0)] },
      });
      const second = await siteToTheme(siteDir, {
        outDir: secondOut,
        pool: emptyHooksPool,
        sections: { about: [semanticSpec(0)], home: [semanticSpec(0)] },
        hooks: {},
      });
      const third = await siteToTheme(siteDir, {
        outDir: thirdOut,
        pool: identityHooksPool,
        sections: { about: [semanticSpec(0)], home: [semanticSpec(0)] },
        hooks: {
          async onFoundation(tokens) {
            return tokens;
          },
          async onSection(section) {
            return section;
          },
          async onRefine(theme) {
            return theme;
          },
        },
      });

      expect(second.model).toEqual(first.model);
      expect(readFileSync(join(secondOut, 'theme.json'), 'utf8')).toBe(
        readFileSync(join(firstOut, 'theme.json'), 'utf8')
      );
      expect(readFileSync(join(secondOut, 'templates', 'index.html'), 'utf8')).toBe(
        readFileSync(join(firstOut, 'templates', 'index.html'), 'utf8')
      );
      expect(third.model).toEqual(first.model);
      expect(readFileSync(join(thirdOut, 'theme.json'), 'utf8')).toBe(
        readFileSync(join(firstOut, 'theme.json'), 'utf8')
      );
      expect(readFileSync(join(thirdOut, 'templates', 'index.html'), 'utf8')).toBe(
        readFileSync(join(firstOut, 'templates', 'index.html'), 'utf8')
      );
    });
  });

  it('uses injected pools without stopping them and runs onSection for coverageFloor 1', async () => {
    const { siteToTheme } = await import('../theme/index.js');

    await withTempDir('blocks-engine-site-to-theme-pool-', async (siteDir) => {
      copyFixtureSite(siteDir);
      const pool = fakePool('<!-- wp:heading -->\n<h2>From pool</h2>\n<!-- /wp:heading -->');
      const seenSections: SectionBlocks[] = [];

      await siteToTheme(siteDir, {
        outDir: join(siteDir, 'theme-out'),
        pool,
        coverageFloor: 1,
        sections: { about: [semanticSpec(0)], home: [semanticSpec(0)] },
        hooks: {
          async onSection(section) {
            seenSections.push(section);
            return section;
          },
        },
      });

      expect(seenSections).toHaveLength(2);
      expect(pool.stopCalls).toBe(0);
    });
  });

  it('stops the one-shot pool it creates internally', async () => {
    const pool = fakePool('<!-- wp:paragraph -->\n<p>Owned.</p>\n<!-- /wp:paragraph -->');

    vi.doMock('../pool/pool.js', () => ({
      createWorker: () => pool,
    }));

    const { siteToTheme } = await import('../theme/site-to-theme.js');

    await withTempDir('blocks-engine-site-to-theme-owned-', async (siteDir) => {
      copyFixtureSite(siteDir);

      await siteToTheme(siteDir, {
        outDir: join(siteDir, 'theme-out'),
        sections: { about: [semanticSpec(0)], home: [semanticSpec(0)] },
      });

      expect(pool.stopCalls).toBe(1);
    });
  });

  it('exports siteToTheme from the main entry without loading @wordpress/blocks in this process', async () => {
    clearWordPressBlocksRequireCache();

    const entry = await import('../index.js');

    expect(entry.siteToTheme).toBeTypeOf('function');
    expect(entry.writeTheme).toBeTypeOf('function');
    expect(entry.lintThemeJson).toBeTypeOf('function');
    expect(requireCacheEntriesForWordPressBlocks()).toEqual([]);

    await withTempDir('blocks-engine-site-to-theme-main-entry-', async (siteDir) => {
      copyFixtureSite(siteDir);
      await entry.siteToTheme(siteDir, {
        outDir: join(siteDir, 'theme-out'),
        pool: fakePool('<!-- wp:paragraph -->\n<p>Main entry.</p>\n<!-- /wp:paragraph -->'),
        sections: { about: [semanticSpec(0)], home: [semanticSpec(0)] },
      });
    });

    expect(requireCacheEntriesForWordPressBlocks()).toEqual([]);
  });
});
