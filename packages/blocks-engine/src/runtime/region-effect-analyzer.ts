import { analyzeRuntimeRegionEffects } from './region-effect-manifest.js';

export const REGION_EFFECT_ANALYZER_BUNDLE = 'blocks-engine/runtime-region-effect-analyzer/v1';

async function main() {
  const request = process.argv.includes('--version') ? null : await readRequest();
  process.stdout.write(
    JSON.stringify(
      request === null
        ? {
            bundle: REGION_EFFECT_ANALYZER_BUNDLE,
            schema: 'blocks-engine/runtime-region-effects/v1',
          }
        : {
            bundle: REGION_EFFECT_ANALYZER_BUNDLE,
            manifest: analyzeRuntimeRegionEffects(request.source),
          },
    ),
  );
}

void main();

async function readRequest(): Promise<{ source: string }> {
  const chunks: Buffer[] = [];
  for await (const chunk of process.stdin) chunks.push(Buffer.from(chunk));
  const value: unknown = JSON.parse(Buffer.concat(chunks).toString('utf8'));
  if (!value || typeof value !== 'object' || typeof (value as { source?: unknown }).source !== 'string') {
    throw new Error('Region effect analyzer expects a JSON object with a string source.');
  }
  return value as { source: string };
}
