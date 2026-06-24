import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createRequire } from 'node:module';

import { afterEach, describe, expect, it, vi } from 'vitest';

import type { WorkerPool } from '../pool/types.js';
import type {
  AssetFile,
  FoundationTokens,
  SectionBlocks,
  SectionSpec,
  SiteModel,
  StaticImgRef,
  ThemeMeta,
} from '../theme/index.js';

const fixtureRoot = join(import.meta.dirname, 'fixtures/site');
const googleCssUrl = 'https://fonts.googleapis.com/css2?family=Inter';
const gstaticFontUrl = 'https://fonts.gstatic.com/s/inter/v12/inter-latin.woff2';
const fontBytes = new Uint8Array([9, 8, 7, 6]);
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
  cpSync(join(fixtureRoot, 'assets'), join(dest, 'assets'), { recursive: true });
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

function imageSpec(sectionIndex = 0): SectionSpec {
  return {
    ...semanticSpec(sectionIndex),
    sectionIndex,
    headings: ['Asset heading'],
    bodyText: ['Asset body copy.'],
    sectionHtml: [
      '<section>',
      '<h1>Asset heading</h1>',
      '<p>Asset body copy.</p>',
      '<img src="assets/logo.png" alt="Blocks Engine mark" />',
      '</section>',
    ].join(''),
  };
}

function p1Sections(): Record<string, SectionSpec[]> {
  return {
    about: [semanticSpec(0)],
    home: [imageSpec(0)],
  };
}

function imageBlockMarkup(): string {
  return [
    '<!-- wp:image -->',
    '<figure class="wp-block-image"><img src="assets/logo.png" alt="Blocks Engine mark"/></figure>',
    '<!-- /wp:image -->',
  ].join('\n');
}

function fakePool(markup: string): WorkerPool & { stopCalls: number } {
  return mappedPool(() => markup);
}

function mappedPool(markupForInput: (html: string) => string): WorkerPool & { stopCalls: number } {
  return {
    stopCalls: 0,
    async rawConvert(items: string[]) {
      return items.map((html) => ({ html: markupForInput(html), wpHtmlResidue: 0 }));
    },
    async canonicalize(items: string[]) {
      return items.map((html) => ({ html, changed: false, fixedIssues: [] }));
    },
    async stop() {
      this.stopCalls += 1;
    },
  };
}

function p1Pool(): WorkerPool & { stopCalls: number } {
  return mappedPool((html) =>
    html.includes('assets/logo.png')
      ? imageBlockMarkup()
      : '<!-- wp:paragraph -->\n<p>Stable.</p>\n<!-- /wp:paragraph -->'
  );
}

function themeMeta(): ThemeMeta {
  return {
    name: 'Fixture Theme',
    slug: 'fixture-theme',
  };
}

function foundationTokens(): FoundationTokens {
  return {
    palette: [{ name: 'Text Default', color: '#111111' }],
    typography: { body: 'Fixture Sans' },
    breakpoints: { md: '768px', lg: '960px' },
  };
}

function siteModel(root = fixtureRoot): SiteModel {
  return {
    root,
    pages: [
      {
        relPath: 'index.html',
        slug: 'home',
        html: readFileSync(join(fixtureRoot, 'index.html'), 'utf8'),
        title: 'Home',
      },
    ],
  };
}

function logoAsset(sourceRoot = fixtureRoot): AssetFile {
  return {
    relPath: 'assets/logo.png',
    sourcePath: join(sourceRoot, 'assets/logo.png'),
  };
}

function logoRef(sourceRoot = fixtureRoot): StaticImgRef {
  return {
    ref: 'assets/logo.png',
    themeRel: 'assets/logo.png',
    sourcePath: join(sourceRoot, 'assets/logo.png'),
  };
}

type AssemblePartsWithAssets = {
  site: SiteModel;
  tokens: FoundationTokens;
  pages: Record<string, SectionBlocks[]>;
  meta: ThemeMeta;
  assets: AssetFile[];
  fontCss: string;
  imgRefsByPage: Record<string, StaticImgRef[]>;
};

function textResponse(body: string): Response {
  return new Response(body, {
    status: 200,
    headers: { 'content-type': 'text/css' },
  }) as Response;
}

function bytesResponse(bytes: Uint8Array): Response {
  return new Response(bytes, {
    status: 200,
    headers: { 'content-type': 'font/woff2' },
  }) as Response;
}

function routeFetch(routes: Record<string, Response>): {
  fetchImpl: typeof fetch;
  fetchMock: ReturnType<typeof vi.fn>;
} {
  const fetchMock = vi.fn(async (input: Parameters<typeof fetch>[0]) => {
    const url = String(input);
    const response = routes[url];
    if (!response) throw new Error(`Unexpected fetch: ${url}`);

    return response.clone();
  });

  return { fetchImpl: fetchMock as unknown as typeof fetch, fetchMock };
}

function mockFontFetch(): {
  fetchImpl: typeof fetch;
  fetchMock: ReturnType<typeof vi.fn>;
} {
  const googleCss = `
    @font-face {
      font-family: 'Inter';
      src: url('${gstaticFontUrl}') format('woff2');
      font-weight: 400;
      font-style: normal;
    }
  `;

  return routeFetch({
    [googleCssUrl]: textResponse(googleCss),
    [gstaticFontUrl]: bytesResponse(fontBytes),
  });
}

function expectThemesByteIdentical(leftDir: string, rightDir: string, files: string[]): void {
  for (const file of files) {
    expect(readFileSync(join(rightDir, file))).toEqual(readFileSync(join(leftDir, file)));
  }
}

afterEach(() => {
  vi.doUnmock('../pool/pool.js');
  vi.resetModules();
});

describe('site-to-theme P0-3 orchestration', () => {
  it('assemble-threads-assets adds copied assets, font CSS, and rewrites template images', async () => {
    const { assemble } = await import('../theme/index.js');
    const parts: AssemblePartsWithAssets = {
      site: siteModel(),
      tokens: foundationTokens(),
      pages: {
        home: [
          {
            spec: imageSpec(0),
            blocks: imageBlockMarkup(),
            coverage: 1,
          },
        ],
      },
      meta: themeMeta(),
      assets: [logoAsset()],
      fontCss: '/*f*/\n@font-face { font-family: "Inter"; src: url("assets/fonts/inter.woff2"); }',
      imgRefsByPage: {
        home: [logoRef()],
      },
    };

    const model = assemble(parts as Parameters<typeof assemble>[0]);

    expect(model.assets).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          relPath: 'assets/logo.png',
        }),
      ])
    );
    expect(model.styleCss).toContain('/*f*/');
    expect(model.templates['index.html']).toContain(
      '/wp-content/themes/fixture-theme/assets/logo.png'
    );
    expect(model.templates['index.html']).not.toContain('src="assets/logo.png"');
  });

  it('assemble-threads-assets lets asset-stage entries win relPath collisions', async () => {
    const { assemble } = await import('../theme/index.js');
    const siteWithLegacyAsset = {
      ...siteModel(),
      assets: [
        {
          relPath: 'assets/logo.png',
          bytes: new Uint8Array([1, 2, 3]),
        },
      ],
    };
    const assetStageLogo = logoAsset();

    const model = assemble({
      site: siteWithLegacyAsset,
      tokens: foundationTokens(),
      pages: {},
      meta: themeMeta(),
      assets: [assetStageLogo],
    } as Parameters<typeof assemble>[0]);

    expect(model.assets.find((asset) => asset.relPath === 'assets/logo.png')).toEqual(
      assetStageLogo
    );
  });

  it('siteToTheme-e2e-assets-on-disk writes copied images and mocked font assets', async () => {
    const { siteToTheme } = await import('../theme/index.js');
    const { fetchImpl, fetchMock } = mockFontFetch();

    await withTempDir('blocks-engine-site-to-theme-assets-', async (siteDir) => {
      copyFixtureSite(siteDir);
      const outDir = join(siteDir, 'theme-out');

      const result = await siteToTheme(siteDir, {
        outDir,
        pool: p1Pool(),
        sections: p1Sections(),
        fetchImpl,
        themeMeta: themeMeta(),
      });

      expect(fetchMock).toHaveBeenCalledTimes(2);
      expect(result.written).toEqual(expect.arrayContaining(['assets/logo.png']));
      expect(existsSync(join(outDir, 'assets', 'logo.png'))).toBe(true);

      const fontFile = result.written.find((file) =>
        /^assets\/fonts\/[^/]+\.woff2$/.test(file)
      );
      expect(fontFile).toBeTypeOf('string');
      expect(existsSync(join(outDir, fontFile as string))).toBe(true);

      const styleCss = readFileSync(join(outDir, 'style.css'), 'utf8');
      expect(styleCss).toContain('assets/fonts/');
      expect(styleCss).not.toContain(gstaticFontUrl);

      const template = readFileSync(join(outDir, 'templates', 'index.html'), 'utf8');
      expect(template).toContain('/wp-content/themes/fixture-theme/assets/logo.png');
      expect(template).not.toContain('src="assets/logo.png"');
    });
  });

  it('siteToTheme-e2e-assets-on-disk keeps assets stage and assemble slug parity', async () => {
    const { siteToTheme } = await import('../theme/index.js');

    await withTempDir('blocks-engine-site-to-theme-slug-assets-', async (siteDir) => {
      copyFixtureSite(siteDir);
      const outDir = join(siteDir, 'theme-out');

      const result = await siteToTheme(siteDir, {
        outDir,
        pool: p1Pool(),
        sections: p1Sections(),
        themeMeta: {
          name: 'Fixture Theme',
          slug: 'Fixture Theme!',
        },
      });

      const template = readFileSync(join(outDir, 'templates', 'index.html'), 'utf8');
      expect(result.model.assets.map((asset) => asset.relPath)).toContain('assets/logo.png');
      expect(template).toContain('/wp-content/themes/fixture-theme/assets/logo.png');
      expect(template).not.toContain('/wp-content/themes/Fixture Theme!');
    });
  });

  it('onAssets-decoration-drop removes decorative assets from the written theme model', async () => {
    const { siteToTheme } = await import('../theme/index.js');
    const { fetchImpl } = mockFontFetch();

    await withTempDir('blocks-engine-site-to-theme-decoration-', async (siteDir) => {
      copyFixtureSite(siteDir);
      const outDir = join(siteDir, 'theme-out');
      const inventories: string[][] = [];

      const result = await siteToTheme(siteDir, {
        outDir,
        pool: p1Pool(),
        sections: p1Sections(),
        fetchImpl,
        themeMeta: themeMeta(),
        hooks: {
          async onAssets(inventory) {
            const relPaths = inventory.assets.map((asset) => asset.relPath);
            inventories.push(relPaths);
            return {
              keep: relPaths,
              decoration: ['assets/logo.png'],
            };
          },
        },
      });

      expect(inventories[0]).toContain('assets/logo.png');
      expect(result.model.assets.map((asset) => asset.relPath)).not.toContain('assets/logo.png');
      expect(result.written).not.toContain('assets/logo.png');
      expect(existsSync(join(outDir, 'assets', 'logo.png'))).toBe(false);
    });
  });

  it('hooks-absent-identity keeps asset output byte-identical for absent, empty, and identity hooks', async () => {
    const { siteToTheme } = await import('../theme/index.js');

    await withTempDir('blocks-engine-site-to-theme-hooks-assets-', async (rootDir) => {
      const siteDir = join(rootDir, 'site');
      mkdirSync(siteDir);
      copyFixtureSite(siteDir);
      const firstOut = join(rootDir, 'theme-no-hooks');
      const secondOut = join(rootDir, 'theme-empty-hooks');
      const thirdOut = join(rootDir, 'theme-identity-hooks');
      const firstFetch = mockFontFetch();
      const secondFetch = mockFontFetch();
      const thirdFetch = mockFontFetch();

      const first = await siteToTheme(siteDir, {
        outDir: firstOut,
        pool: p1Pool(),
        sections: p1Sections(),
        fetchImpl: firstFetch.fetchImpl,
        themeMeta: themeMeta(),
      });
      const second = await siteToTheme(siteDir, {
        outDir: secondOut,
        pool: p1Pool(),
        sections: p1Sections(),
        fetchImpl: secondFetch.fetchImpl,
        themeMeta: themeMeta(),
        hooks: {},
      });
      const third = await siteToTheme(siteDir, {
        outDir: thirdOut,
        pool: p1Pool(),
        sections: p1Sections(),
        fetchImpl: thirdFetch.fetchImpl,
        themeMeta: themeMeta(),
        hooks: {
          async onFoundation(tokens) {
            return tokens;
          },
          async onSection(section) {
            return section;
          },
          async onAssets(inventory) {
            return {
              keep: inventory.assets.map((asset) => asset.relPath),
              decoration: [],
            };
          },
          async onRefine(theme) {
            return theme;
          },
        },
      });

      expect(first.written).toEqual(expect.arrayContaining(['assets/logo.png']));
      expect(first.written.some((file) => file.startsWith('assets/fonts/'))).toBe(true);
      expect(second.model).toEqual(first.model);
      expect(second.written).toEqual(first.written);
      expectThemesByteIdentical(firstOut, secondOut, first.written);
      expect(third.model).toEqual(first.model);
      expect(third.written).toEqual(first.written);
      expectThemesByteIdentical(firstOut, thirdOut, first.written);
    });
  });

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

  it('react-isolation-after-full-run exports siteToTheme without loading @wordpress/blocks in this process', async () => {
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
        pool: p1Pool(),
        sections: p1Sections(),
        fetchImpl: mockFontFetch().fetchImpl,
        themeMeta: themeMeta(),
      });
    });

    expect(requireCacheEntriesForWordPressBlocks()).toEqual([]);
  });
});
