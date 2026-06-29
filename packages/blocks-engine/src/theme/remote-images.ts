export interface RemoteImageFetchConfig {
  timeoutMs: number;
  maxBytes: number;
  allowedSchemes: readonly string[];
}

export const DEFAULT_REMOTE_IMAGE_FETCH_CONFIG = {
  timeoutMs: 10_000,
  maxBytes: 10 * 1024 * 1024,
  allowedSchemes: ['http:', 'https:'],
} as const satisfies RemoteImageFetchConfig;

export interface RemoteImageFetchSuccess {
  ok: true;
  url: string;
  contentType: string;
  bytes: Uint8Array;
}

export interface RemoteImageFetchSkipped {
  ok: false;
  warning: string;
}

export type RemoteImageFetchResult = RemoteImageFetchSuccess | RemoteImageFetchSkipped;

export async function fetchRemoteImage(
  rawUrl: string,
  opts: {
    fetchImpl?: typeof fetch;
    config?: Partial<RemoteImageFetchConfig>;
  }
): Promise<RemoteImageFetchResult> {
  void rawUrl;
  void opts;
  return { ok: false, warning: 'remote image fetch is not implemented' };
}
