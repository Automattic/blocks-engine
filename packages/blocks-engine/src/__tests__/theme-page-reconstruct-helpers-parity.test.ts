import { describe, expect, expectTypeOf, it } from 'vitest';

import * as theme from '../theme/index.js';
import {
  normalizeCopy,
  sanitizePatternHeaderField,
  sanitizeSvgAsset,
  stripChrome,
  type FontFamilyToken,
} from '../theme/index.js';
import type { SectionSpec } from '../theme/section-spec.js';

function section(partial: Partial<SectionSpec>): SectionSpec {
  return {
    sectionIndex: 0,
    interactionModel: 'static',
    top: 0,
    height: 400,
    headings: [],
    bodyText: [],
    buttonLabels: [],
    images: [],
    icons: [],
    backgroundBrightness: 255,
    backgroundColor: 'rgb(255, 255, 255)',
    gradient: null,
    gradientSource: null,
    motionProfile: { motionClass: 'none', signals: [], animatedElements: 0 },
    dividerAbove: null,
    dividerBelow: null,
    layout: {
      containerWidth: 1200,
      padding: '0',
      childLayout: 'stack',
      columnCount: 1,
      gap: '0',
    },
    ...partial,
  };
}

describe('page reconstruct helper public surface', () => {
  it('exports the DLA page-reconstruct helper surface from theme/index', () => {
    expect(theme).toEqual(
      expect.objectContaining({
        normalizeCopy: expect.any(Function),
        sanitizePatternHeaderField: expect.any(Function),
        sanitizeSvgAsset: expect.any(Function),
        stripChrome: expect.any(Function),
      })
    );

    expectTypeOf(normalizeCopy).toEqualTypeOf<(s: string) => string>();
    expectTypeOf(sanitizePatternHeaderField).toEqualTypeOf<(s: string) => string>();
    expectTypeOf(sanitizeSvgAsset).toEqualTypeOf<(svg: string) => string>();
    expectTypeOf(stripChrome).toEqualTypeOf<(sections: SectionSpec[]) => SectionSpec[]>();
    expectTypeOf<FontFamilyToken>().toEqualTypeOf<{ slug: string; family: string }>();
  });

  it('uses the public FontFamilyToken type shape for registered theme font families', () => {
    const token: FontFamilyToken = {
      slug: 'display',
      family: 'Caldera Display, serif',
    };

    expect(token).toEqual({
      slug: 'display',
      family: 'Caldera Display, serif',
    });
  });
});

describe('normalizeCopy DLA parity', () => {
  it('collapses whitespace and strips soft-hyphen and zero-width copy noise', () => {
    expect(normalizeCopy('  foo\u00ad\u200b   bar\n baz ')).toBe('foo bar baz');
    expect(normalizeCopy('\ufeffA\u200c\tB\u200d\r\nC\u00ad')).toBe('A B C');
  });
});

describe('sanitizePatternHeaderField DLA parity', () => {
  it('strips PHP doc-comment and PHP-tag delimiters without rewriting visible text', () => {
    expect(sanitizePatternHeaderField('*/ evil(); /*')).toBe('evil();');
    expect(sanitizePatternHeaderField('a <?php b ?> c')).toBe('a php b  c');
    expect(sanitizePatternHeaderField('line1\r\nline2\nline3')).toBe('line1 line2 line3');
    expect(sanitizePatternHeaderField('demo-replica/page-x')).toBe('demo-replica/page-x');
    expect(sanitizePatternHeaderField('Page \u2014 X')).toBe('Page \u2014 X');
  });
});

describe('stripChrome DLA parity', () => {
  it('drops leading header chrome and trailing footer/nav chrome while preserving middle content', () => {
    const out = stripChrome([
      section({
        sectionIndex: 0,
        interactionModel: 'static',
        height: 116,
        headings: ['Acme Co 555-0142'],
        bodyText: ['PRODUCTS', 'GALLERY', 'JOB OPPORTUNITIES', 'ABOUT US'],
      }),
      section({
        sectionIndex: 1,
        height: 760,
        headings: ['Premium Hardwood'],
        bodyText: ['A content section that must remain in the reconstructed page body.'],
      }),
      section({
        sectionIndex: 2,
        backgroundColor: 'rgb(47, 56, 78)',
        headings: ['100 Night Happiness Guarantee'],
        bodyText: ['This dark middle band is page content, not sitewide chrome.'],
      }),
      section({
        sectionIndex: 3,
        interactionModel: 'footer',
        bodyText: ['PRODUCTS', 'GALLERY', 'CALL US', '\u00a9 2026 Website by Acme Studio'],
      }),
      section({
        sectionIndex: 4,
        interactionModel: 'nav',
        bodyText: ['Privacy Policy', 'Terms of Service'],
      }),
    ]);

    expect(out.map((s) => s.sectionIndex)).toEqual([1, 2]);
    expect(out.map((s) => s.headings)).toEqual([
      ['Premium Hardwood'],
      ['100 Night Happiness Guarantee'],
    ]);
  });
});

describe('sanitizeSvgAsset DLA parity', () => {
  it('removes active SVG content and keeps inert icon markup as concrete goldens', () => {
    expect(
      sanitizeSvgAsset(
        '<svg><script>alert(1)</script><foreignObject><p>x</p></foreignObject><path d="M3 9h4"/></svg>'
      )
    ).toBe('<svg xmlns="http://www.w3.org/2000/svg"><path d="M3 9h4"/></svg>');

    expect(
      sanitizeSvgAsset(
        '<svg><set attributeName="onload" to="alert(1)"/><animate attributeName="onbegin" to="x"></animate><path onload="evil()" onclick=\'evil2()\' d="M3 9h4"/></svg>'
      )
    ).toBe('<svg xmlns="http://www.w3.org/2000/svg"><path d="M3 9h4"/></svg>');

    expect(
      sanitizeSvgAsset(
        '<svg><image href="https://evil.example/x"/><a xlink:href="javascript:alert(1)">x</a><use href="#local-glyph"/><image href="data:image/png;base64,abc"/></svg>'
      )
    ).toBe(
      '<svg xmlns="http://www.w3.org/2000/svg"><image/><a xlink:href="alert(1)">x</a><use href="#local-glyph"/><image href="data:image/png;base64,abc"/></svg>'
    );

    expect(
      sanitizeSvgAsset('<svg xmlns="http://www.w3.org/2000/svg"><path d="M3 9h4"/></svg>')
    ).toBe('<svg xmlns="http://www.w3.org/2000/svg"><path d="M3 9h4"/></svg>');
    expect(
      sanitizeSvgAsset('<svg data-label="a > b" xmlns="http://www.w3.org/2000/svg"><path/></svg>')
    ).toBe('<svg data-label="a > b" xmlns="http://www.w3.org/2000/svg"><path/></svg>');
    expect(sanitizeSvgAsset('<svg xmlns=""><path/></svg>')).toBe(
      '<svg xmlns="http://www.w3.org/2000/svg"><path/></svg>'
    );
    expect(sanitizeSvgAsset('<svg xmlns="https://example.test/svg"><path/></svg>')).toBe(
      '<svg xmlns="http://www.w3.org/2000/svg"><path/></svg>'
    );
    expect(sanitizeSvgAsset('<svg XMLNS="http://www.w3.org/2000/svg"><path/></svg>')).toBe(
      '<svg xmlns="http://www.w3.org/2000/svg" XMLNS="http://www.w3.org/2000/svg"><path/></svg>'
    );
    expect(
      sanitizeSvgAsset('<svg xmlns="http://www.w3.org/2000/svg" xmlns="https://example.test/svg"><path/></svg>')
    ).toBe('<svg xmlns="http://www.w3.org/2000/svg"><path/></svg>');
    expect(
      sanitizeSvgAsset('<svg xmlns="" xmlns="https://example.test/svg"><path/></svg>')
    ).toBe('<svg xmlns="http://www.w3.org/2000/svg"><path/></svg>');
    expect(
      sanitizeSvgAsset('<?xml version="1.0" encoding="UTF-8"?>\n<!-- icon -->\n<svg viewBox="0 0 1 1"><path/></svg>')
    ).toBe(
      '<?xml version="1.0" encoding="UTF-8"?>\n<!-- icon -->\n<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path/></svg>'
    );
    expect(
      sanitizeSvgAsset('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><path/></svg>')
    ).toBe('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><path/></svg>');
    expect(
      sanitizeSvgAsset('<?source-generator value="a > b"?>\n<svg viewBox="0 0 1 1"><path/></svg>')
    ).toBe(
      '<?source-generator value="a > b"?>\n<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path/></svg>'
    );
    expect(
      sanitizeSvgAsset('<!DOCTYPE svg [<!ENTITY label "a > b">]>\n<svg viewBox="0 0 1 1"><path/></svg>')
    ).toBe(
      '<!DOCTYPE svg [<!ENTITY label "a > b">]>\n<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path/></svg>'
    );
    expect(
      sanitizeSvgAsset('<!DOCTYPE svg [<!-- ]> --><?probe value="]>"?><!ENTITY label "a > b">]>\n<svg viewBox="0 0 1 1"><path/></svg>')
    ).toBe(
      '<!DOCTYPE svg [<!-- ]> --><?probe value="]>"?><!ENTITY label "a > b">]>\n<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path/></svg>'
    );
    expect(
      sanitizeSvgAsset('<svg xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="#glyph"/></svg>')
    ).toBe(
      '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="#glyph"/></svg>'
    );
    expect(sanitizeSvgAsset('<div data-asset="unchanged">bytes</div>')).toBe(
      '<div data-asset="unchanged">bytes</div>'
    );
  });
});
