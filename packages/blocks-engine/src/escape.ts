// src/lib/html-escape.ts
//
// The single home for HTML entity escaping. Three escalating variants: pick by
// context, not convenience.

/** Escape &, <, >: the minimal set for HTML text-node content. */
export function escapeHtmlText(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** Escape &, <, >, ": text plus double-quoted attribute values. */
export function escapeHtmlAttr(s: string): string {
  return escapeHtmlText(s).replace(/"/g, '&quot;');
}

/** Escape &, <, >, ", ': the full set, safe in any HTML context. */
export function escapeHtml(s: string): string {
  return escapeHtmlAttr(s).replace(/'/g, '&#039;');
}
