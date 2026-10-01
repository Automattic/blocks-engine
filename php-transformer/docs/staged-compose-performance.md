# Measuring staged composition

`tools/benchmarks/staged-compose-profile.php` uses the existing staged compiler
and its advisory progress callback. It reports receipt validation, reduction,
finalization, plan-view projection, compaction, serialization and independent
plan-replay timing, plus phase memory peaks and source/content identities.

```sh
COMPOSE_RECEIPTS_OUT=receipts.serialized \
  php -d memory_limit=2G tools/benchmarks/staged-compose-profile.php artifact.json

COMPOSE_RECEIPTS_IN=receipts.serialized COMPOSE_REVISION=<candidate-sha> \
  php -d memory_limit=2G tools/benchmarks/staged-compose-profile.php receipts

COMPOSE_SOURCE_ROOT=<archived-baseline-transformer> \
COMPOSE_RECEIPTS_IN=receipts.serialized COMPOSE_REVISION=<baseline-sha> \
  php -d memory_limit=2G tools/benchmarks/staged-compose-profile.php receipts
```

Receipt caches are trusted local benchmark artifacts created by the canonical
prepare/compile APIs. JSON artifact input retains caller capture facts;
directory input intentionally measures static files only. Replay isolates
composition by giving both implementations the same receipts and dependencies.
Run each arm in a fresh process and alternate their order. Optional
`COMPOSE_SAMPLE=1` collects at most 300 one-second stacks without arguments.

Compare `output.canonical_plan_sha256`, `output.canonical_plan_identity` and
`output.compact_view_sha256` across baseline and candidate. Optional
`COMPOSE_PLAN_OUT` and `COMPOSE_COMPACT_OUT` retain full private output for
byte comparisons. The separate `plan_projection_replay_matches` flag compares
the original plan against a later derivation from the final result. Terminal
process counters can change `quality` and `plan_identity` in that later
derivation; the harness reports those differing keys rather than treating that
flag as baseline/candidate equality.

## Exhale scanner experiment

The 12-page capture localized almost all composition time to finalization,
especially repeated CSS scans during compatibility generation and WordPress
plan derivation. Receipt reduction took milliseconds. The scoped optimization
skips ordinary byte runs with native `strcspn`; lexical state transitions still
use the existing scanner for quotes, escapes, comments and grouping bytes.

Two alternating-order PHP 8.5 receipt comparisons measured composition:

| Order | Baseline | Candidate |
| --- | ---: | ---: |
| baseline then candidate | 88.25 s | 55.03 s |
| candidate then baseline | 84.33 s | 23.67 s |

Both arms produced identical canonical-plan and compact-view hashes in every
comparison. Timings vary with host load. Composition peak memory was identical
at 177,389,568 bytes; this is a CPU improvement, not a receipt-retention change.

A separate matched native WordPress/PHP 8.4 pair, with the same SSI revision and
full retained capture, measured composition **77.77 s → 29.26 s**. Both imports
produced canonical plan identity
`5f5a25fdb4aad1dba7db29b1f57e880da0465427054e4c0af74cab0be65a50fe`.
These measured inputs are specific evidence, not a universal performance target.
The source-producing candidate was `c5a35b0f6447411f9f9921746830134fd0b252fe`;
the matched producer baseline was `0a687367465f8822157219882e40d6f16fe23d10`.

Whole-site Exhale acceptance remained **FAILED** in both arms: 308 `core/html`
blocks. The receipt-retention design in
[issue 2410](https://github.com/Automattic/blocks-engine/issues/2410) remains a
separate open problem.
