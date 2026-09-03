export function buildRevealNeutralizerCss(
  sourceCss: string,
  sourceHtml: readonly string[],
  sourceJs: string,
): string {
  const selectors = hiddenRuntimeSelectors(sourceCss, sourceHtml, sourceJs);
  if (selectors.length === 0) return '';

  return `/* wp-compat: reveal gates need JS that is not carried yet, so render them visible */
${selectors.join(',\n')} {
  opacity: 1 !important;
  transform: none !important;
}
`;
}

function hiddenRuntimeSelectors(sourceCss: string, sourceHtml: readonly string[], sourceJs: string): string[] {
  if (!sourceJs.trim()) return [];

  const runtimeSelectors = new Set<string>();
  for (const match of sourceJs.matchAll(/querySelector(?:All)?\(\s*(['"])(.*?)\1\s*\)/gs)) {
    runtimeSelectors.add(match[2].trim());
  }

  const hidden = new Set<string>();
  for (const match of sourceCss.matchAll(/([^{}@][^{}]*)\{([^{}]*)\}/g)) {
    if (!/(?:^|;)\s*opacity\s*:\s*0(?:\s*!important)?\s*(?:;|$)/i.test(match[2])) continue;
    for (const selector of splitSelectorList(match[1])) {
      if (runtimeSelectors.has(selector) && selectorMatchesSourceHtml(selector, sourceHtml)) {
        hidden.add(selector);
      }
    }
  }

  return [...hidden];
}

function splitSelectorList(selectorList: string): string[] {
  return selectorList.split(',').map((selector) => selector.trim()).filter(Boolean);
}

function selectorMatchesSourceHtml(selector: string, sourceHtml: readonly string[]): boolean {
  const classMatch = /^\.([A-Za-z][\w-]*)$/.exec(selector);
  if (classMatch) {
    const className = classMatch[1];
    return sourceHtml.some((html) => new RegExp(`\\bclass\\s*=\\s*(["'])[^"']*\\b${className}\\b[^"']*\\1`, 'i').test(html));
  }

  const dataMatch = /^\[data-([\w-]+)(?:=[^\]]+)?\]$/.exec(selector);
  if (dataMatch) {
    return sourceHtml.some((html) => new RegExp(`\\bdata-${dataMatch[1]}(?:\\s|=|>)`, 'i').test(html));
  }

  return false;
}
