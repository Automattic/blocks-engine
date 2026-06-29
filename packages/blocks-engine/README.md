# @automattic/blocks-engine

Convert HTML and static sites into WordPress block markup and block themes — from JavaScript or the command line.

- **Convert HTML → blocks.** Turn an HTML fragment or page into clean WordPress block markup.
- **Build a whole block theme.** Point it at a directory of static HTML and get back a ready-to-install block theme (templates, parts, patterns, `theme.json`, assets).
- **Use it however you like.** A one-shot async API, a pure in-process composer, a reusable worker pool, and an `npx` CLI all ship in the box.

## Install

```sh
npm install @automattic/blocks-engine
```

Or run the CLI without installing anything:

```sh
npx @automattic/blocks-engine --help
```

Requires Node.js with support for forking child processes and IPC (the worker pool runs conversions in child processes).

## Quick Start

### Build a block theme from the CLI

```sh
npx @automattic/blocks-engine theme ./my-site
```

This reads the static HTML in `./my-site` and writes a complete block theme to `./_block-theme`. The command exits without touching anything if that default directory already exists, so a stray `_block-theme/` is never silently overwritten.

Set the output directory and theme metadata with flags:

```sh
npx @automattic/blocks-engine theme ./my-site --out ./theme --slug my-site --name "My Site"
```

The bare source-directory form is shorthand for `theme`:

```sh
npx @automattic/blocks-engine ./my-site
```

### Convert HTML in JavaScript

```js
import { convert } from '@automattic/blocks-engine';

const html = '<h2>Hi</h2><p>Body</p>';
const blockMarkup = await convert(html, { url: 'https://example.com/page.html' });

console.log(blockMarkup);
```

Emits:

```html
<!-- wp:heading -->
<h2 class="wp-block-heading">Hi</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Body</p>
<!-- /wp:paragraph -->
```

`convert` is the normal end-to-end path: it tries worker-backed conversion, falls back through composition when needed, canonicalizes the result, and returns WordPress block markup. If you don't pass a pool, it spins one up for the call and stops it before returning.

## CLI

```
blocks-engine theme <srcDir> [--out <dir>] [--slug <slug>] [--name <name>]
blocks-engine <srcDir> [--out <dir>] [--slug <slug>] [--name <name>]
blocks-engine convert [file]
blocks-engine --help | -h
```

| Command   | Description |
|-----------|-------------|
| `theme`   | Build a whole-site block theme from a source directory. |
| `convert` | Convert a single HTML file — or stdin — to block markup. |

| Option          | Description |
|-----------------|-------------|
| `--out <dir>`   | Write the generated theme to a directory (default: `./_block-theme`; the command exits if it already exists). |
| `--slug <slug>` | Set the generated theme slug. |
| `--name <name>` | Set the generated theme name. |

`convert` writes block markup to stdout. With a file argument it reads that file; with no argument it reads HTML from stdin:

```sh
npx @automattic/blocks-engine convert page.html
printf '<h2>Hi</h2>' | npx @automattic/blocks-engine convert
```

## JavaScript API

The root export (`@automattic/blocks-engine`) mirrors `src/index.ts`:

**Functions & classes**

| Export | Use it to… |
|--------|-----------|
| `convert` | Run the normal end-to-end HTML → blocks conversion (async). |
| `compose` | Run the pure, synchronous in-process composition layer directly. |
| `createWorker` | Create a reusable `WorkerPool` for batches or long-lived processes. |
| `siteToTheme` | Build and write a block theme from a source directory (the CLI's `theme` engine). |
| `writeTheme` | Write an already-built `ThemeModel` to disk. |
| `lintThemeJson` | Lint a `theme.json` object and get back structured findings. |
| `BlocksEngineError` | Catch package-level failures carrying a stable `code` and user-facing `hint`. |

**Types**

`ConvertOptions`, `BlocksEngineErrorOptions`, `SiteToThemeOptions`, `ThemeBuildResult`, `ThemeJsonLintResult`, `ConversionContext`, `Converter`, `HtmlFallback`, `CreateWorker`, `FixResult`, `RawConvertResult`, `WorkerPool`, `WorkerPoolOptions`, `PoolEvent`.

Import runtime functions and classes normally, and import TypeScript shapes with `type`:

```ts
import {
  BlocksEngineError,
  compose,
  convert,
  createWorker,
  siteToTheme,
  type Converter,
  type PoolEvent,
  type WorkerPool,
} from '@automattic/blocks-engine';
```

Advanced utilities that aren't part of the headline path live under `@automattic/blocks-engine/internals`. Theme- and WordPress-specific helpers are exposed under `@automattic/blocks-engine/theme` and `@automattic/blocks-engine/wp`.

### Build a theme programmatically

```ts
import { siteToTheme } from '@automattic/blocks-engine';

const result = await siteToTheme('./my-site', {
  outDir: './theme',
  themeMeta: { slug: 'my-site', name: 'My Site' },
});

console.log(`Wrote ${result.written.length} files to ${result.outDir}`);
result.warnings.forEach((warning) => console.warn(warning));
```

`siteToTheme` ingests the source directory, builds the theme model, and writes it to `outDir` (defaulting to `<srcDir>/theme`), returning a `ThemeBuildResult` with the written file list, tallies, warnings, and diagnostics. Pass your own `pool` to reuse a worker pool across builds; otherwise it manages one internally.

## Which entry point do I use?

- **`convert`** — the default. Async, worker-backed with composition fallback, and self-contained: it owns and stops its pool unless you supply one.
- **`compose`** — the pure, synchronous composition layer. Reach for it when you want custom `Converter` chains, custom `HtmlFallback` behavior, or tests and tools that only need best-effort local HTML-to-block composition. It never creates, owns, or stops a worker pool.
- **`createWorker`** — for batches or long-lived processes. Reuse one `WorkerPool` across calls to avoid per-item worker startup costs, and pass it into `convert` via `ConvertOptions.pool`.
- **`siteToTheme`** — for turning a directory of static HTML into a full block theme (this is what the CLI's `theme` command runs).
- **`BlocksEngineError`** — catch this for package-level failures with a stable `code` and user-facing `hint`.

## Worker Pool Lifecycle

`createWorker(options?)` returns a `WorkerPool` with `rawConvert(items)`, `canonicalize(items)`, and `stop()`. Treat the pool as an owned process resource.

```ts
import { convert, createWorker, type PoolEvent } from '@automattic/blocks-engine';

const events: PoolEvent[] = [];
const pool = createWorker({
  size: 2,
  recycleAfter: 100,
  itemTimeoutMs: 15_000,
  onEvent: (event) => events.push(event),
});

try {
  const blockMarkup = await convert('<p>Hello</p>', { url: 'https://example.com/' }, { pool });
  console.log(blockMarkup);
} finally {
  await pool.stop();
}
```

**Sizing.** The default pool size is `min(cores, 4)`, with a minimum of one worker. `WorkerPoolOptions.size` floors the value and still keeps at least one worker. Start with the default for request/response code; prefer `size: 1` (or another small number) for CI, deterministic tests, or memory-constrained jobs.

**Cleanup.** Workers are child processes connected to the parent over Node IPC. Create the pool in the process that will send work, and don't pass a live pool across process boundaries. Workers die with the parent, but that is not a deterministic cleanup path — always `await pool.stop()` in a `finally` block, test teardown, or shutdown hook. The one-shot `convert` helper handles this for pools it creates itself; shared pools are your responsibility.

**Recycling.** `recycleAfter` replaces a child after it has processed that many items. The default is `0` (disabled). Recycling helps with long-running batches, CI isolation, and workloads where periodically refreshing child-process state is safer than keeping one alive indefinitely.

**Timeouts & resilience.** `itemTimeoutMs` (default `10000`) bounds how long one item can occupy a worker. On timeout or worker failure the pool reroutes work until its reroute limit is reached; unresolved raw conversions return a `RawConvertResult` sentinel and unresolved canonicalization returns the original HTML in a `FixResult`.

**Observability.** `onEvent` receives `PoolEvent` values for `child-spawn`, `child-crash`, `re-route`, `recycle`, `sentinel`, and `pool-degraded`, with `childId` and `count` when available. Log these when diagnosing flakes, timeouts, fork limits, or degraded worker startup.

**Packaging.** Run from an environment that can fork Node child processes and use IPC. For packaged or CI usage, run the package from its built distribution so the worker child file is present; source-mode execution depends on the local TypeScript runner setup.

## License

GPL-3.0-or-later
