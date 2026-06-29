# Single package with entry points (not a pure/wp package split)

Ship the engine as **one** package — `@automattic/blocks-engine` — with entry points rather than two packages:

- **default** (`.`) — React-free: `compose` + helpers + `createWorker()` (the pool client). Never loads `@wordpress/blocks` in the importing process.
- **`/wp`** — opt-in, **in-process** sync `rawConvert` / `canonicalize` / `convert` that load `@wordpress/blocks` in the current process; this is also what the forked worker child runs.

## Considered Options

- **Two packages (`@automattic/blocks-engine` pure + `@automattic/blocks-engine-wp`).** Rejected. The React isolation consumers need comes from the worker **process**, not a package boundary, so the split doesn't earn it. Its only real benefit — letting a helpers-only consumer skip the `@wordpress/*` + jsdom install — serves a largely hypothetical consumer: the package exists to do HTML→blocks, which needs rawHandler, and the cheerio-based pure code isn't browser-ready, so there's no edge/browser story either. The split also puts the headline name on the weaker half (the pure layer alone is a best-effort converter, not the engine).

## Consequences

- One version, one publish pipeline; no cross-package version coupling.
- React isolation is a property of the worker `fork()`, reachable from the single package's default entry — the importing process never loads React 18.
- A consumer with no React conflict can `import … from '@automattic/blocks-engine/wp'` for a synchronous in-process engine.
