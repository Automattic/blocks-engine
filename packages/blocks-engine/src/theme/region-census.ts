import type { SourceLandmark } from './region-audit.js';

export function selectorForHtmlRoot(_html: string): string | undefined {
  throw new Error('region audit census is not implemented');
}

export function landmarkRoleForHtmlRoot(_html: string): SourceLandmark['role'] | undefined {
  throw new Error('region audit census is not implemented');
}

export function extractSourceLandmarksFromHtml(_html: string): SourceLandmark[] {
  throw new Error('region audit census is not implemented');
}
