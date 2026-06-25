export type SectionRole = 'body' | 'header' | 'nav' | 'footer';

export type LayoutWrapperRailPosition = 'beforeMain' | 'afterMain';

export interface LayoutRailWrapperMetadata {
  layoutWrapperTag: string;
  layoutWrapperClasses: string[];
  layoutWrapperRailPosition: LayoutWrapperRailPosition;
}

export interface Section {
  /** Stable, deterministic id (existing id/class, heading slug, or content hash). */
  id: string;
  role: SectionRole;
  /** Chrome inferred structurally rather than from a body-direct landmark. */
  chromeSource?: 'layout-rail';
  /** outerHTML of the section element. */
  html: string;
  /** The source element's class list, in order. */
  classes?: string[];
  layoutWrapperTag?: string;
  layoutWrapperClasses?: string[];
  layoutWrapperRailPosition?: LayoutWrapperRailPosition;
}

export function segmentPage(html: string): Section[] {
  void html;
  throw new Error('STAGE_STUB: segmentPage');
}
