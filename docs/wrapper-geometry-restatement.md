# Wrapper geometry restatement

Refs #7471. Implements #7517 on the existing selector-restatement proof.

Before: a `.col` Group containing an `.inner` Group survives when their
border boxes differ. After: a paint-free child whose border box expanded by
exactly the wrapper padding equals the wrapper border box at every captured
viewport takes the wrapper classes and editor spacing styles. Width,
max-width and auto margins continue to be transported by the verified author
rules; centered max-width plus inset is covered by the contract test. Child
sizing declarations, contested declarations, percentage dependencies and
unexplained geometry retain the wrapper. Opacity moves only onto a child
with neutral observed opacity and no competing opacity declaration.

## Capture classification probe

A narrow read-only probe of the existing `~/cal/wd2/*-cap` snapshots classified
all source `div` elements with a `child_box_differs` result (one per element,
first failing viewport; desktop sidecars, excluding separate mobile files):

| Capture | Inset | Width/centering | Min-height | Other |
| --- | ---: | ---: | ---: | ---: |
| squarespace-bedford | 192 | 0 | 0 | 78 |
| sdnunca | 15 | 0 | 1 | 7 |

These are source-box counts, **not** the original issue's real-import refusal
histogram: earlier conversion guards and already-collapsed source chains
change that population. Computed margins may contain resolved pixel values
rather than `auto`, so the width/centering category is conservative. An
updated real-import histogram and visual corpus A/B remain external checks;
no claim of visual equivalence or material corpus reduction is made here.

## Focused verification

`cd php-transformer && php tests/contract/restated-wrapper-fold.php`

Covers inset, centered max-width inset, painted-child refusal, unexplained
geometry refusal, opacity transfer and nested-opacity refusal, alongside the
existing selector and identity regression cases. Full Composer verification
is delegated to Homeboy.
