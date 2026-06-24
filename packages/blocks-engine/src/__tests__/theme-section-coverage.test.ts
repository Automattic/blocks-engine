import { describe, expect, it } from 'vitest';

import {
  captureSectionContent,
  measureSectionCoverage,
  TEXT_FLOOR,
  type CapturedSectionContent,
} from '../theme/section-coverage.js';
import type { SectionSpec, SectionSpecImage } from '../theme/section-spec.js';

function image(overrides: Partial<SectionSpecImage> = {}): SectionSpecImage {
  return {
    url: 'assets/hero.jpg',
    sourceUrl: 'source/hero.jpg',
    alt: 'Hero',
    kind: 'img',
    width: 1200,
    height: 800,
    ...overrides,
  };
}

function sectionSpec(overrides: Partial<SectionSpec> = {}): SectionSpec {
  return {
    sectionIndex: 0,
    interactionModel: 'static',
    top: 0,
    height: 640,
    headings: [],
    bodyText: [],
    buttonLabels: [],
    images: [],
    icons: [],
    backgroundBrightness: 1,
    backgroundColor: '#ffffff',
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
      containerWidth: 1200,
      padding: '0',
      childLayout: 'stack',
      columnCount: 1,
      gap: '0',
    },
    ...overrides,
  };
}

function captured(text: string[], images: string[] = []): CapturedSectionContent {
  return { text, images };
}

describe('theme section coverage contract', () => {
  describe('captureSectionContent', () => {
    it('collects normalized headings, body text, button labels, and structured button labels', () => {
      const result = captureSectionContent(
        sectionSpec({
          headings: ['  Hero   headline  ', '', 'Hero headline'],
          bodyText: ['Body\tcopy', ' Body copy ', '   '],
          buttonLabels: ['Start now', ' Start   now ', ''],
          buttons: [
            { label: 'Learn more', href: '#learn' },
            { label: ' Learn    more ', href: '#learn-again' },
            { label: '', href: '#empty' },
          ],
        })
      );

      expect(result.text).toEqual([
        'hero headline',
        'hero headline',
        'body copy',
        'body copy',
        'start now',
        'start now',
        'learn more',
        'learn more',
      ]);
    });

    it('captures image effective URLs preferring url and falling back to sourceUrl', () => {
      const result = captureSectionContent(
        sectionSpec({
          images: [
            image({ url: 'assets/generated.jpg', sourceUrl: 'source/original.jpg' }),
            image({ url: '', sourceUrl: 'source/fallback.jpg' }),
          ],
        })
      );

      expect(result.images).toEqual(['assets/generated.jpg', 'source/fallback.jpg']);
    });
  });

  describe('measureSectionCoverage', () => {
    it('reports full text and image coverage as not lost', () => {
      const result = measureSectionCoverage(
        captured(['Hero headline', 'Detailed copy', 'Start now'], ['assets/hero.jpg']),
        [
          '<section>',
          '<h2>Hero headline</h2>',
          '<p>Detailed copy</p>',
          '<a href="/start">Start now</a>',
          '<img src="assets/hero.jpg" alt="Hero">',
          '</section>',
        ].join('')
      );

      expect(result).toEqual({
        textCoverage: 1,
        missingImages: [],
        lost: false,
      });
    });

    it('marks sections lost when missing heading and text coverage falls below the text floor', () => {
      const result = measureSectionCoverage(
        captured(['Hero headline', 'Detailed copy', 'Start now']),
        '<section><p>Detailed copy</p></section>'
      );

      expect(result.textCoverage).toBeCloseTo(1 / 3);
      expect(result.textCoverage).toBeLessThan(TEXT_FLOOR);
      expect(result.lost).toBe(true);
    });

    it('marks sections lost when an image is missing even if text coverage is full', () => {
      const result = measureSectionCoverage(
        captured(['Hero headline', 'Detailed copy'], ['assets/missing.jpg']),
        '<section><h2>Hero headline</h2><p>Detailed copy</p></section>'
      );

      expect(result.textCoverage).toBe(1);
      expect(result.missingImages).toEqual(['assets/missing.jpg']);
      expect(result.lost).toBe(true);
    });

    it('matches rendered text after HTML entity decoding and glyph folding', () => {
      const result = measureSectionCoverage(
        captured(["You're ready - set & launch"]),
        '<section><p>You&#8217;re ready &#8211; set &amp; launch</p></section>'
      );

      expect(result.textCoverage).toBe(1);
      expect(result.missingImages).toEqual([]);
      expect(result.lost).toBe(false);
    });

    it('treats empty captured content as fully covered and not lost', () => {
      const result = measureSectionCoverage(captured([]), '<section></section>');

      expect(result).toEqual({
        textCoverage: 1,
        missingImages: [],
        lost: false,
      });
    });
  });
});
