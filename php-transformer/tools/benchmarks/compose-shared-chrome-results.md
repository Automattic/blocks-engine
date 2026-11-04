# Distinct pages with shared chrome

Fixture: `tests/contract/compose-shared-chrome-scaling.php --artifact N`.
Each page has distinct title, article text and page class, the same 40-link
header/nav and footer, and one stylesheet. Generate the artifact, then pass it
to `tools/benchmarks/staged-compose-profile.php`. `COMPOSE_SOURCE_ROOT` selects
the comparison source tree while keeping dependencies and the driver identical.

Before is the reviewed branch plus merged #4183 timing instrumentation; after
includes this review's changes. PHP 8.5.4, three fresh-process samples per size,
median wall time; peaks are allocated MiB during compose. Preparation is excluded
from compose time. These are small synthetic sites, not a production speed claim.

| Routes | Compose before → after (ms) | Compose peak before → after (MiB) |
| --- | --- | --- |
| 16 | 235.14 → 237.32 | 48 → 48 |
| 64 | 817.19 → 836.56 | 72 → 72 |
| 256 | 3351.06 → 3375.90 | 170 → 170 |

Median `compose.*` exclusive step times (ms), before → after:

| Step | 16 | 64 | 256 |
| --- | --- | --- | --- |
| receipt_validation | 10.69 → 10.91 | 37.65 → 38.09 | 162.92 → 166.08 |
| receipt_reduction | 0.13 → 0.14 | 0.35 → 0.38 | 1.66 → 1.73 |
| artifact_assembly | 38.68 → 40.73 | 122.18 → 122.27 | 478.54 → 486.87 |
| author_stylesheet_projections | 0.02 → 0.02 | 0.03 → 0.04 | 0.11 → 0.11 |
| author_stylesheet_projections.route_documents | 2.57 → 2.70 | 9.63 → 8.84 | 46.82 → 46.80 |
| author_stylesheet_projections.route_variants | 0.92 → 1.22 | 3.28 → 4.09 | 11.62 → 16.03 |
| author_stylesheet_projections.route_budget | <0.01 → <0.01 | <0.01 → <0.01 | 0.01 → 0.01 |
| serialization | 0.04 → 0.04 | 0.05 → 0.04 | 0.05 → 0.06 |
| compaction | <0.01 → <0.01 | <0.01 → <0.01 | <0.01 → <0.01 |
| from_compiler_input | 179.31 → 177.74 | 636.22 → 643.89 | 2589.79 → 2585.13 |
| result_composition | 2.66 → 3.15 | 5.40 → 5.50 | 57.96 → 58.89 |

Canonical plan, compact view and serialized-block SHA-256 values match before
and after for every sample. Overall time and peak are essentially unchanged:
plan construction dominates this fixture, and its identical lowered projections
usually eliminate deltas before cascade matching. The contract therefore also
forces the actual `rulesAbsentFrom` cascade path on the same distinct-page DOMs,
so it cannot pass merely because that production optimization skipped matching.

At 4/16/64 routes, actual shared selector-subject evaluations are 40/40/40;
page-unique evaluations are 4/16/64; production rule collections are 3/3/3.
Whole-document equality cannot explain these results. On trunk the contract
fails: with only a ComposeFacts counter injected immediately before the existing
matcher invocation (no algorithm changes), four routes perform 160 shared
subject evaluations, failing `Shared chrome must be matched once: 160`.
The reviewed branch also fails the new assertion before restructuring.

## Why some proofs remain route-local

An element's tag/attributes and ancestor context completely determine supported
child/descendant selectors without structural pseudo-classes. Those immutable
subjects are shared across documents, matched once, then scattered into each
route's element-id set. Cascade intersections still use real route-local ids:
sharing match work must never imply two elements compete across routes.
Sibling combinators, positional/functional pseudo-classes and escaped selectors
can observe page-specific siblings or descendants, so they retain the complete
route-local proof. Unsupported selectors still return unknown, never evidence
for dropping a rule. State pseudo-classes are reduced to the same conservative
structural superset as before.
