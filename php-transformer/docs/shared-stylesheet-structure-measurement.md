# Shared stylesheet structure replay

## Review correction

The original offset cache used ambient static activation and repeatedly SHA-256
hashed alternating stylesheet strings. Its hardcoded compiled-output hash was
invalid because richtext/control markers are run-random. Neither approach remains.

The compiler now owns source-preserving parsed units (prelude, body, source
positions, malformed tails and validity). Stylesheet assets carry the owner into
analysis and projection; staged workers explicitly share that owner. Each source
is SHA-256 identified at ingestion once. The lookup uses PHP string table keys,
whose string hashes PHP retains, rather than repeatedly hashing whole strings
cryptographically. Actual content is authoritative, not potentially stale source
provenance hashes. Nested bodies are separately interned. Generated standalone
CSS uses the streaming scanner, avoiding unnecessary retained trees.

## Reduced fixture and measurements

`tests/fixtures/contract/shared-stylesheet-structure/shared-css.php` reduces the
native replay shape to 22 shared sheets totaling 964,482 bytes, nested layer/media
blocks, and page-local CSS. Eight pages alternate those sheets, as the reported
worker did. This is synthetic, not the unavailable real Wix capture.

Native PHP measurements in this workspace:

| Eight-page full compile | Wall | Peak allocated memory |
| --- | ---: | ---: |
| trunk ab5a72ff | 1.6542 s | 180.7 MB |
| original PR e206237c | 2.6996 s | 180.7 MB |
| revised, shared compiler | 1.6893 s | 182.7 MB |
| revised, fresh compiler per page | 1.7488 s | 182.7 MB |

The revision is 37.4% faster than the reviewed branch and 3.4% faster than the
equivalent fresh path. It is still 2.1% slower than trunk on this small full-compile
fixture; this is not evidence of a real-import end-to-end speedup. A separate
alternating-sheet transformer replay measured 0.0097 s uncached versus 0.0058 s
cached (40% faster), including cold construction. Timings are observations, not
flaky test assertions.

The benchmark writes normalized full output for direct byte comparison. Revised
shared, revised fresh and trunk outputs compared equal, with only metrics/timing
fields removed and `blocks-engine-(richtext|control)-<hex>` normalized. No expected
output digest is embedded in the test. The contract also compares complete form
compiles against fresh compiler instances, and verifies one parse per shared or
page-local source. The large replay retains 74 distinct source entries (22 sheets,
44 nested bodies, eight page sheets); repeating the pages leaves that count
unchanged. Memory is retained by distinct source bytes, not page invocations.

The new contract was run against the original PR with its own Composer autoloader
and failed on the explicit no-ambient-activation requirement. The old cache also
cannot populate the per-instance reduced replay without global activation.

AI disclosure: OpenAI's coding assistant via Pi read the review, implemented the
revision, added fixtures and comparisons, ran measurements, and drafted this note.
