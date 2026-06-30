# Bundle the WordPress runtime into `dist` (not a normal `@wordpress/*` dependency)

Ship the WordPress runtime the engine calls — `@wordpress/block-library` (`registerCoreBlocks`), `@wordpress/blocks` (`rawHandler`, `serialize`, `parse`, `createBlock`, `getBlockAttributes`), and `@wordpress/block-serialization-default-parser` — as a single self-contained **CJS chunk** in `dist`, loaded via `createRequire`. Move `@wordpress/*` to `devDependencies`; keep `jsdom`, `cheerio`, `domhandler` as runtime dependencies.

This cuts a consumer's install footprint from ~491 MB (post-Tier-1) to **~53 MB** (~20 MB bundle + ~32 MB jsdom/cheerio/domhandler), with no public API change. The bundle aliases nine edit-only leaf packages (`icons`, `ui`, `dataviews`, `image-cropper`, `server-side-render`, `commands`, `preferences`, `notices`, `keyboard-shortcuts`) to an empty stub at build time; `block-editor`, `components`, `core-data`, `patterns` are bundled real because they run the `@wordpress/private-apis` lock/unlock handshake at module load.

## Considered Options

- **Tier 1 only — version alignment (merged, PR #356).** Collapsed nested-duplicate `node_modules` (~2.0 GB → ~491 MB) but left the editor stack that `registerCoreBlocks()` pulls transitively (~296 MB of `@wordpress/*`). Necessary, not sufficient.

- **`pnpm.overrides` to empty-stub the unused packages.** Rejected. `overrides` are honored only in the install **root** (the consumer app), never from inside a dependency, so overrides in this package help only this repo's dev install — not consumers. Measured ~258 MB locally, ~0 benefit for DLA.

- **`peerDependencies` / `optionalDependencies` for the editor stack.** Rejected. `block-library` hard-depends on them; the consumer still installs the full tree.

- **Minimal custom block registration (register only emitted blocks).** Deferred. Could push below 20 MB but is a larger, riskier change; bundling already reaches the ~50 MB target. Revisit only if 50 MB proves insufficient.

- **ESM bundle.** Rejected. Fails at runtime with "Dynamic require not supported" (WP/React use dynamic `require`). CJS bundles work, and the engine already loads the runtime via `createRequire`.

## Consequences

- Consumers install no `@wordpress/*` at all; the runtime travels inside `dist`.
- React 18 lives entirely inside the bundle — more isolated from a host's React 19 than today, consistent with the worker-`fork()` isolation of ADR 0002 and the entry points of ADR 0003 (default `.` never loads the bundle in-process; `/wp` and `/theme` and the worker child do).
- The build gains a bundling step (esbuild via tsup): `format: cjs`, `minify`, `external: jsdom/cheerio/domhandler`, alias stub set → empty.
- The stub set is correctness-sensitive: a future block reaching a stubbed package would regress output. Guarded by the full golden/reconstruct suite (incl. DLA goldens) run against the bundle, plus a CI assertion that production `dependencies` contain no `@wordpress/*` and the bundle stays under a size ceiling.
- DLA (consumes only `@automattic/blocks-engine/theme`) is unaffected by the internal change, but must declare its direct `@wordpress/interactivity` import itself (currently an unresolved phantom dependency) — an independent cleanup.
- The runtime chunk's sourcemap is intentionally **not** shipped: the map weighs ~52 MB (minified third-party WP code) and would more than double the consumer footprint. If the bundled runtime ever needs to be debugged, rebuild locally with `sourcemap: true` in `scripts/build-wp-runtime.mjs`.
- Full design and validation gates: `docs/superpowers/specs/2026-06-29-blocks-engine-package-size-tier2-bundling-design.md` (local).
