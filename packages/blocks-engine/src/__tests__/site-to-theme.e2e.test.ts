import { describe, expect, it } from 'vitest';

import { assemble } from '../theme/assemble.js';
import { foundation } from '../theme/foundation.js';
import { reconstruct } from '../theme/reconstruct.js';
import { siteToTheme } from '../theme/site-to-theme.js';
import { lintThemeJson } from '../theme/theme-json-lint.js';
import { writeTheme } from '../theme/write-theme.js';

describe('site-to-theme P0-3 path contracts', () => {
  it('freezes the P0-3 module and e2e test paths', () => {
    expect(foundation).toBeTypeOf('function');
    expect(reconstruct).toBeTypeOf('function');
    expect(assemble).toBeTypeOf('function');
    expect(writeTheme).toBeTypeOf('function');
    expect(siteToTheme).toBeTypeOf('function');
    expect(lintThemeJson).toBeTypeOf('function');
  });
});
