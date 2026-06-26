import { describe, expect, it } from 'vitest';

import type { WorkerPool } from '../../pool/types.js';
import { reconstruct, reconstructNativeAggregate } from '../reconstruct.js';
import type { SectionSpec } from '../section-spec.js';
import type { StageCtx } from '../types.js';

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
  overrides: Partial<SectionSpec['images'][number]> = {},
): SectionSpec['images'][number] {
  return {
    url,
    sourceUrl: url,
    alt: 'Fixture image',
    kind: 'img',
    width: 1200,
    height: 800,
    ...overrides,
  };
}

function fakePool(): WorkerPool {
  return {
    async rawConvert(items: string[]) {
      return items.map((html) => ({ html, wpHtmlResidue: 0 }));
    },
    async canonicalize(items: string[]) {
      return items.map((html) => ({ html, changed: false, fixedIssues: [] }));
    },
    async stop() {},
  };
}

function stageCtx(overrides: Partial<StageCtx> & Record<string, unknown> = {}): StageCtx {
  return {
    srcDir: '/tmp/strategy-site',
    site: { root: '/tmp/strategy-site', pages: [] },
    themeMeta: { name: 'Strategy Fixture', slug: 'strategy-fixture' },
    warn() {},
    ...overrides,
  };
}

const convertedMarkup = [
  '<!-- wp:heading -->',
  '<h2>Converted service</h2>',
  '<!-- /wp:heading -->',
  '<!-- wp:paragraph -->',
  '<p>Converted copy survives intact.</p>',
  '<!-- /wp:paragraph -->',
].join('\n');

const sections: SectionSpec[] = [
  sectionSpec({
    sectionIndex: 1,
    interactionModel: 'cover-with-headline',
    height: 720,
    headings: ['Cover hero'],
    bodyText: ['Hero body copy.'],
    fullBleed: true,
    images: [sectionImage('/wp-content/uploads/2026/hero.jpg', { width: 1440, height: 900 })],
    sectionHtml:
      '<section id="hero" class="hero cover"><h1>Cover hero</h1><p>Hero body copy.</p><img src="/wp-content/uploads/2026/hero.jpg" alt="Fixture image"></section>',
  }),
  sectionSpec({
    sectionIndex: 0,
    headings: ['Native text'],
    bodyText: ['Native body copy.'],
    sectionHtml: '<section id="native" class="band text"><h2>Native text</h2><p>Native body copy.</p></section>',
  }),
  sectionSpec({
    sectionIndex: 2,
    headings: ['Converted service'],
    bodyText: ['Converted copy survives intact.'],
    sectionHtml:
      '<section id="converted" class="service"><h2>Converted service</h2><p>Converted copy survives intact.</p></section>',
  }),
  sectionSpec({
    sectionIndex: 3,
    headings: ['Lossy fallback'],
    bodyText: ['Fallback body copy.'],
    images: [sectionImage('https://cdn.example.com/lossy.jpg')],
    sectionHtml:
      '<section id="lossy" class="fallback"><h2>Lossy fallback</h2><p>Fallback body copy.</p><img src="https://cdn.example.com/lossy.jpg" alt="Fixture image"></section>',
  }),
];

const options = {
  sourceUrl: 'https://example.com/strategy',
  slug: 'strategy',
  convertedSections: new Map([[2, { markup: convertedMarkup, wpHtmlResidue: 0 }]]),
};

function frozenAggregate() {
  const aggregate = reconstructNativeAggregate(sections, options);
  return {
    sectionMarkup: aggregate.sectionMarkup,
    heroIsCover: aggregate.heroIsCover,
    provenanceFlags: aggregate.provenanceFlags,
    expectedText: aggregate.expectedText,
    bodyText: aggregate.bodyText,
    expectedAssets: aggregate.expectedAssets,
  };
}

describe('reconstruct strategy seam default path', () => {
  it('keeps the pre-seam direct aggregate byte-identical without dedup output', () => {
    const aggregate = reconstructNativeAggregate(sections, options);
    console.info(`Strategy seam default byte-identity cases=${sections.length}`);
    expect(aggregate.dedup).toBeUndefined();
    expect(frozenAggregate()).toMatchInlineSnapshot(`
      {
        "bodyText": [
          "Hero body copy.",
          "Native body copy.",
          "Converted copy survives intact.",
        ],
        "expectedAssets": [
          "/wp-content/uploads/2026/hero.jpg",
        ],
        "expectedText": [
          "Cover hero",
          "Native text",
          "Converted service",
        ],
        "heroIsCover": true,
        "provenanceFlags": [
          "html-to-blocks#2: converted native blocks (0 wp:html, text 100%)",
          "html-fallback#3: structured render dropped content (1 images missing, text 100%) — emitted verbatim core/html",
        ],
        "sectionMarkup": [
          "<!-- wp:cover {"url":"/wp-content/uploads/2026/hero.jpg","dimRatio":40,"overlayColor":"surface-inverse","isUserOverlayColor":true,"minHeight":50,"minHeightUnit":"vw","align":"full","style":{"spacing":{"margin":{"top":"0px"}}},"layout":{"type":"constrained"}} -->
      <div class="wp-block-cover alignfull" style="margin-top:0px;min-height:50vw"><img class="wp-block-cover__image-background" src="/wp-content/uploads/2026/hero.jpg" alt="Fixture image" data-object-fit="cover"/><span aria-hidden="true" class="wp-block-cover__background has-surface-inverse-background-color has-background-dim-40 has-background-dim"></span>
      <div class="wp-block-cover__inner-container">
      <!-- wp:heading {"textAlign":"center","level":1,"fontFamily":"display","textColor":"text-inverse"} -->
      <h1 class="wp-block-heading has-text-align-center has-text-inverse-color has-text-color has-display-font-family">Cover hero</h1>
      <!-- /wp:heading -->
      <!-- wp:paragraph {"align":"center","textColor":"text-inverse"} -->
      <p class="has-text-align-center has-text-inverse-color has-text-color">Hero body copy.</p>
      <!-- /wp:paragraph -->
      </div>
      </div>
      <!-- /wp:cover -->",
          "<!-- wp:group {"tagName":"section","align":"full","backgroundColor":"surface-inverse","textColor":"text-inverse","style":{"spacing":{"margin":{"top":"0","bottom":"0"},"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60","left":"var:preset|spacing|40","right":"var:preset|spacing|40"},"blockGap":"var:preset|spacing|40"}},"layout":{"type":"constrained","contentSize":"760px"}} -->
      <section class="wp-block-group alignfull has-surface-inverse-background-color has-text-inverse-color has-text-color has-background" style="margin-top:0;margin-bottom:0;padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--40)">
      <!-- wp:heading {"textAlign":"center","level":1,"fontFamily":"display","textColor":"text-inverse"} -->
      <h1 class="wp-block-heading has-text-align-center has-text-inverse-color has-text-color has-display-font-family">Native text</h1>
      <!-- /wp:heading -->
      <!-- wp:paragraph {"align":"center","textColor":"text-inverse"} -->
      <p class="has-text-align-center has-text-inverse-color has-text-color">Native body copy.</p>
      <!-- /wp:paragraph -->
      </section>
      <!-- /wp:group -->",
          "<!-- wp:heading -->
      <h2>Converted service</h2>
      <!-- /wp:heading -->
      <!-- wp:paragraph -->
      <p>Converted copy survives intact.</p>
      <!-- /wp:paragraph -->",
          "<!-- wp:html {"metadata":{"name":"lib-coverage-island"}} -->
      <section id="lossy" class="fallback"><h2>Lossy fallback</h2><p>Fallback body copy.</p><img src="https://cdn.example.com/lossy.jpg" alt="Fixture image"></section>
      <!-- /wp:html -->",
        ],
      }
    `);
  });

  it('keeps reconstruct() routed through the same default aggregate bytes', async () => {
    const aggregate = reconstructNativeAggregate(sections, options);
    const routed = await reconstruct(
      sections,
      stageCtx(options),
      fakePool(),
      {},
      0,
    );
    expect(routed.map((section) => section.blocks)).toEqual(aggregate.sectionMarkup);
  });
});
