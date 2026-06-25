import type { FontFamilyToken } from './page-reconstruct-helpers.js';

export function familyMatches(computed: string, token: FontFamilyToken): boolean {
  void computed;
  void token;
  throw new Error('native-fonts familyMatches is not implemented');
}

export function nearestFamily(computed: string | undefined, tokens: FontFamilyToken[]): string | null {
  void computed;
  void tokens;
  throw new Error('native-fonts nearestFamily is not implemented');
}
