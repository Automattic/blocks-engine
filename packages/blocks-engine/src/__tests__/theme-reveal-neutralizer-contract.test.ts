import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';
import { collectSourceAssets } from '../theme/index.js';

function withTempDir<T>(fn: (dir: string) => T): T {
  const dir = mkdtempSync(join(tmpdir(), 'blocks-engine-reveal-neutralizer-'));
  try {
    return fn(dir);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

function sourceCss(html: string, css: string, js = ''): string {
  return withTempDir((dir) => {
    writeFileSync(join(dir, 'style.css'), css, 'utf8');
    if (js) writeFileSync(join(dir, 'main.js'), js, 'utf8');
    const scripts = js ? '<script src="main.js"></script>' : '';
    return collectSourceAssets(dir, [{ relPath: 'index.html', html: `<link rel="stylesheet" href="style.css">${scripts}${html}` }]).css;
  });
}

describe('reveal neutralizer contract', () => {
  it('neutralizes a source element proven hidden by carried CSS and omitted runtime selector evidence', () => {
    const css = sourceCss(
      '<section class="enter">Shown without JS</section>',
      '.enter{opacity:0;transform:translateY(36px)}',
      "document.querySelectorAll('.enter').forEach((element) => element.classList.add('visible'));",
    );

    expect(css).toContain('.enter {\n  opacity: 1 !important;\n  transform: none !important;\n}');
  });

  it('leaves permanently hidden and unrelated reveal-named elements untouched', () => {
    const css = sourceCss(
      '<section class="reveal">Permanent</section><section class="unrelated">Unrelated</section>',
      '.reveal,.unrelated{opacity:0;transform:translateY(36px)}',
      "document.querySelectorAll('.other').forEach(() => {});",
    );

    expect(css).not.toContain('opacity: 1 !important');
    expect(css).toContain('.reveal,.unrelated{opacity:0;transform:translateY(36px)}');
  });

  it('requires source DOM evidence before neutralizing a runtime-selected hidden selector', () => {
    const css = sourceCss(
      '<section class="other">Visible</section>',
      '.enter{opacity:0;transform:translateY(36px)}',
      "document.querySelectorAll('.enter').forEach(() => {});",
    );

    expect(css).not.toContain('opacity: 1 !important');
  });
});
