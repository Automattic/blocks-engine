# Capture-evidence equivalence

Proof that moving the transformer to the producer-neutral capture vocabulary
([Capture evidence](../../docs/contracts/capture-evidence.md)) lost no
behavior. The gate is `tests/contract/capture-evidence-equivalence.php`, run by
`composer test`.

## What is compared

- **Corpus** (`tests/fixtures/capture-evidence-equivalence/corpus.json.gz`):
  every outermost call to `HtmlTransformer::transform`,
  `ArtifactCompiler::compile`/`compileFragment`, `ArtifactNormalizer::normalize`
  and the five captured-interaction projectors made by the pre-move test suite
  (`composer test` plus every other PHP test script) whose input carried the
  retired producer-specific capture names. Inputs are stored verbatim.
- **Rename**: `CaptureEvidenceEquivalence::rename()` is the published rule. It
  is applied to every string and key, inside `content_base64` payloads and
  embedded JSON, and keeps each declared `htmlBytes` consistent with its
  renamed `html`, as a producer writing the new names would.
- **Expectation** (`expected.json`): the pre-move source commit, with the same
  rule applied to its `src/` (and the two recognized runtime digests swapped
  for the currently published ones), replays each renamed case. The
  gate requires this checkout to produce a byte-identical compile result,
  WordPress site plan, projected file set or exception for every case. Only
  the checkout path, `*_duration_ms` wall-clock timings and the
  `engine_version` release stamp are neutralized.

## Deliberate fidelity changes

These cases no longer match the pre-move replay. Their expectations record this
checkout's output, and each difference was checked against the pre-move
replay by case:

- **Runtime retirement requires the published body.** Every retirement path
  now checks the runtime digest, and a page that carries an unrecognized
  runtime body keeps that runtime and its wiring. The corpus inputs below carry
  short stand-in bodies, not the published ones, so their pages are now left
  as captured: no native dialog, gallery or submenu replacement, no retired
  script, and no per-state projector diagnostics.
  - Navigation submenu retirement of a stand-in disclosure body:
    `07f8a0bd`, `1e87fd71`, `28e1a0f1`, `2f545afa`, `3e283668`, `647b8b53`,
    `bab702ca`.
  - Native dialog projection plus retirement of a stand-in disclosure body:
    `3317930e`, `461f0a83`, `5eccb541`, `f67e5ca6`.
  - Native gallery projection plus retirement of a stand-in gallery body:
    `0bf7a2ea`, `12d3ff94`, `3b0aed43`, `62d3b1f8`, `8d155e3b`, `90115e60`,
    `bcd4e29b`.
  - Stand-in disclosure body, state diagnostics only
    (`captured_dialog_trigger_unmatched`, `captured_dialog_unsafe_or_truncated`):
    `6cdada86`, `6ed86c41`, `b913b3b4`, `eaad29ef`, `fcc75319`, `feef8efc`.
- **Native dialog view script.** The captured-dialog view script replays
  `aria-*`/`data-*` ancestor bindings. Only the emitted script text and the
  hashes and sizes derived from it change in `0a9c1bea`, `15082c2d`,
  `21e5cdbf`, `2915d21f`, `37284b0e`, `55b724ac`, `7a7af898`, `7c2d1217`,
  `927aa368`, `944dd63d`, `df9c24a8`, `e5ea5743`.
- **Declared block menu items stack in core flow** (#2726). A list
  navigation whose items all declare a block-level display, with no display
  on the nav or its list, records `"layout":{"type":"default"}`. That adds
  the attribute in `46f79246` and `e2e35d0c`. The extra display lookups only
  move the style-rule candidate cache counters in `2448c3c2`, `2890e8f1`,
  `448ead36`, `a5a08a86`, `b550beb0`, `c7fff215` and `f025cf28`.

When regenerating from the pre-move commit, re-record these cases from the
checkout that introduced them, and only after checking the difference again.

## Regenerating

From `php-transformer/`, with disposable worktrees of the pre-move commit:

```sh
# 1. Harvest: wrap the entry points of a disposable checkout so they call
#    be_capture_evidence_harvest() from harvest-hook.php, then run its suite.
BE_CAPTURE_EVIDENCE_HARVEST_DIR=/tmp/harvest composer test   # in the wrapped checkout
php tools/capture-evidence-equivalence/build-corpus.php /tmp/harvest <commit>

# 2. Expectation: rename a second, unmodified disposable checkout, then replay.
php tools/capture-evidence-equivalence/rename-checkout.php <checkout>/php-transformer
php tools/capture-evidence-equivalence/baseline.php <checkout>/php-transformer <commit>
```

## Cross-name report

`semantic-report.php <unmodified pre-move php-transformer root>` compares the
pre-move source on the original names with this checkout on the renamed
input, masking what a longer name changes by construction (byte counts,
offsets, content digests and anything keyed by them, excerpt cut points). It
is a report, not a gate.
