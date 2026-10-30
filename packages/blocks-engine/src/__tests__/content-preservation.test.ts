import { afterAll, describe, expect, it } from 'vitest';

import cases from '../__fixtures__/cases.json' with { type: 'json' };
import { convertReport } from '../index.js';
import { createWorker } from '../pool/pool.js';
import { canonicalize } from '../wp/canonicalize';

type Fixture = { id: string; op: string; input: string };

// What a reader of the page sees: its words, and the media it loads (a
// source may become an embed URL, so any occurrence of it counts).
function words(html: string): string[] {
  return html
    .replace(/<!--[\s\S]*?-->/gu, ' ')
    .replace(/<[^>]+>/gu, ' ')
    .split(/\s+/u)
    .filter(Boolean);
}

function missing(input: string, output: string): string[] {
  const left = words(output);
  const lostWords = words(input).filter((word) => {
    const at = left.indexOf(word);
    if (at === -1) return true;
    left.splice(at, 1);
    return false;
  });
  const lostSources = Array.from(input.matchAll(/\ssrc="([^"]+)"/gu), (match) => match[1]).filter(
    (source) => !output.includes(source),
  );
  return [...lostWords, ...lostSources];
}

const pool = createWorker({ size: 1 });
afterAll(() => pool.stop());

const card =
  '<div class="paw-card"><img src="https://example.com/a.png" alt="A"><h3>Coco Rope Tug</h3><p>Braided cotton rope.</p></div>';

const inputs: Array<[string, string]> = [
  ['a card', card],
  ['a card with a price and a link', '<div class="paw-card"><h3>Tug</h3><p class="price">$12</p><a class="btn" href="/x">Add</a></div>'],
  ['cards in a grid', `<div class="card-grid">${card}${card}</div>`],
  ['a callout', '<div class="callout"><p>Heads up.</p></div>'],
  ['an accordion item', '<div class="faq-item"><h3>Shipping?</h3><div><p>Two days.</p></div></div>'],
  ...(cases as Fixture[])
    .filter((fixture) => fixture.op === 'compose')
    .map((fixture): [string, string] => [fixture.id, fixture.input]),
];

describe('conversion keeps what the page shows', () => {
  it.each(inputs)('%s', async (_name, html) => {
    const report = await convertReport(html, { url: 'https://x.test/' }, { pool });

    expect(missing(html, report.blockMarkup)).toEqual([]);
    // Converted blocks hold their content themselves; none rely on the
    // canonicalize guard keeping an invalid block.
    expect(report.diagnostics.map((finding) => finding.code)).not.toContain('invalid_block_preserved');
  });

  it('keeps a card class the site styles and invents no block style', async () => {
    const report = await convertReport(card, { url: 'https://x.test/' }, { pool });

    expect(report.blockMarkup).toContain('paw-card');
    expect(report.blockMarkup).not.toContain('is-style-');
    expect(report.status).toBe('success_with_warnings');
  });
});

describe('canonicalize keeps an invalid block whose re-save drops content', () => {
  const lossy: Array<[string, string, string[]]> = [
    [
      'a group holding raw HTML',
      '<!-- wp:group -->\n<div class="wp-block-group"><p>Kept words</p><img src="https://example.com/k.png" alt=""></div>\n<!-- /wp:group -->',
      ['core/group'],
    ],
    [
      'the same group inside columns',
      '<!-- wp:columns -->\n<div class="wp-block-columns"><!-- wp:column -->\n<div class="wp-block-column"><!-- wp:group -->\n<div class="wp-block-group"><p>Kept words</p></div>\n<!-- /wp:group --></div>\n<!-- /wp:column --></div>\n<!-- /wp:columns -->',
      ['core/group'],
    ],
  ];

  it.each(lossy)('%s', (_name, markup, preserved) => {
    const result = canonicalize(markup);

    expect(missing(markup, result.html)).toEqual([]);
    expect(result.preservedBlocks).toEqual(preserved);
  });

  it('still fixes an invalid block whose re-save keeps its content', () => {
    const markup = '<!-- wp:paragraph {"className":"lead"} -->\n<p>Hello there</p>\n<!-- /wp:paragraph -->';
    const result = canonicalize(markup);

    expect(result.html).toContain('<p class="lead">Hello there</p>');
    expect(result.preservedBlocks).toEqual([]);
  });

  it('reports a kept block as a warning', async () => {
    const markup = lossy[0][1];
    const report = await convertReport(markup, { url: '' }, { pool });

    expect(report.status).toBe('success_with_warnings');
    expect(report.diagnostics).toContainEqual(
      expect.objectContaining({ code: 'invalid_block_preserved', severity: 'warning', selector: 'core/group' }),
    );
  });
});
