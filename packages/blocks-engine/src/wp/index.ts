import { createRequire } from 'node:module';

export type ConvertContext = {
  url?: string;
  mediaMap?: Record<string, string>;
};

export type CanonicalizeResult = {
  html: string;
  changed: boolean;
  fixedIssues: string[];
};

export type RawConvertResult = {
  html: string | null;
  wpHtmlResidue: number;
};

type RuntimeWpEntry = {
  bootstrap: () => void;
  canonicalize: (markup: string) => CanonicalizeResult;
  rawConvert: (html: string) => RawConvertResult;
  convert: (html: string, ctx?: ConvertContext) => string;
};

const require = createRequire(import.meta.url);

function loadRuntimeEntry(): RuntimeWpEntry {
  const { require: tsxRequire } = require('tsx/cjs/api') as {
    require: (id: string, parentURL: string) => unknown;
  };
  return tsxRequire('./internal-index.ts', import.meta.url) as RuntimeWpEntry;
}

const entry =
  process.env.VITEST_WORKER_ID !== undefined
    ? ((await import(`./internal-index.${'ts'}`)) as RuntimeWpEntry)
    : loadRuntimeEntry();

export const bootstrap = entry.bootstrap;
export const canonicalize = entry.canonicalize;
export const rawConvert = entry.rawConvert;
export const convert = entry.convert;
