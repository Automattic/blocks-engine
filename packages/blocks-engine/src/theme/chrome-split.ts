export interface RegionSplit {
  headerHtml: string;
  mainHtml: string;
  footerHtml: string;
}

export function splitPageChrome(bodyHtml: string): RegionSplit {
  return { headerHtml: '', mainHtml: bodyHtml, footerHtml: '' };
}
