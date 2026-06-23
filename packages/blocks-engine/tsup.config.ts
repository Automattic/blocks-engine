import { defineConfig } from 'tsup';

export default defineConfig({
  entry: ['src/index.ts', 'src/wp/index.ts', 'src/wp/worker-child.ts'],
  format: ['esm', 'cjs'],
  dts: true,
  clean: true,
  sourcemap: true,
});
