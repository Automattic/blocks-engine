import type { CreateWorker, WorkerPoolOptions } from './types';

export type {
  FixResult,
  RawConvertResult,
  WorkerPool,
  WorkerPoolOptions,
} from './types';
export type { PoolEvent } from './events';

export const createWorker: CreateWorker = (opts?: WorkerPoolOptions) => {
  void opts;

  const notImplemented = async (): Promise<never> => {
    throw new Error('createWorker implementation is added by Phase C');
  };

  return {
    rawConvert: notImplemented,
    canonicalize: notImplemented,
    stop: async () => {},
  };
};
