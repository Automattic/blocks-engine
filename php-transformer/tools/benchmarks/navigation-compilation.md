# Navigation compilation (#7085)

`navigation-compilation.php` generates 2,978 body elements: 16 menus with
hamburger controls across folded desktop/mobile document variants, and 900
ordinary content sections. It compiles through ArtifactCompiler, including the
WordPress site plan. Pass a JSON path to record/compare exact serialized blocks
and site-plan bytes; `--contract` checks the pre-change output digest. The contract
runner invokes this generated fixture. Set `COMPILE_PROFILE` to save Excimer
collapsed wall-clock stacks (10 ms). Set `BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT`
to compare another checkout using the identical generator.

## Measurement

Paired runs on the same host: **21.062 s before, 11.598 s after (44.9% faster)**.
Host contention varies substantially; timings are evidence, not a CI assertion.
Both runs produced digest
`289917657a7db5c722648293c277e0fe41a08600d2e5cc81011d936e1910a2a2`
for serialized blocks plus the full WordPress site plan. Baseline is the parent
checkout, not the older version cited in the issue.

Excimer inclusive shares (2,105 baseline samples; 1,159 candidate samples):

| Frame | Before | After |
|---|---:|---:|
| NavigationPattern::recognizeNavigation | 9.0% | 13.5% |
| NavigationToggleSuppressor::navigationToggleControl | 4.2% | 1.8% |
| SourceDom::documentVariantRoot | 1.5% | 0.4% |
| SourceStyleResolutionState::matchingRules | 5.1% | 7.5% |
| SourceStyleResolutionState::remembered | 5.2% | 6.7% |
| CssStylesheetTransformer::renderTree | 2.6% | 1.5% |
| AuthorStylesheetMaterialization::projections | 2.4% | 1.2% |
| DocumentTitles::withoutTitleHeadingChrome | 22.8% | 0.3% |

Shares overlap and are relative to different totals. The last frame was the
largest additional repeated work: the same absent entry-body marker was sought
on every heading's preceding axis. One document-wide existence check avoids it.

Separate instrumented copies (entry counters, not sampling estimates):

| Work | Before | After |
|---|---:|---:|
| findNavigationToggleControl | 144 | 16 |
| documentVariantRoot calls | 181,738 | 67,482 |
| uncached variant ancestor walks | 181,738 | 2,978 |
| recognizeNavigation entries | 3,860 | 3,860 |
| matchingRules entries | 113,414 | 113,414 |
| remembered entries | 124,024 | 23,471 |
| renderTree entries | 195 | 195 |
| projections entries | 1 | 1 |

Recognition entry counts stay equal: the structural gate is inside recognition.
Selector matches were already memoized; the new fast path avoids closure
allocation and the generic memo wrapper on hits. Render callbacks must still
execute (they publish bindings), so only stable render syntax is memoized.
Projection memoization also avoids duplicating binding/content-box side effects
if a consumer asks twice; this fixture asks once.

All new caches belong to the compilation's existing collaborators. The variant
memo belongs to the explicitly installed/released source document index; detached
nodes bypass it. Toggle results distinguish mirror-building mode and reset when
relationships settle, folds are released, or source recognition is invalidated.
No process-wide persistent cache is added.
