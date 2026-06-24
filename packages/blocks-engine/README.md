# @automattic/blocks-engine

Convert HTML into WordPress block markup from JavaScript or the command line.

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

## Install Quickstart

Install the package:

```sh
npm install @automattic/blocks-engine
```

Run a one-shot file conversion without adding a dependency:

```sh
npx @automattic/blocks-engine page.html
```

The CLI reads `page.html` and writes block markup to stdout. With no file argument, it reads HTML from stdin.

## Start Here

The root package export mirrors `src/index.ts`:

- `convert`
- `compose`
- `createWorker`
- `BlocksEngineError`
- `ConvertOptions`
- `BlocksEngineErrorOptions`
- `ConversionContext`
- `Converter`
- `HtmlFallback`
- `CreateWorker`
- `FixResult`
- `RawConvertResult`
- `WorkerPool`
- `WorkerPoolOptions`
- `PoolEvent`

Import runtime functions and classes normally, and import TypeScript shapes with `type`:

```ts
import {
  BlocksEngineError,
  compose,
  convert,
  createWorker,
  type Converter,
  type PoolEvent,
  type WorkerPool,
} from '@automattic/blocks-engine';
```

Advanced utilities that are not part of the headline path live under `@automattic/blocks-engine/internals`.

## Which Do I Use?

Use `convert` for the normal end-to-end path. It accepts HTML plus an optional conversion context, tries worker-backed conversion, falls back through composition when needed, canonicalizes the result, and returns WordPress block markup. If you do not pass a pool, `convert` creates one for the call and stops it before returning.

Use `compose` when you want the pure, synchronous in-process composition layer directly. It is useful for custom `Converter` chains, custom `HtmlFallback` behavior, and tests or tools that already know they only need best-effort local HTML-to-block composition. It does not create, own, or stop a worker pool.

Use `createWorker` when you are converting batches or running a long-lived process. Reuse one `WorkerPool` across calls instead of paying worker startup costs per item, and pass it into `convert` with `ConvertOptions.pool` when you want `convert` to use that shared pool.

Use `BlocksEngineError` when catching package-level failures that include a stable `code` and user-facing `hint`.

## Worker Pool Lifecycle

`createWorker(options?)` returns a `WorkerPool` with `rawConvert(items)`, `canonicalize(items)`, and `stop()`. Treat the pool as an owned process resource.

The default pool size is `min(cores, 4)`, with a minimum of one worker. Passing `WorkerPoolOptions.size` floors the value and still keeps at least one worker. For most request/response code, start with the default. For CI, deterministic tests, or memory-constrained jobs, prefer `size: 1` or another explicit small number.

Workers are child processes connected to the parent over Node IPC. Create the pool in the parent process that will send work, and do not try to pass a live pool across process boundaries. Workers die with the parent process, but parent shutdown is not a deterministic cleanup API. Always call `await pool.stop()` in a `finally` block, test teardown, or service shutdown hook. The one-shot `convert` helper handles this for internally-created pools; shared pools are your responsibility.

Use `recycleAfter` to replace a child after it has processed a configured number of items. The default is `0`, which disables recycling. Recycling is useful for long-running batches, CI isolation, and workloads where periodically refreshing child process state is safer than keeping one process alive indefinitely.

Use `itemTimeoutMs` to bound how long one item can occupy a worker. The default is `10000`. On timeout or worker failure, the pool reroutes work until its reroute limit is reached; unresolved raw conversions return a `RawConvertResult` sentinel and unresolved canonicalization returns the original HTML in a `FixResult`.

Use `onEvent` for operational visibility. The callback receives `PoolEvent` values for `child-spawn`, `child-crash`, `re-route`, `recycle`, `sentinel`, and `pool-degraded`, with `childId` and `count` when available. In CI, log these events when diagnosing flakes, timeouts, fork limits, or degraded worker startup.

Build or run from an environment that can fork Node child processes and use IPC. For packaged or CI usage, run the package from its built distribution so the worker child file is present; source-mode execution depends on the local TypeScript runner setup.

```ts
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
