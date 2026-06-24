import { defineConfig } from 'tsup';

export default defineConfig({
  entry: {
    index: 'src/index.ts',
    'internals/index': 'src/internals/index.ts',
    'wp/index': 'src/wp/index.ts',
    'wp/worker-child': 'src/wp/worker-child.ts',
  },
  format: ['esm', 'cjs'],
  dts: true,
  clean: true,
  sourcemap: true,
});
