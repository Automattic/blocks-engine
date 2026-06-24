export interface ParsedFontFace {
  family: string;
  src: string;
  format?: string;
  weight?: string;
  style?: string;
}

export function parseFontFaces(...cssOrHtml: string[]): ParsedFontFace[] {
  void cssOrHtml;
  return stageStub('parseFontFaces');
}

export function stripUnusedFontFaces(
  css: string,
  usageText: string
): { css: string; removed: number } {
  void css;
  void usageText;
  return stageStub('stripUnusedFontFaces');
}

function stageStub(name: string): never {
  throw new Error(`STAGE_STUB:${name}`);
}
