import { BlocksEngineError } from '../errors.js';
import type { SiteModel } from './types.js';

export function slugFromRelPath(relPath: string): string {
  throw new BlocksEngineError('not implemented', {
    code: 'STAGE_STUB',
    hint: 'Phase 0 skeleton',
  });
}

export function ingest(srcDir: string): SiteModel {
  throw new BlocksEngineError('not implemented', {
    code: 'STAGE_STUB',
    hint: 'Phase 0 skeleton',
  });
}
