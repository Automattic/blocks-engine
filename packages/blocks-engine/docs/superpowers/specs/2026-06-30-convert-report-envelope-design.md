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
  snippet?: string;                         // bounded (<= 2000 bytes), sanitized
  [extra: string]: unknown;                 // additive, producer-specific
}
```

First-slice producers:

- `unconverted_html` — one finding per `core/html` block in the final markup,
  `severity: 'warning'`, placed in `fallbacks`. `selector` is the synthetic
  positional locator `core/html[<index>]`; `snippet` is the bounded, sanitized
  inner HTML of the block.
- `normalized_markup` — one finding per `canonicalize` fixed issue,
  `severity: 'info'`, placed in `diagnostics`. `message` carries the fixed-issue
  string.

### 4. Metrics

```ts
interface ConversionMetrics {
  inputBytes: number;        // UTF-8 byte length of input html
  outputBytes: number;       // UTF-8 byte length of blockMarkup
  blockCount: number;        // top-level blocks parsed from final markup
  fallbackCount: number;     // fallbacks.length
  diagnosticCount: number;   // diagnostics.length
  transformDurationMs: number; // wall time of the convertReport call
}
```

`blockCount` and the `core/html` inventory both come from a single parse of the
final markup with `@wordpress/block-serialization-default-parser` (already a
dependency; used by `scripts/tuner/score.ts`).

### 5. Module layout

- `src/report/findings.ts` — **pure** core:
  `buildReport({ inputHtml, blockMarkup, fixResult, transformDurationMs }): ConvertReport`.
  Parses the markup, extracts `core/html` blocks into `unconverted_html`
  fallbacks, maps `fixResult.fixedIssues` into `normalized_markup` diagnostics,
  derives `status` and `metrics`. `transformDurationMs` is an explicit input
  (default `0`) so the function stays pure and deterministic — the async wrapper
  passes the measured wall time, and tests pass a fixed value. No async, no
  pool — fully unit-testable.
- `src/report/contract.ts` — `assertConvertReport(report)`: a JS port of
  php-transformer's `assertCanonicalEnvelope` (required keys present, `schema`
  matches, `status` and every finding `severity` in their closed sets, arrays are
  arrays). Throws on violation. Exported under `@automattic/blocks-engine/internals`
  and used in tests.
- `src/convert-report.ts` — async `convertReport`: drives the pool exactly as
  `convert` does today (raw path with `compose` fallback, then `canonicalize`),
  measures wall time, and calls `buildReport`, injecting the measured duration
  into the returned metrics.
- `src/convert.ts` — imports `convertReport`, returns `.blockMarkup`.

Keeping `buildReport` pure and duration-injected means the envelope logic is
deterministic and testable without booting Gutenberg or a worker pool.

### 6. Data flow

```
convertReport(html, ctx, opts)
  -> pool.rawConvert([html])           (existing)
  -> raw clean? use raw : compose(...) (existing path choice)
  -> pool.canonicalize([blockMarkup])  (existing) -> FixResult
  -> buildReport({ inputHtml: html, blockMarkup: fixed.html, fixResult: fixed,
                   transformDurationMs: <measured wall time> })
  -> ConvertReport

convert(html, ctx, opts) = (await convertReport(...)).blockMarkup
```

### 7. Error handling

- Hard, unsafe-input failures keep throwing `BlocksEngineError` (no behavior
  change). `convertReport` does not swallow them into a `failed` status in this
  slice.
- Degraded-pool sentinels behave exactly as today (the existing fallbacks in the
  pool path are unchanged); whatever markup results is reported, and any residual
  `core/html` shows up honestly in the fallback inventory.

### 8. Testing

- Pure `buildReport` unit tests: markup with N `core/html` blocks yields N
  `unconverted_html` fallbacks with correct synthetic selectors; clean markup
  yields zero fallbacks and `status: 'success'`; `fixedIssues` map to
  `normalized_markup` diagnostics; metrics (byte counts, `blockCount`,
  `fallbackCount`) are correct; snippets are bounded to <= 2000 bytes.
- `assertConvertReport` contract tests: a well-formed report passes; missing key,
  wrong schema, out-of-set severity/status each throw (parallels
  php-transformer's envelope assertion tests).
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
