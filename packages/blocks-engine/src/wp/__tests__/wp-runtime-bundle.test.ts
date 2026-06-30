import { describe, it, expect, beforeAll } from 'vitest';
import { existsSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
import { installDomGlobals } from '../dom-globals.js';

const require = createRequire(import.meta.url);
const bundlePath = fileURLToPath(new URL('../../../dist/wp-runtime.cjs', import.meta.url));

// Install real jsdom globals before requiring the bundle. The bundled WP
// runtime touches window/document at module-init. Uses the shared util from
// dom-globals.ts so this setup stays in sync with bootstrap.ts automatically.
function setupDomGlobals(): void {
  const { window } = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    url: 'http://localhost',
    pretendToBeVisual: true,
  });
  installDomGlobals(window as unknown as typeof globalThis);
}

describe('wp-runtime bundle', () => {
  let m: Record<string, (...args: unknown[]) => unknown>;
  beforeAll(() => {
    if (!existsSync(bundlePath)) throw new Error('run `pnpm build` first');
    setupDomGlobals();
    m = require(bundlePath);
  });

  it('exports the WordPress symbols the engine calls', () => {
    for (const name of ['registerCoreBlocks', 'rawHandler', 'serialize', 'parse', 'createBlock', 'getBlockAttributes', 'parseGrammar']) {
      expect(typeof m[name], name).toBe('function');
    }
  });

  it('converts HTML to core blocks with no wp:html residue', () => {
    (m.registerCoreBlocks as () => void)();
    const html = '<h2>Hello</h2><p>x <strong>b</strong></p><ul><li>one</li></ul><blockquote><p>q</p></blockquote><table><tbody><tr><td>a</td></tr></tbody></table>';
    const out = (m.serialize as (b: unknown) => string)((m.rawHandler as (o: { HTML: string }) => unknown)({ HTML: html }));
    expect(out.match(/<!-- wp:html/g) ?? []).toHaveLength(0);
    expect((out.match(/<!-- wp:/g) ?? []).length).toBeGreaterThanOrEqual(6);
  });
});
