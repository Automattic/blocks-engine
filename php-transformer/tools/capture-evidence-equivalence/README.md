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
