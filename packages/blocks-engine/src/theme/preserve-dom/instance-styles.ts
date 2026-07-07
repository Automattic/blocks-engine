import { createHash } from 'node:crypto';

/**
 * Canonicalize a CSS declaration string for content-addressed dedup: split on
 * ';', trim each declaration, collapse internal whitespace, normalize the
 * `prop: value` spacing, drop empties, rejoin with ';'. So 'a: 1px ;  b:2px'
 * and 'a:1px;b:2px' produce the same key.
 */
export function normalizeDeclarations(style: string): string {
  const declarations = style
    .split(';')
    .map((decl) => decl.trim().replace(/\s+/g, ' '))
    .filter(Boolean)
    .map((decl) => {
      const i = decl.indexOf(':');
      if (i < 0) return { prop: '', value: '', text: decl };
      const prop = decl.slice(0, i).trim().toLowerCase();
      const value = decl.slice(i + 1).trim();
      return { prop, value, text: `${prop}:${value}` };
    });
  const outOfFlow = declarations.some(
    ({ prop, value }) => prop === 'position' && /^(absolute|fixed)\b/i.test(value),
  );

  return declarations
    .filter(({ prop, value }) => !isRiskyLayoutDeclaration(prop, value, outOfFlow))
    .map(({ text }) => text)
    .join(';');
}

const OUT_OF_FLOW_OFFSET_PROPS = new Set(['inset', 'inset-block', 'inset-inline', 'top', 'right', 'bottom', 'left']);
const BOX_SIZE_PROPS = new Set(['height', 'min-height', 'max-height', 'width', 'min-width', 'max-width']);
const MAX_SAFE_BOX_PX = 1600;
const MAX_SAFE_VIEWPORT_UNIT = 120;

function isRiskyLayoutDeclaration(prop: string, value: string, outOfFlow: boolean): boolean {
  if (!prop) return false;
  if (prop === 'position' && /^(absolute|fixed)\b/i.test(value)) return true;
  if (outOfFlow && OUT_OF_FLOW_OFFSET_PROPS.has(prop)) return true;
  if (BOX_SIZE_PROPS.has(prop) && isOversizedBoxValue(value)) return true;
  return false;
}

function isOversizedBoxValue(value: string): boolean {
  return value
    .split(/\s+/)
    .some((part) => oversizedPx(part) || oversizedViewportUnit(part));
}

function oversizedPx(value: string): boolean {
  const match = /^(-?\d+(?:\.\d+)?)px$/i.exec(value);
  return !!match && Number(match[1]) > MAX_SAFE_BOX_PX;
}

function oversizedViewportUnit(value: string): boolean {
  const match = /^(-?\d+(?:\.\d+)?)(vh|vw|vmin|vmax)$/i.exec(value);
  return !!match && Number(match[1]) > MAX_SAFE_VIEWPORT_UNIT;
}

export class InstanceStyleSheet {
  private readonly rules = new Map<string, string>();

  classFor(style: string | undefined | null): string | null {
    const decls = normalizeDeclarations(style ?? '');
    if (!decls) return null;
    const cls = `lib-i${createHash('sha1').update(decls).digest('hex').slice(0, 10)}`;
    this.rules.set(cls, decls);
    return cls;
  }

  get size(): number {
    return this.rules.size;
  }

  toCss(): string {
    return [...this.rules.entries()]
      .sort((a, b) => (a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : 0))
      .map(([cls, decls]) => `.${cls}{${decls}}`)
      .join('\n');
  }
}
