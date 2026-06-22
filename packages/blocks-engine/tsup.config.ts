import { defineConfig } from 'tsup';

export default defineConfig({
  // Note: src/wp/worker-child.ts entry is added by a later task (C phase)
  entry: ['src/index.ts', 'src/wp/index.ts'],
  format: ['esm', 'cjs'],
  dts: true,
  clean: true,
  sourcemap: true,
});
