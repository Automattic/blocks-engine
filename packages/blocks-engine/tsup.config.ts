import { defineConfig } from 'tsup';

export default defineConfig({
  entry: {
    index: 'src/internal-index.ts',
    'wp/index': 'src/wp/internal-index.ts',
    'wp/worker-child': 'src/wp/worker-child.ts',
  },
  format: ['esm', 'cjs'],
  dts: true,
  clean: true,
  sourcemap: true,
});
