import { createRequire } from 'node:module';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const nodeRequire = createRequire(
  typeof __filename === 'string' ? __filename : import.meta.url,
);

type WpModule = Record<string, unknown>;

// Resolve the built bundle relative to this module (dist/wp/require-wp.js -> dist/wp-runtime.cjs).
// An env override wins so the test:bundle script can point vitest (which runs from src/) at the
// pre-built dist/wp-runtime.cjs without MODULE_NOT_FOUND.
function bundlePath(): string {
  const override = process.env.BLOCKS_ENGINE_WP_RUNTIME_PATH;
  if (override) return override;
  if (typeof __dirname === 'string') {
    return nodeRequire('node:path').join(__dirname, '..', 'wp-runtime.cjs');
  }
  return fileURLToPath(new URL('../wp-runtime.cjs', import.meta.url));
}

let mode: 'bundle' | 'deps' | undefined;
let bundle: WpModule | undefined;

function resolveMode(): 'bundle' | 'deps' {
  if (mode) return mode;
  const forced = process.env.BLOCKS_ENGINE_WP_RUNTIME;
  if (forced === 'bundle' || forced === 'deps') {
    mode = forced;
  } else {
    mode = existsSync(bundlePath()) ? 'bundle' : 'deps';
  }
  return mode;
}

function loadBundle(): WpModule {
  bundle ??= nodeRequire(bundlePath()) as WpModule;
  return bundle;
}

export function requireWp(name: string): WpModule {
  if (resolveMode() === 'deps') {
    return nodeRequire(name) as WpModule;
  }
  const b = loadBundle();
  if (name === '@wordpress/block-serialization-default-parser') {
    return { parse: b.parseGrammar };
  }
  // @wordpress/blocks and @wordpress/block-library are both slices of the one bundle.
  return b;
}

/** Test-only: clear cached mode/bundle so a test can force a different mode. */
export function __resetRequireWpForTest(): void {
  mode = undefined;
  bundle = undefined;
}
