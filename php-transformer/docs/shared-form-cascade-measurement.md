# Shared form cascade analysis (#4237)

## Retained optimization

The original two-page, four-form fixture prepares four distinct immutable CSS
indexes instead of trunk's 34 analysis preparations. Scope retention, property
lists, condition-depth limits and query budgets remain consumer-specific.

CSS indexes contain no DOM nodes or selector callbacks. DOM match caches are now
local to the original build scopes again: layout and its nested presentation
consumers share a cache during one build, but subsequent builds reset it. The
compilation-wide form cache and step-detector cache wiring have been removed.
Source lowering can change the truth of relational selectors; an immutable CSS
index does not make the DOM immutable.

## Review evidence and reduced regression

The review reports a normalized native replay of `/giving`: trunk versus trunk
had zero differing lines, whereas the previous branch had 1,371. Added variants
used device-scoped `div:has(>input)` inside `@media (forced-colors:active)`, with
`border:1px solid CanvasText!important`. The real dumped inputs are not present
in this checkout, so this revision does not claim a rerun of that capture.

`tests/fixtures/shared-form-forced-colors.html` contains desktop/mobile document
scopes and form-control containers. The contract primes a match cache, lowers
inputs into spans, and compares the resulting layout/presentation graph with an
equivalent fresh builder. With the previous shared-cache builder restored, this
assertion fails (exit 255); with build-local matching it passes. This reproduces
the stale relational-match failure without weakening condition handling.

The contract also compares complete compiled artifacts against a cold CSS-cache
compilation, including fallbacks, diagnostics, receipts and graphs. Only timing
fields and run-random `blocks-engine-(richtext|control)-<hex>` markers are
normalized. The hardcoded output hash has been removed.

A separate fresh-process replay of the reduced fixture against trunk `02a185b6`
and this revision produced byte-identical normalized JSON (zero differing lines).
The unlowered fixture alone also matched before the fix; the primed-cache/lowering
assertion is the regression that specifically fails on the previous branch.

## Measurements

Original four-form fixture, five fresh processes (historical measurements):

| | Median compilation |
|---|---:|
| Trunk | 223.075 ms |
| Initial shared-cache branch | 223.905 ms |

Review-provided measurements for eight real pages: 113.5 s to 96.3 s before this
revision; `/youth` 22.9 s to 14.6 s and `/womens-ministry` 25.6 s to 15.7 s, with
lower peak memory. These are not measurements of this revised cache lifetime.

Current reduced forced-colors replay, five fresh PHP processes (whole-process
wall time, including startup and JSON serialization):

| | Runs (seconds) | Median | Peak RSS range |
|---|---|---:|---:|
| Trunk | .16, .15, .15, .16, .17 | .16 s | 77,408–77,888 KiB |
| Revised branch | .16, .17, .16, .17, .16 | .16 s | 78,580–79,012 KiB |

No reduced-fixture speedup or retained real-import speedup is claimed. Real-input
replay should be repeated before promotion when the captured inputs are available.
The full php-transformer Composer suite passes.

Compatibility: additive immutable CSS index/query API; existing analysis entry
points and output contracts remain unchanged. DOM cache sharing is intentionally
restricted to the original stable scopes.

AI disclosure: Homeboy/Pi assisted with code inspection, implementation, reduced
regression fixtures, measurements and test execution. The original implementation
was also AI-assisted (model `cook-heavy`, provider `roadie`).

Fixes #4237.
