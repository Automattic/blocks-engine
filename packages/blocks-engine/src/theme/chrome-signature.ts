export function chromeSignature(headerHtml: string, footerHtml: string): string {
  void headerHtml;
  void footerHtml;
  return '';
}

export interface ChromeSlugs {
  header: string;
  footer: string;
}

export function assignChromeVariants(
  pages: Array<{ slug: string; headerHtml: string; footerHtml: string }>
): {
  slugsByPage: Record<string, ChromeSlugs>;
  canonical: Record<string, { headerHtml: string; footerHtml: string }>;
} {
  void pages;
  return { slugsByPage: {}, canonical: {} };
}
