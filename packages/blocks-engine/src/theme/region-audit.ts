export type SourceLandmarkRole =
  | 'main'
  | 'nav'
  | 'header'
  | 'footer'
  | 'section'
  | 'article'
  | 'aside'
  | 'complementary';

export interface SourceLandmark {
  role: SourceLandmarkRole;
  tag: string;
  selector: string;
  textLength: number;
  mediaCount: number;
  linkCount?: number;
}

export type RegionAssignmentKind =
  | 'page_body_section'
  | 'header_part'
  | 'footer_part'
  | 'non_actionable'
  | 'unassigned';

export interface RegionAssignment {
  landmark: SourceLandmark;
  kind: RegionAssignmentKind;
}

export interface PlacedRegion {
  kind: 'page_body_section' | 'header_part' | 'footer_part';
  selector?: string;
  role?: 'header' | 'nav' | 'footer' | 'aside' | 'complementary';
}

export interface RegionSelectionReport {
  page: string;
  entryUrl: string;
  assignments: RegionAssignment[];
  unassignedRegions: SourceLandmark[];
  counts: {
    sourceLandmarks: Record<string, number>;
    assigned: number;
    unassigned: number;
    nonActionable: number;
  };
}

export function reconcileRegions(
  _census: SourceLandmark[],
  _placed: PlacedRegion[],
  _page = '',
  _entryUrl = ''
): RegionSelectionReport {
  throw new Error('region audit reconciliation is not implemented');
}
