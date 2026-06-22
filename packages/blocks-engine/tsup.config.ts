import { defineConfig } from 'tsup';

export default defineConfig({
  // Note: src/wp/index.ts + src/wp/worker-child.ts entries are added by later tasks (B/C phases)
  entry: ['src/index.ts'],
  format: ['esm', 'cjs'],
  dts: true,
  clean: true,
  sourcemap: true,
});
