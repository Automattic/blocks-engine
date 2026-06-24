export interface ThemeJsonLintResult {
  ok: boolean;
  errors: string[];
}

export function lintThemeJson(theme: Record<string, unknown>): ThemeJsonLintResult {
  void theme;
  return { ok: false, errors: ['not implemented'] };
}
