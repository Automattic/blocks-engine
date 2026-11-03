# Gallery compilation scaling (#4096)

The generated, unbranded fixture in `tests/fixtures/generated-gallery.php` repeats
capture-shaped media, inline geometry, empty hover wrappers and unique viewport
IDs. `php tools/benchmarks/gallery-compilation.php` compiles 25, 50, 100 and 200
items through `HtmlTransformer::transform`. Set `GALLERY_OUTPUT_DIR` to an existing
directory to save serialized blocks for byte comparisons.

Native PHP 8.5, same workspace and process settings, seconds (fixture generation
and block serialization excluded):

| Items | Before | After | Before ms/item | After ms/item |
| --- | ---: | ---: | ---: | ---: |
| 25 | 0.436 | 0.369 | 17.4 | 14.8 |
| 50 | 0.818 | 0.605 | 16.4 | 12.1 |
| 100 | 2.049 | 1.165 | 20.5 | 11.7 |
| 200 | 6.224 | 2.405 | 31.1 | 12.0 |

All four serialized outputs compare byte-for-byte equal before/after. The
regression pins the baseline SHA-256 at 50 and 200 and allows 50% headroom in
per-item cost, rather than imposing a machine-specific deadline.

## Profile

A phpspy sampling run at 200 items identified repeated `DOMXPath::query` calls
from `InteractiveSurface::classify`, reached particularly through
`SourceElementClassifier::hasCarouselIdentity`. Instrumenting `classify` with an
inclusive hrtime wrapper on the baseline measured **1.955 seconds** of a 7.856s
instrumented compile. The XPath comment claimed an indexed lookup, but each
query actually searched the entire document for an `aria-controls` reference.

The same sampling run found `AuthoredCarouselBlockGenerator::convert` spending
time in `sharesCarouselIdentity`: for every unsuccessful gallery candidate it
walked every element looking for responsive counterparts with the same ID.
`SourceDom::documentReferencesFragmentId` and
`SourceDom::isBoundCapturedDialogControl` also repeated full-document walks.
Finally, `LayoutGeometryState::cssForSerializedBlocks` sampled prominently
(341 leaf samples, versus 839 for XPath): it scanned the full serialized page
once per generated rule. Sampling overhead and occasional invalid frames mean
these counts are diagnostic, not exact wall-time attribution.

The fix builds reverse reference and duplicate-ID indexes for the stable source
conversion phase, releases them in `finally`, and tokenizes serialized geometry
identities once. Standalone mutable-DOM helpers retain live-query semantics.
No Static Site Importer code participates in this reproduction.

AI assistance: the Pi coding agent (model identifier `cook-heavy`) generated the
neutral fixture, measured and profiled compilation, implemented the indexes and
regression tests, and drafted this evidence.
