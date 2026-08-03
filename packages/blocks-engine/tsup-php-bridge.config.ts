import { defineConfig } from 'tsup';

export default defineConfig({
  entry: ['src/runtime/region-effect-analyzer.ts'],
  format: ['esm'],
  outDir: '../../php-transformer/resources/runtime',
  clean: true,
  dts: false,
  sourcemap: false,
  noExternal: ['acorn'],
});
