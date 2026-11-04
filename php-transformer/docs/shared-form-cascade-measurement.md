# Shared form cascade analysis (#4237)

Reduced regression: `tests/fixtures/shared-form-cascade.html`, compiled as two
pages, each containing two forms and linking the same stylesheet. The contract
adds 150 unrelated style rules to the shared sheet so form retention is exercised.
This is intentionally not a substitute for the captured Wix import.

## Before and after

Before implementation, temporary instrumentation at `CssRuleAnalyzer::analyze`
recorded the full inputs on trunk: **34 calls, four distinct stylesheet/property
inputs**. They comprised 14 presentation, 14 custom-property, four layout and two
visibility analyses. After implementation, the CSS owner builds **four immutable
indexes**, and form consumers query them with their existing scope and budgets.
Selector matches are also shared by form consumers within a compilation, never
in the process-wide immutable CSS cache.

Five fresh-process runs of the reduced compilation:

| | Runs (ms) | Median (ms) |
|---|---|---|
| Before | 228.021, 224.946, 218.557, 223.075, 221.131 | 223.075 |
| After | 223.905, 234.632, 222.744, 220.971, 227.275 | 223.905 |

The fixture demonstrates removal of repeated analysis preparation, not a timing
improvement: its median is effectively unchanged. Scope-specific retention must
still be queried because scope affects rule budgets, block exits and diagnostics.

## Regression evidence

`tests/contract/shared-form-cascade-analysis.php` asserts four builds and hashes
the entire compiled result (only wall-clock telemetry is excluded). Its SHA-256,
measured on trunk and reproduced after the fix, is
`70bfc6fc12c7b2fc63071c397692bd4a64f3ed5d6c968b3f77267d6ebec48d3b`.
This includes fallbacks, layout graphs, presentation graphs and receipts.

The test fails on unmodified trunk because `indexBuilds()` does not exist. With
only a temporary counter adapter added to trunk (increment at the existing
`analyze` entry point), it fails the work assertion: 34 rather than four. The
adapter and measurement instrumentation were removed. The fixed test passes,
as does the full php-transformer test suite.

The operator will re-measure a real **30-page Wix import after merge**. No
real-import speedup is claimed from this reduced fixture.

Compatibility: additive CSS index/query API; the existing analyzer entry point
remains a compatibility delegate. Existing output and bounded-query contracts
are unchanged; the old per-caller analysis and analyzer replay paths are removed.

AI disclosure: model `cook-heavy` (provider `roadie`; underlying model identity
not exposed by this session), via Pi, read
the issues and code, instrumented and measured the fixture, implemented the shared
CSS index and form match-cache wiring, wrote the regression test and ran tests.

Fixes #4237.
