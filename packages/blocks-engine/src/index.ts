import { createRequire } from 'node:module';

export interface ConversionContext {
  url: string;
  mediaMap?: Record<string, string>;
}

export type Converter = (
  html: string,
  ctx: ConversionContext,
) => string | null;

export interface RecipeRule {
  match: string;
  block: string;
  attrs?: Record<string, unknown>;
  inner?: 'innerHtml' | 'text' | 'images' | 'drop';
}

export type HtmlFallback = string | ((html: string) => string);

export interface VerifyResult {
  valid: boolean;
  hallucinated: string[];
}

export interface HeuristicResult {
  handled: boolean;
  blocks?: string;
  reason?: string;
}

export type RoundtripResult = { ok: true } | { ok: false; reason: string };

export type PoolEvent = {
  type:
    | 'child-spawn'
    | 'child-crash'
    | 're-route'
    | 'recycle'
    | 'sentinel'
    | 'pool-degraded';
  childId?: number;
  count?: number;
};

export interface RawConvertResult {
  html: string | null;
  wpHtmlResidue: number;
}

export interface FixResult {
  html: string;
  changed: boolean;
  fixedIssues: string[];
}

export interface WorkerPoolOptions {
  size?: number;
  recycleAfter?: number;
  maxReroutes?: number;
  itemTimeoutMs?: number;
  onEvent?: (e: PoolEvent) => void;
}

export interface WorkerPool {
  rawConvert(items: string[]): Promise<RawConvertResult[]>;
  canonicalize(items: string[]): Promise<FixResult[]>;
  stop(): Promise<void>;
}

export type CreateWorker = (opts?: WorkerPoolOptions) => WorkerPool;

type RuntimeEntry = {
  compose: (
    html: string,
    ctx: ConversionContext,
    opts?: { converters?: Converter[]; htmlFallback?: HtmlFallback },
  ) => string;
  createWorker: CreateWorker;
  isRawConvertible: (html: string) => boolean;
  UNWRAP_SELECTOR: string;
  escapeHtml: (s: string) => string;
  escapeHtmlAttr: (s: string) => string;
  escapeHtmlText: (s: string) => string;
  buildEmbedBlock: (url: string) => string;
  guessEmbedProvider: (url: string) => string | null;
  sanitize: (html: string) => string;
  blockMarkupRoundtrips: (markup: string) => RoundtripResult;
  verifyComposedOutput: (
    blocksMarkup: string,
    sourceHtmlPlainText: string,
  ) => VerifyResult;
  heuristicBlocks: (html: string) => HeuristicResult;
  genericHtmlToBlocks: (
    html: string,
    ctx: ConversionContext,
    htmlFallback?: (html: string) => string,
  ) => string | null;
  composeFromRecipes: (
    html: string,
    recipes: RecipeRule[],
    ctx: ConversionContext,
    htmlFallback?: (html: string) => string,
  ) => string | null;
};

const require = createRequire(import.meta.url);

function loadRuntimeEntry(): RuntimeEntry {
  const { require: tsxRequire } = require('tsx/cjs/api') as {
    require: (id: string, parentURL: string) => unknown;
  };
  return tsxRequire('./internal-index.ts', import.meta.url) as RuntimeEntry;
}

const entry =
  process.env.VITEST_WORKER_ID !== undefined
    ? ((await import(`./internal-index.${'ts'}`)) as RuntimeEntry)
    : loadRuntimeEntry();

export const compose = entry.compose;
export const createWorker = entry.createWorker;
export const isRawConvertible = entry.isRawConvertible;
export const UNWRAP_SELECTOR = entry.UNWRAP_SELECTOR;
export const escapeHtml = entry.escapeHtml;
export const escapeHtmlAttr = entry.escapeHtmlAttr;
export const escapeHtmlText = entry.escapeHtmlText;
export const buildEmbedBlock = entry.buildEmbedBlock;
export const guessEmbedProvider = entry.guessEmbedProvider;
export const sanitize = entry.sanitize;
export const blockMarkupRoundtrips = entry.blockMarkupRoundtrips;
export const verifyComposedOutput = entry.verifyComposedOutput;
export const heuristicBlocks = entry.heuristicBlocks;
export const genericHtmlToBlocks = entry.genericHtmlToBlocks;
export const composeFromRecipes = entry.composeFromRecipes;
