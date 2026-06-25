export interface PaletteToken {
  slug: string;
  hex: string;
}

export function nearestToken(hex: string, tokens: PaletteToken[]): string | null {
  void hex;
  void tokens;
  throw new Error('native-color nearestToken is not implemented');
}

export function brightness(hex: string): number {
  void hex;
  throw new Error('native-color brightness is not implemented');
}
