export function themeAssetUrl(themeSlug: string, themeRel: string): string {
  return `/wp-content/themes/${themeSlug}/${themeRel.replace(/^\/+/, '')}`;
}
