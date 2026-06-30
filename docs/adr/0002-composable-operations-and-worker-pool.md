# Engine API surface: composable operations + a worker pool

The engine exposes three **composable operations** rather than one monolithic `convert()`:

- `compose(html, ctx, { converters, htmlFallback })` — **pure, synchronous**, React-free. Runs consumer converters (functions or recipe tables) → built-in generic catalog → `htmlFallback`. No `@wordpress/blocks`.
- `rawConvert(html)` and `canonicalize(markup)` — **async**, backed by a package-managed **pool** of `fork()`ed worker processes (default `min(cores, 4)`, configurable, dies with the parent). These load `@wordpress/blocks`, isolated in the pool.

A convenience `convert()` chains `rawConvert → compose → canonicalize` for consumers who just want "HTML in, blocks out."

(Packaging of these operations into one package with entry points is ADR 0003.)

## Considered Options

- **One monolithic `convert()` owning rawHandler internally.** Rejected: it would force consumer **function** converters (e.g. DLA's imperative Squarespace walk) through the worker IPC boundary, where functions can't be serialized — breaking function-converters under isolation. It would also force the consumer's currently-synchronous composition path to become async.
- **Single forked child instead of a pool.** Rejected: throughput regression versus the current `cluster(min(cores, 4))` on whole-site runs, which is exactly the workload the parallelism exists for.

## Consequences

- Consumer **function** converters run in the pure, in-process `compose`; only HTML/markup **strings** cross the worker boundary. Serialization is never an issue.
- The first consumer (DLA) deletes its bespoke HTTP server + cluster client and consumes the package's worker pool; it keeps wiring the three ops at its own pipeline stages.
