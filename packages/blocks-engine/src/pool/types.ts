import type { PoolEvent } from './events';

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
