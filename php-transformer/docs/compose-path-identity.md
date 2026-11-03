# Compose asset identity regression (#4076)

## Confirmed root cause

Page-envelope normalization re-extracts inline styles from its HTML even when
that stylesheet is already in the shared plan. `compose()` content-addressed the
merged files for validation, but discarded that assembly for terminal receipts.
`reduceCompiledReceipts()` independently concatenated shared and page files,
without coalescing inline paths and their occurrence records. Finalization thus
received the same stylesheet path repeatedly. Each entry ran the complete
all-owner projection, and reserved-path collisions gave repeated page deltas
fresh `-N` suffixes.

Terminal reduction now uses the normalizer's inline identity merge before source
ordering, preserving all inline occurrence metadata. The projection boundary
also keys files by path. A deterministic counter records distinct projected
source paths rather than measuring elapsed time.

## Deterministic fixture measurements

JSON sizes below are byte lengths of compact `json_encode` output, not heap
sizes or pretty-printed checkpoints. Baseline is the checkout before this fix.

| Fixture | Routes | Before assets / bytes | After assets / bytes |
| --- | ---: | ---: | ---: |
| Repeated-path projection with namespace fallback and per-owner deltas | 3 | 12 / 5,545 | 4 / 1,845 |
| Same projection | 6 | 42 / 20,125 | 7 / 3,345 |
| Full staged inline compose, complete site plan | 3 | 5 / 60,808 | 5 / 57,972 |
| Same staged compose | 6 | 8 / 92,256 | 8 / 80,915 |

The original staged fixture's simple rules were lowered into editor static-state
assets. It now uses image bridge rules plus `@namespace` to force non-lowered
page deltas and generates the complete theme bootstrap. Before the ownership
fix, each delta inherited every route's occurrences from its shared parent.
Page-file creation now filters occurrence records to the owner, and explicit
page compilation ownership takes precedence over inline references in plan
scoping. The shared parent retains every route's occurrences.

| Forced-delta staged fixture | Before functions.php bytes | After functions.php bytes | Before / after enqueue lines | Before / after presentation entries |
| --- | ---: | ---: | ---: | ---: |
| 3 routes | 25,779 | 22,023 | 12 / 8 | 14 / 10 |
| 6 routes | 55,551 | 32,076 | 39 / 14 | 44 / 19 |

These measurements compare the deduplicated compose checkout before the
ownership fix with the same fixture after it. Assertions require exactly one
owner scope, occurrence, instance, presentation entry and frontend enqueue per
delta, all parent occurrences, linear bootstrap counts, one source-path
projection, and partition-order-independent output.

The projection-boundary fixture forces page deltas with `@namespace`,
asserts exactly one shared sheet plus one delta per owner, and compares output
byte-for-byte against a single source file regardless of duplicate count.

Resolution indexes plan tokens once for the write loop and publication targets.
Payload scanning selects distinct used tokens; CSS custom-property and normal
relative URL substitution share that subset. Unknown tokens still fail. The
500-write/token fixture asserts one selected token per write (even when used
twice) and unchanged relative URL output, with no timing threshold.
