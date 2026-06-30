import { describe, expect, it } from 'vitest';

import cases from '../__fixtures__/cases.json' with { type: 'json' };
import { convert, convertReport } from '../index.js';

type Fixture = {
  id: string;
  op: 'rawConvert' | 'canonicalize' | 'compose';
  input: string;
  expected: unknown;
};

const fixtures = cases as Fixture[];

function fixture(id: string): Fixture {
  const found = fixtures.find((candidate) => candidate.id === id);
  if (!found) throw new Error(`Missing fixture ${id}`);
  return found;
}

describe('convertReport', () => {
  it('reports clean conversion as success with no fallbacks', async () => {
    const report = await convertReport('<h2>Title</h2><p>Body</p>', { url: '' });

    expect(report.schema).toBe('blocks-engine/convert-report/v1');
    expect(report.status).toBe('success');
    expect(report.blockMarkup).toContain('<!-- wp:heading -->');
    expect(report.blockMarkup).toContain('<!-- wp:paragraph -->');
    expect(report.blockMarkup).not.toContain('<!-- wp:html');
    expect(report.fallbacks).toEqual([]);
    expect(report.metrics.fallbackCount).toBe(0);
    expect(report.metrics.blockCount).toBeGreaterThan(0);
    expect(report.metrics.transformDurationMs).toBeGreaterThanOrEqual(0);
  });

  it('reports surviving core/html as a warning fallback', async () => {
    const input = fixture('raw.sample-semantic-section').input;

    const report = await convertReport(input, { url: 'https://x.test/' });

    expect(report.status).toBe('success_with_warnings');
    expect(report.blockMarkup).toContain('<!-- wp:html');
    expect(report.blockMarkup).toContain('<section><h2>Our process</h2>');
    expect(report.fallbacks).toHaveLength(1);
    expect(report.fallbacks[0]).toEqual(
      expect.objectContaining({
        code: 'unconverted_html',
        severity: 'warning',
      }),
    );
    expect(report.metrics.fallbackCount).toBe(1);
  });

  it('keeps convert as the blockMarkup projection of convertReport', async () => {
    const input = '<h2>Title</h2><p>Body</p>';

    await expect(convert(input, { url: '' })).resolves.toBe(
      (await convertReport(input, { url: '' })).blockMarkup,
    );
  });
});
