export type InternalLinkMap = Map<string, string>;

export function rewriteMediaUrls(
  input: string,
  mapping: Map<string, string>,
  opts: { onMissing?: (sourceUrl: string) => void } = {},
): string {
  void mapping;
  void opts;
  return input;
}

export function rewriteInternalLinks(
  input: string,
  map: InternalLinkMap,
  opts: { onMissing?: (href: string) => void } = {},
): string {
  void map;
  void opts;
  return input;
}
