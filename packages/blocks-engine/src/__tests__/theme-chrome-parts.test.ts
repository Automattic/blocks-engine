import { describe, expect, expectTypeOf, it } from 'vitest';

import {
  buildChromePart,
  chrome,
  type ChromeResult,
  type ChromeSlugs,
  type StageCtx,
} from '../theme/index.js';
import type { WorkerPool } from '../pool/types.js';

describe('chrome parts contract', () => {
  it('freezes the public signatures exported from the theme entrypoint', () => {
    expect(typeof buildChromePart).toBe('function');
    expect(typeof chrome).toBe('function');

    expectTypeOf(buildChromePart).toEqualTypeOf<
      (html: string, ctx: StageCtx, pool: WorkerPool) => Promise<string>
    >();
    expectTypeOf(chrome).toEqualTypeOf<
      (ctx: StageCtx, pool: WorkerPool) => Promise<ChromeResult>
    >();
    expectTypeOf<ChromeResult>().toEqualTypeOf<{
      parts: Record<string, string>;
      slugsByPage: Record<string, ChromeSlugs>;
      mainHtmlByPage: Record<string, string>;
      warnings: string[];
    }>();
  });
});
