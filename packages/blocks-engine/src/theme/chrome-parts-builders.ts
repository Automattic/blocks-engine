import * as cheerio from 'cheerio';
import type { Element } from 'domhandler';

export interface NavLink {
  fromSlug: string;
  toSlug: string;
  label: string;
  inNav?: boolean;
}

export interface StickyBehavior {
  kind: 'sticky';
  toggleClass: string;
  offset: number;
}

export interface HeaderPartOpts {
  plain?: boolean;
  sticky?: StickyBehavior;
}

export interface ChromePartSection {
  id: string;
  role: 'body' | 'header' | 'nav' | 'footer';
  chromeSource?: 'layout-rail';
  html: string;
  classes?: string[];
}

export type ChromePartConverter = (html: string) => string | Promise<string>;

export interface CarriedHeaderPartOpts {
  pageSlugs?: string[];
  sticky?: StickyBehavior;
  labelToUrl?: (label: string, sourceHref: string) => string | undefined;
  convertPart?: ChromePartConverter;
}

export interface FooterPartOpts {
  pageSlugs?: string[];
  bgToken?: string;
  textToken?: string;
  convertPart?: ChromePartConverter;
}

export interface ChromeMount {
  id: string;
  classes: string[];
}

export interface ChromeMounts {
  header?: ChromeMount;
  footer?: ChromeMount;
}

export function buildHeaderPart(
  siteTitle: string,
  nav: NavLink[],
  pageSlugs: string[],
  opts?: HeaderPartOpts
): string {
  void siteTitle;
  void nav;
  void pageSlugs;
  void opts;
  throw new Error('STAGE_STUB: buildHeaderPart');
}

export async function buildCarriedHeaderPart(
  header: ChromePartSection,
  opts?: CarriedHeaderPartOpts
): Promise<string> {
  void header;
  void opts;
  throw new Error('STAGE_STUB: buildCarriedHeaderPart');
}

export async function buildFooterPart(
  footer: ChromePartSection | null,
  siteTitle: string,
  opts?: FooterPartOpts
): Promise<string> {
  void footer;
  void siteTitle;
  void opts;
  throw new Error('STAGE_STUB: buildFooterPart');
}

export function findChromeMounts(html: string): ChromeMounts {
  void cheerio;
  void html;
  return {};
}

export function mountPartMarkup(mount: ChromeMount, sticky?: StickyBehavior): string {
  void mount;
  void sticky;
  throw new Error('STAGE_STUB: mountPartMarkup');
}

export type { Element as ChromePartElement };
