# Shared stylesheet structure (#4238)

Reduced regression: four pages, one shared stylesheet, one distinct sheet per
page, and a contact form on each page. One `ArtifactCompiler` compiles all four.
This deliberately does not use a whole-stylesheet-set cache: every set differs.

Before editing the implementation, temporary instrumentation in
`CssStylesheetTransformer::nextRuleBoundary` recorded **108 boundary scans** of
the shared stylesheet; the four-page compile took **0.1630 s**. Instrumenting the
replacement scanner on the same fixture recorded **4 boundary scans**, building
that stylesheet's top-level structure once; a measured run took **0.1701 s**.
These are single native-PHP runs of a tiny fixture, not evidence of an end-to-end
speedup. Startup and unrelated compilation work dominate this fixture.

The contract `tests/contract/shared-stylesheet-structure.php` asserts one actual
structure construction for the shared stylesheet and each of the four distinct
page stylesheets. It hashes the entire result envelope after excluding timing
metrics, including fallbacks, layout/presentation graphs and source reports.
The trunk and fixed SHA-256 are both:

```
d4e54e9432ee44e4202731af3cc022e04e06758fda40756384bfc27b742cdca3
```

With all four modified implementation files restored from HEAD, the new contract
exits 1 with `FAIL: shared stylesheet must be parsed once ...: 0, d4e54e...`.
(The zero means trunk has no compiler-owned structure counter.) Restoring the
implementation makes it pass. Temporary measurement instrumentation was removed.

The structure owner retains source offsets and validity by content hash, not DOM
nodes, per-page query results, source paths, conditions or property filters.
Repeated identical inputs add no entries; memory follows distinct stylesheet
content, including generated stylesheet content, rather than page count. Nested
bodies queried by walkers are also content-addressed. Active ownership is scoped
with `try/finally`, including staged batches, and does not retain the compiler in
process-global state between calls.

The operator will re-measure the real **30-page Wix import after merge**.

AI disclosure: model `cook-heavy` (provider: Roadie), using Pi, read the issues,
implemented the cache, wrote the regression, and ran the measurements and tests.
The harness did not expose a more specific underlying model identity.
