export const HOIST_MIN_INSTANCES = 3;

export interface HoistedVariation {
  slug: string;
  title: string;
  blockTypes: string[];
  styles: Record<string, unknown>;
  count: number;
}

export interface HoistPage {
  slug: string;
  markup: string;
}

export interface HoistResult {
  pages: HoistPage[];
  variations: HoistedVariation[];
}

export function hoistVariations(
  pagesIn: HoistPage[],
  _opts: { minInstances?: number } = {}
): HoistResult {
  return { pages: pagesIn.map(p => ({ ...p })), variations: [] };
}

export function applyHoistSwaps(markup: string, _variations: HoistedVariation[]): string {
  return markup;
}
