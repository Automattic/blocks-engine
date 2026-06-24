import { describe, expect, it } from 'vitest';
import { parseFontFaces, stripUnusedFontFaces } from '../theme/font-faces.js';

describe('parseFontFaces', () => {
  it('parses family, preferred source, format, normalized weight, and style', () => {
    const faces = parseFontFaces(`
      @font-face {
        font-family: "Acme Display";
        src: url("/fonts/acme.woff") format("woff"),
          url("/fonts/acme.woff2?cache=1") format("woff2");
        font-weight: bold;
        font-style: oblique 12deg;
      }
    `);

    expect(faces).toEqual([
      {
        family: 'Acme Display',
        src: '/fonts/acme.woff2?cache=1',
        format: 'woff2',
        weight: '700',
        style: 'oblique',
      },
    ]);
  });

  it('filters generic CSS font families', () => {
    const faces = parseFontFaces(`
      @font-face {
        font-family: serif;
        src: url("/fonts/serif.woff2") format("woff2");
      }
      @font-face {
        font-family: system-ui;
        src: url("/fonts/system.woff2") format("woff2");
      }
    `);

    expect(faces).toEqual([]);
  });

  it('keeps remote gstatic and typekit faces for later self-hosting', () => {
    const faces = parseFontFaces(`
      @font-face {
        font-family: "Roboto";
        src: url("https://fonts.gstatic.com/s/roboto/v30/roboto.woff2") format("woff2");
        font-weight: 400;
      }
      @font-face {
        font-family: "Adobe Face";
        src: url("https://use.typekit.net/abc123.woff") format("woff");
        font-style: italic;
      }
    `);

    expect(faces.map((face) => face.src)).toEqual([
      'https://fonts.gstatic.com/s/roboto/v30/roboto.woff2',
      'https://use.typekit.net/abc123.woff',
    ]);
  });

  it('deduplicates identical parsed faces', () => {
    const block = `
      @font-face {
        font-family: "Acme";
        src: url("/fonts/acme.woff2") format("woff2");
        font-weight: normal;
      }
    `;

    expect(parseFontFaces(block, block)).toHaveLength(1);
  });
});

describe('stripUnusedFontFaces', () => {
  it('removes unused font-face blocks while keeping used blocks', () => {
    const usedFace = `@font-face{font-family:'Libre Baskerville';src:url(/fonts/libre.woff2) format("woff2")}`;
    const unusedFace = `@font-face{font-family:'Unused Sans';src:url(/fonts/unused.woff2) format("woff2")}`;
    const usage = `.title{font-family:"Libre Baskerville",serif}`;

    const result = stripUnusedFontFaces(`${usedFace}\n${unusedFace}\n${usage}`, usage);

    expect(result.removed).toBe(1);
    expect(result.css).toContain("font-family:'Libre Baskerville'");
    expect(result.css).not.toContain('Unused Sans');
    expect(result.css).toContain(usage);
  });
});
