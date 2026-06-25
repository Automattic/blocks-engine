import { describe, expect, it } from 'vitest';

import type { WorkerPool } from '../pool/types.js';
import {
  captureSectionContent,
  hasUnmigratedRemoteAsset,
  measureSectionCoverage,
  reconstruct,
  type SectionSpec,
  type StageCtx,
} from '../theme/index.js';

function sectionSpec(overrides: Partial<SectionSpec> = {}): SectionSpec {
  return {
    sectionIndex: 0,
    interactionModel: 'static',
    top: 0,
    height: 0,
    headings: [],
    bodyText: [],
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
    ...overrides,
  };
}

function sectionImage(
  url: string,
  overrides: Partial<SectionSpec['images'][number]> = {}
): SectionSpec['images'][number] {
  return {
    url,
    sourceUrl: url,
    alt: 'Hero image',
    kind: 'img',
    width: 1200,
    height: 800,
    ...overrides,
  };
}

function fakePool(markupForInput: (html: string) => string): WorkerPool {
  return {
    async rawConvert(items: string[]) {
      return items.map((html) => ({ html: markupForInput(html), wpHtmlResidue: 0 }));
    },
    async canonicalize(items: string[]) {
      return items.map((html) => ({ html, changed: false, fixedIssues: [] }));
    },
    async stop() {},
  };
}

function stageCtx(): StageCtx {
  return {
    srcDir: '/tmp/site',
    site: { root: '/tmp/site', pages: [] },
    themeMeta: { name: 'Fixture Theme', slug: 'fixture-theme' },
    warn() {},
  };
}

describe('theme reconstruct coverage gate', () => {
  it('falls back from lossy native blocks to a verbatim coverage island', async () => {
    const spec = sectionSpec({
      headings: ['Build calmer block themes'],
      bodyText: ['Static source pages become a structured theme pipeline.'],
      buttonLabels: ['Learn more'],
      sectionHtml: [
        '<section id="hero">',
        '<h1>Build calmer block themes</h1>',
        '<p>Static source pages become a structured theme pipeline.</p>',
        '<a class="button" href="./about.html">Learn more</a>',
        '</section>',
      ].join(''),
    });
    const lossyNative =
      '<!-- wp:buttons -->\n<div class="wp-block-buttons">Learn more</div>\n<!-- /wp:buttons -->';

    const [section] = await reconstruct(
      [spec],
      stageCtx(),
      fakePool(() => lossyNative),
      {},
      0
    );

    expect(section.blocks).toContain('metadata":{"name":"lib-coverage-island"}');
    expect(section.blocks).toContain('<h1>Build calmer block themes</h1>');
    expect(section.blocks).toContain(
      '<p>Static source pages become a structured theme pipeline.</p>'
    );
    expect(section.blocks).not.toBe(lossyNative);
    expect(measureSectionCoverage(captureSectionContent(spec), section.blocks).lost).toBe(false);
    expect(section.coverage).toBe(0);
  });

  it('keeps full-coverage native blocks unchanged', async () => {
    const spec = sectionSpec({
      headings: ['Native heading'],
      bodyText: ['Native body copy.'],
      sectionHtml: '<section><h2>Native heading</h2><p>Native body copy.</p></section>',
    });
    const nativeBlocks = [
      '<!-- wp:heading -->',
      '<h2>Native heading</h2>',
      '<!-- /wp:heading -->',
      '<!-- wp:paragraph -->',
      '<p>Native body copy.</p>',
      '<!-- /wp:paragraph -->',
    ].join('\n');

    const [section] = await reconstruct(
      [spec],
      stageCtx(),
      fakePool(() => nativeBlocks),
      {},
      0
    );

    expect(section.blocks).toBe(nativeBlocks);
    expect(section.blocks).not.toContain('lib-coverage-island');
    expect(section.coverage).toBe(1);
  });

  it('falls back from converted native blocks with unmigrated remote assets to an island', async () => {
    const remoteImage = 'https://cdn.example.com/uploads/hero.jpg';
    const spec = sectionSpec({
      headings: ['Remote asset hero'],
      bodyText: ['Coverage can pass while asset provenance fails.'],
      images: [sectionImage(remoteImage)],
      sectionHtml: [
        '<section>',
        '<h2>Remote asset hero</h2>',
        '<p>Coverage can pass while asset provenance fails.</p>',
        `<img src="${remoteImage}" alt="Hero image">`,
        '</section>',
      ].join(''),
    });
    const convertedNative = [
      '<!-- wp:heading -->',
      '<h2>Remote asset hero</h2>',
      '<!-- /wp:heading -->',
      '<!-- wp:paragraph -->',
      '<p>Coverage can pass while asset provenance fails.</p>',
      '<!-- /wp:paragraph -->',
      '<!-- wp:image -->',
      `<figure class="wp-block-image"><img src="${remoteImage}" alt="Hero image"></figure>`,
      '<!-- /wp:image -->',
    ].join('\n');

    expect(hasUnmigratedRemoteAsset(convertedNative)).toBe(true);

    const [section] = await reconstruct(
      [spec],
      stageCtx(),
      fakePool(() => convertedNative),
      {},
      0
    );

    expect(section.blocks).toContain('metadata":{"name":"lib-coverage-island"}');
    expect(section.blocks).not.toBe(convertedNative);
    expect(section.coverage).toBe(0);
  });

  it('falls back from converted native blocks with injection or placeholders to an island', async () => {
    const injectionSpec = sectionSpec({
      headings: ['Safe hero'],
      bodyText: ['Fallback copy survives.'],
      sectionHtml:
        '<section><h2>Safe hero</h2><p>Fallback copy survives.</p><script>alert(1)</script></section>',
    });
    const injectedNative = [
      '<!-- wp:heading -->',
      '<h2>Safe hero</h2>',
      '<!-- /wp:heading -->',
      '<!-- wp:paragraph -->',
      '<p>Fallback copy survives.</p>',
      '<!-- /wp:paragraph -->',
      '<script>alert(1)</script>',
    ].join('\n');

    const [injectedSection] = await reconstruct(
      [injectionSpec],
      stageCtx(),
      fakePool(() => injectedNative),
      {},
      0
    );

    expect(injectedSection.blocks).toContain('metadata":{"name":"lib-coverage-island"}');
    expect(injectedSection.blocks).toContain('<p>Fallback copy survives.</p>');
    expect(injectedSection.blocks).not.toContain('<script');
    expect(injectedSection.blocks).not.toBe(injectedNative);

    const placeholderSpec = sectionSpec({
      headings: ['Personalized hero'],
      bodyText: ['Clean fallback copy.'],
      sectionHtml: '<section><h2>Personalized hero</h2><p>Clean fallback copy.</p></section>',
    });
    const placeholderNative = [
      '<!-- wp:heading -->',
      '<h2>Personalized hero</h2>',
      '<!-- /wp:heading -->',
      '<!-- wp:paragraph -->',
      '<p>Clean fallback copy.</p>',
      '<!-- /wp:paragraph -->',
      '<!-- wp:paragraph -->',
      '<p>{{first-name}}</p>',
      '<!-- /wp:paragraph -->',
    ].join('\n');

    const [placeholderSection] = await reconstruct(
      [placeholderSpec],
      stageCtx(),
      fakePool(() => placeholderNative),
      {},
      0
    );

    expect(placeholderSection.blocks).toContain('metadata":{"name":"lib-coverage-island"}');
    expect(placeholderSection.blocks).toContain('<p>Clean fallback copy.</p>');
    expect(placeholderSection.blocks).not.toContain('{{first-name}}');
    expect(placeholderSection.blocks).not.toBe(placeholderNative);
  });

  it('keeps converted native blocks when converted coverage matches image basename', async () => {
    const remoteImage = 'https://cdn.example.com/uploads/hero.jpg?width=1200';
    const uploadsImage = '/wp-content/uploads/2026/06/hero.jpg';
    const spec = sectionSpec({
      headings: ['Native image hero'],
      bodyText: ['Converted image basename is enough.'],
      images: [sectionImage(remoteImage)],
      sectionHtml: [
        '<section>',
        '<h2>Native image hero</h2>',
        '<p>Converted image basename is enough.</p>',
        `<img src="${remoteImage}" alt="Hero image">`,
        '</section>',
      ].join(''),
    });
    const nativeBlocks = [
      '<!-- wp:heading -->',
      '<h2>Native image hero</h2>',
      '<!-- /wp:heading -->',
      '<!-- wp:paragraph -->',
      '<p>Converted image basename is enough.</p>',
      '<!-- /wp:paragraph -->',
      '<!-- wp:image -->',
      `<figure class="wp-block-image"><img src="${uploadsImage}" alt="Hero image"></figure>`,
      '<!-- /wp:image -->',
    ].join('\n');

    const [section] = await reconstruct(
      [spec],
      stageCtx(),
      fakePool(() => nativeBlocks),
      {},
      0
    );

    expect(hasUnmigratedRemoteAsset(nativeBlocks)).toBe(false);
    expect(section.blocks).toBe(nativeBlocks);
    expect(section.blocks).not.toContain('lib-coverage-island');
    expect(section.coverage).toBe(1);
  });

  it('keeps existing onSection coverage-floor gating after fallback', async () => {
    const spec = sectionSpec({
      headings: ['Hooked hero'],
      bodyText: ['Copy that should survive.'],
      buttonLabels: ['Act now'],
      sectionHtml:
        '<section><h1>Hooked hero</h1><p>Copy that should survive.</p><a>Act now</a></section>',
    });
    const seenCoverages: number[] = [];

    const [section] = await reconstruct(
      [spec],
      stageCtx(),
      fakePool(
        () =>
          '<!-- wp:buttons -->\n<div class="wp-block-buttons">Act now</div>\n<!-- /wp:buttons -->'
      ),
      {
        async onSection(section) {
          seenCoverages.push(section.coverage);
          return {
            ...section,
            blocks: `${section.blocks}\n<!-- hooked -->`,
          };
        },
      },
      0
    );

    expect(seenCoverages).toEqual([0]);
    expect(section.blocks).toContain('lib-coverage-island');
    expect(section.blocks).toContain('<!-- hooked -->');
  });
});
