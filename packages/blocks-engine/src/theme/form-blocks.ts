import type { SectionSpecForm, SectionSpecFormField } from './section-spec.js';

export interface FormBlocksResult {
  /** jetpack/contact-form block markup (wrapper + field blocks + submit button). */
  markup: string;
  /** Captured fields that have no in-scope Jetpack equivalent (file, hidden). */
  skipped: Array<{ kind: SectionSpecFormField['kind']; label: string }>;
}

/** Field kinds the mapper deliberately does NOT emit (see grammar notes). */
export const SKIPPED_FIELD_KINDS: ReadonlySet<SectionSpecFormField['kind']> = new Set(['file', 'hidden']);

export function formToBlocks(form: SectionSpecForm): FormBlocksResult {
  void form;
  throw new Error('STAGE_STUB formToBlocks');
}
