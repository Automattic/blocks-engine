# Structured convert report: envelope + fallback inventory + metrics

**Date:** 2026-06-30
**Branch:** `feature/js-envelope`
**Status:** Approved design — ready for implementation plan

## Background

This is item #1 from `docs/to-port-to-js.md` ("Structured result envelope +
fallback inventory + metrics"), the highest effort-to-fidelity, lowest-risk port
from `php-transformer/` into `packages/blocks-engine`.

Today `convert()` returns a bare `Promise<string>` (block markup only). Three
fidelity-relevant signals already flow through the pipeline but are discarded:

- `rawConvert` returns `wpHtmlResidue` (count of blocks `rawHandler` could not
  convert) and the caller knows which path was taken (worker raw vs `compose`).
- `canonicalize` returns `FixResult.fixedIssues` / `changed` (normalizations
  applied).
- The final markup itself: every unconverted chunk lands as a `core/html` block
  (the `compose` / `defaultHtmlFallback` path wraps it in `<!-- wp:html -->`).

The current verification surface is text-presence only (`src/output-verify.ts`)
plus the tuner ratchet, which scores structure + content coverage. There is no
per-conversion record of *what failed to convert* and no metrics envelope, so
fidelity regressions are not measurable.

`php-transformer` solved this with `Contract\TransformerResult` (a versioned
result envelope with `status` / `fallbacks` / `diagnostics` / `metrics` /
`coverage`) and a tolerant `Contract\ConversionFindingContract` for findings.
This spec ports the minimal, JS-idiomatic subset.

## Goals

- Expose a structured, versioned conversion result envelope without breaking the
  existing string API.
- Produce a per-conversion **fallback inventory**: one finding per chunk that did
  not become a native block.
- Produce **metrics** (input/output bytes, block count, fallback/diagnostic
  counts, duration).
- Keep the derivation logic pure and unit-testable without a worker pool.

## Non-goals (separate items in `docs/to-port-to-js.md`)

- Wiring fallback-count into the tuner ratchet (item #2). The envelope is
  designed so that work is trivial later, but it is not done here.
- Emitting hallucination findings from `verifyComposedOutput` (stays available,
  not auto-emitted in this slice).
- Visual / style parity (items #1 visual, #3, #4 in the port doc).
- Source provenance and structure signals (item #2 in the port doc).

## Design

### 1. API surface

- New canonical function:
  `convertReport(html, ctx?, opts?): Promise<ConvertReport>`. Same signature as
  `convert` (same `Partial<ConversionContext>` and `ConvertOptions`).
- `convert(...)` becomes a one-line projection:
  `(await convertReport(...)).blockMarkup`. The string and the envelope share a
  single conversion path and can never drift.
- New exports from `src/index.ts`: `convertReport` and the types `ConvertReport`,
  `ConversionFinding`, `ConversionMetrics`.

This mirrors the php-transformer pattern where the rich result object is
canonical and the flat output is a projection of it (`toArray()`).

### 2. Envelope shape

```ts
interface ConvertReport {
  schema: 'blocks-engine/convert-report/v1';
  status: 'success' | 'success_with_warnings' | 'failed';
  blockMarkup: string;
  fallbacks: ConversionFinding[];   // unconverted-content inventory
  diagnostics: ConversionFinding[]; // normalizations / non-fatal notes
  metrics: ConversionMetrics;
}
```

- `status` is `success_with_warnings` iff there is at least one fallback OR at
  least one warning-or-higher diagnostic; otherwise `success`.
- `failed` is present in the type for forward compatibility but **is never
  emitted in this slice**. Hard failures (e.g. `INJECTION_VECTORS_REMAIN`)
  continue to throw `BlocksEngineError` exactly as today.
- `schema` is a constant version string so future shape changes are detectable.

### 3. Finding shape

Mirrors php-transformer's intentionally *tolerant* `ConversionFindingContract`:
a stable `code` is the only required field; well-known fields are type-checked
when present; additive producer-specific fields are allowed.

```ts
interface ConversionFinding {
  code: string;                             // required, stable identifier
  severity: 'info' | 'warning' | 'error';   // closed set
  message?: string;
  selector?: string;                        // synthetic, e.g. "core/html[2]"
  snippet?: string;                         // bounded, sanitized, UNTRUSTED text
  [extra: string]: unknown;                 // additive, producer-specific
}
```

`snippet` is bounded to <= 2000 **characters** (not bytes — byte truncation can
split a UTF-8 sequence and produce mojibake) and is passed through the existing
`sanitize` helper. It is diagnostic metadata and must be treated as **untrusted**
by consumers (escape before rendering); the contract does not guarantee it is
safe to inject into an HTML context.

First-slice producers:

- `unconverted_html` — one finding per `core/html` block in the final markup,
  `severity: 'warning'`, placed in `fallbacks`. `selector` is the synthetic
  positional locator `core/html[<index>]`; `snippet` is the bounded, sanitized
  inner HTML of the block. **Capped at 100 findings** (see Section 4); the count
  is bounded but `metrics.fallbackCount` always reports the true total.
- `normalized_markup` — one finding per `canonicalize` fixed issue,
  `severity: 'info'`, placed in `diagnostics`. `message` carries the fixed-issue
  string.
- `conversion_degraded` — emitted in `diagnostics`, `severity: 'warning'`, when
  the markup came from the worker pool's degraded/timeout **sentinel** path
  (the conversion did not run to completion and the output is largely
  un-converted original HTML). `message` carries the sentinel reason. This makes
  a degraded conversion distinguishable from a legitimately HTML-heavy page.
- `fallback_inventory_truncated` — emitted in `diagnostics`, `severity: 'info'`,
  only when the `unconverted_html` inventory exceeded the cap. Carries `total`
  (true count) and `kept` (100).

### 4. Metrics

```ts
interface ConversionMetrics {
  inputBytes: number;        // UTF-8 byte length of input html
  outputBytes: number;       // UTF-8 byte length of blockMarkup
  blockCount: number;        // named blocks counted in the worker (fixResult.blockCount)
  fallbackCount: number;     // TRUE total of unconverted core/html (may exceed fallbacks.length when capped)
  diagnosticCount: number;   // diagnostics.length
  transformDurationMs: number; // wall time of the convertReport call
}
```

`fallbackCount` is the real number of unconverted `core/html` islands even when
the `fallbacks` array is capped at 100 (see Section 3), so the metric never lies
about totals.

**Where the parse happens (revised per deep review — Approach A).** The package
declares **no `@wordpress/*` in runtime `dependencies`**; this is enforced by
`src/__tests__/no-wordpress-runtime-deps.test.ts`. The block parser
(`@wordpress/block-serialization-default-parser`) is only resolvable inside the
worker / bundled WP runtime (`block-tree.ts` imports it as a **type only**;
value imports live behind `requireWp`). Therefore `buildReport` must **not**
value-import the parser. Instead, the **worker** computes the block analysis
(where the parser is already loaded for `canonicalize`) and returns it on the
`FixResult`; `buildReport` in the main process stays pure and WP-free, consuming
that pre-extracted summary. The worker reuses the existing `walkBlocks`
(`src/block-tree.ts`) traversal rather than introducing a new parse.

`FixResult` gains two additive output fields:

```ts
interface FixResult {
  html: string;
  changed: boolean;
  fixedIssues: string[];
  blockCount: number;                              // NEW: named blocks in tree
  htmlIslands: { index: number; snippet: string }[]; // NEW: core/html inventory
}
```

The pool's **degraded/timeout sentinel** path constructs a `FixResult` by hand;
it must populate safe defaults (`blockCount: 0`, `htmlIslands: []`) and signal
the degradation so `convert-report.ts` can emit `conversion_degraded`.

### 5. Module layout

- `src/report/findings.ts` — **pure, WP-free** core:
  `buildReport({ inputHtml, blockMarkup, fixResult, transformDurationMs, degraded? }): ConvertReport`.
  Maps `fixResult.htmlIslands` into `unconverted_html` fallbacks (capped at 100,
  emitting `fallback_inventory_truncated` past the cap), maps
  `fixResult.fixedIssues` into `normalized_markup` diagnostics, emits
  `conversion_degraded` when `degraded` is set, derives `status` and `metrics`
  (`blockCount` from `fixResult.blockCount`). `transformDurationMs` is an
  explicit input (default `0`) so the function stays pure and deterministic — the
  async wrapper passes the measured wall time, tests pass a fixed value. No
  async, no pool, no parser import — fully unit-testable.
- `src/report/contract.ts` — `assertConvertReport(report)`: a JS port of
  php-transformer's `assertCanonicalEnvelope` (required keys present, `schema`
  matches, `status` and every finding `severity` in their closed sets, arrays are
  arrays). Throws on violation. Exported under `@automattic/blocks-engine/internals`
  and used in tests.
- `src/convert-report.ts` — async `convertReport`: drives the pool exactly as
  `convert` does today (raw path with `compose` fallback, then `canonicalize`),
  detects the sentinel/degraded signal, measures wall time, and calls
  `buildReport`.
- `src/convert.ts` — imports `convertReport`, returns `.blockMarkup`.
- Worker changes: `src/wp/canonicalize.ts` / `src/wp/worker-child.ts` (and the
  pool protocol / `FixResult` type) extended to compute and carry
  `blockCount` + `htmlIslands` via `walkBlocks`.

Keeping `buildReport` pure, WP-free, and duration-injected means the envelope
logic is deterministic and testable without booting Gutenberg or a worker pool,
and the no-`@wordpress`-runtime-deps contract test still passes.

### 6. Data flow

```
convertReport(html, ctx, opts)
  -> pool.rawConvert([html])           (existing)
  -> raw clean? use raw : compose(...) (existing path choice)
  -> pool.canonicalize([blockMarkup])  (existing) -> FixResult
        (worker: walkBlocks -> blockCount + htmlIslands on the result;
         degraded/timeout -> sentinel FixResult w/ safe defaults + degraded flag)
  -> buildReport({ inputHtml: html, blockMarkup: fixed.html, fixResult: fixed,
                   transformDurationMs: <measured wall time>, degraded: <sentinel?> })
  -> ConvertReport

convert(html, ctx, opts) = (await convertReport(...)).blockMarkup
```

### 7. Error handling

- Hard, unsafe-input failures keep throwing `BlocksEngineError` (no behavior
  change). `convertReport` does not swallow them into a `failed` status in this
  slice.
- Degraded-pool sentinels: the conversion returns un-converted original HTML.
  `convert-report.ts` detects the sentinel and `buildReport` emits a
  `conversion_degraded` diagnostic (`success_with_warnings`), so the degradation
  is named rather than silently indistinguishable from an HTML-heavy page. The
  residual `core/html` still shows up honestly in the (capped) fallback
  inventory, and `metrics.fallbackCount` keeps the true total.
- `buildReport` is defensive: empty/odd markup and an empty `htmlIslands`
  yield zeros and `status: 'success'` rather than throwing.

### 8. Testing

- Pure `buildReport` unit tests: `fixResult.htmlIslands` of length N yields N
  `unconverted_html` fallbacks with correct synthetic selectors; clean input
  yields zero fallbacks and `status: 'success'`; `fixedIssues` map to
  `normalized_markup` diagnostics; metrics (byte counts, `blockCount`,
  `fallbackCount`) are correct; snippets bounded to <= 2000 **characters**
  (multibyte-safe); over-cap input keeps 100 fallbacks, emits
  `fallback_inventory_truncated`, and `fallbackCount` reports the true total;
  `degraded: true` emits `conversion_degraded`.
- `assertConvertReport` contract tests: a well-formed report passes; missing key,
  wrong schema, out-of-set severity/status each throw (parallels
  php-transformer's envelope assertion tests).
- Worker tests: `canonicalize` returns accurate `blockCount` + `htmlIslands`;
  the sentinel path carries safe defaults.
- Dependency-boundary test: `no-wordpress-runtime-deps.test.ts` still passes
  (proves `buildReport` / the main path stayed WP-free).
- Integration tests (`convertReport`): clean input -> `success`, empty
  `fallbacks`; input containing an element that survives as `core/html` ->
  `success_with_warnings` with exactly one `unconverted_html` fallback.
- Projection test: `convert(x)` equals `(await convertReport(x)).blockMarkup`
  for representative inputs.

## Rollout / compatibility

- Purely additive. No existing export changes signature or return type.
- README gains a short "Structured report" subsection documenting `convertReport`
  and the envelope; the existing `convert` one-liner stays as the headline.
- `schema` version string lets the eventual ratchet integration (item #2) and any
  downstream consumer detect envelope changes.

## Deferred follow-ups (from deep review + adversarial review)

Tracked, intentionally out of scope for this branch. None block merge.

1. **Invalid-block severity** — `canonicalize` folds genuine block-validation
   failures into `fixedIssues`, which `buildReport` maps to `info`
   `normalized_markup`. Real validation failures should get their own code at
   `warning` severity so they escalate `status`.
2. **`fallbackCount` vs `fallbacks.length`** — when the inventory is capped at
   100, `metrics.fallbackCount` is the true total and exceeds `fallbacks.length`.
   Document this in the README / type docs (a `fallback_inventory_truncated`
   diagnostic already discloses it).
3. **Inventory perf gate (council decision C)** — benchmark `siteToTheme`
   per-page conversion cost; only if material, gate inventory collection *inside
   the worker* (conditional work on the single path) — never fork `convert()`
   into a markup-only path.
4. **Snippet render-safety** — `snippet` is passed through `sanitize()` (strips
   script/style/on*) but is NOT XSS-safe (leaves `javascript:` URLs, `iframe`,
   etc.). It is documented as untrusted text; consider escaping to text outright
   if a report viewer ever renders it.
5. **`failed` status** — currently reserved and unreachable. Decide whether the
   degraded/sentinel path should map to `failed`, or drop `failed` from the enum.
6. **Purity guard strength** — `no-wordpress-runtime-deps.test.ts` checks
   `package.json` only, not the import graph. Consider an import-graph assertion
   so a future value-level `@wordpress/*` import in the pure core is caught.
