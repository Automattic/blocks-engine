import { describe, expect, it } from 'vitest';

import { compose } from '../compose';

const ctx = { url: 'https://x.test/' };

describe('compose default htmlFallback injection guard', () => {
  it('throws when sanitized fallback html still contains a quote-adjacent event handler', () => {
    expect(() => compose('<img src="x"onerror="alert(1)">', ctx, {})).toThrow(
      /html-fallback sanitization left injection vectors|inline event handler/,
    );
  });

  it('keeps clean unconvertible html in the coverage island', () => {
    const out = compose('<section><h2>Clean</h2><p>ok</p></section>', ctx, {});

    expect(out).toContain('metadata":{"name":"lib-coverage-island"}');
    expect(out).toContain('<section><h2>Clean</h2><p>ok</p></section>');
    expect(out).toContain('<!-- /wp:html -->');
  });
});
