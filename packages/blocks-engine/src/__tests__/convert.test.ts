import { describe, expect, it } from 'vitest';

import * as defaultEntry from '../index';
import cases from '../__fixtures__/cases.json' with { type: 'json' };
import { canonicalize } from '../wp/canonicalize';
import { convert } from '../wp';

type Fixture = {
  id: string;
  op: 'rawConvert' | 'canonicalize' | 'compose';
  input: string;
  expected: unknown;
};

const fixtures = cases as Fixture[];
const fixture = (id: string) => {
  const found = fixtures.find((candidate) => candidate.id === id);
  if (!found) throw new Error(`Missing fixture ${id}`);
  return found;
};

describe('convert', () => {
  it('composes the original source when rawConvert leaves html residue, then canonicalizes', () => {
    const composeFixture = fixture('compose.sample-callout-div') as Fixture & { expected: string };
    expect(convert(composeFixture.input, { url: 'https://x.test/' })).toBe(
      canonicalize(composeFixture.expected).html,
    );
  });

  it('keeps clean rawConvert output before canonicalizing', () => {
    const rawFixture = fixture('raw.smoke-heading-paragraph-table') as Fixture & {
      expected: { html: string; wpHtmlResidue: number };
    };
    expect(convert(rawFixture.input, { url: 'https://x.test/' })).toBe(
      canonicalize(rawFixture.expected.html).html,
    );
  });
});

describe('default entry', () => {
  it('exports the React-free public surface', () => {
    expect(typeof defaultEntry.compose).toBe('function');
    expect(typeof defaultEntry.createWorker).toBe('function');
    expect(typeof defaultEntry.isRawConvertible).toBe('function');
    expect(typeof defaultEntry.escapeHtmlText).toBe('function');
    expect(typeof defaultEntry.escapeHtmlAttr).toBe('function');
    expect(typeof defaultEntry.buildEmbedBlock).toBe('function');
    expect(typeof defaultEntry.guessEmbedProvider).toBe('function');
    expect(typeof defaultEntry.sanitize).toBe('function');
    expect(typeof defaultEntry.blockMarkupRoundtrips).toBe('function');
    expect(typeof defaultEntry.verifyComposedOutput).toBe('function');
    expect(typeof defaultEntry.heuristicBlocks).toBe('function');
  });
});
