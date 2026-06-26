export interface SelectorParts {
  tag: string;
  id: string | null;
  classes: string[];
  nthOfType: number;
}

export function buildSelector(_parts: SelectorParts): string {
  throw new Error('region audit selector builder is not implemented');
}
