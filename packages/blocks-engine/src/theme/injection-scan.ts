export function scanForInjection(markup: string): string[] {
  const violations: string[] = [];
  const SANCTIONED_PHP = /<\?php\s+echo\s+esc_url\(\s*get_theme_file_uri\(\s*'[^']+'\s*\)\s*\);\s*\?>/gi;
  const SANCTIONED_HEADER = /<\?php\s*\/\*\*(?:(?!\*\/)[\s\S])*\*\/\s*\?>/g;
  const residualPhp = markup.replace(SANCTIONED_PHP, '').replace(SANCTIONED_HEADER, '');
  if (/<\?/.test(residualPhp)) {
    violations.push('raw PHP tag in markup (only the pattern-header doc-comment and esc_url(get_theme_file_uri()) are allowed)');
  }
  if (/<\s*script/i.test(markup)) {
    violations.push('raw <script> tag in markup (not allowed)');
  }
  if (/[\s"'/]on[a-z]+\s*=/i.test(markup)) {
    violations.push('inline event handler attribute (on*=) in markup (not allowed)');
  }
  return violations;
}
