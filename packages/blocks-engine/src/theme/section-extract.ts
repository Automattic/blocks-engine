import { BlocksEngineError } from '../errors.js';
import type { SectionSpec } from './section-spec.js';
import type { SitePage } from './types.js';

export function sectionExtract(page: SitePage): SectionSpec[] {
  throw new BlocksEngineError('not implemented', {
    code: 'STAGE_STUB',
    hint: 'Phase 0 skeleton',
  });
}
