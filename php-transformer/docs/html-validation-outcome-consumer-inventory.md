# HTML Validation Outcome Consumer Inventory

## Report policy (issue #4170)

`validation_evidence` defaults to `full` (unchanged report contents); `compact`
retains its existing semantic-inventory omission. The explicit `none` level omits
`wp_block_validity`, `semantic_parity`, `content_round_trip`,
`source_media_retention`, and `dead_projected_selectors` from `source_reports`.
No report evaluation is interpreted as a pass. Required facts and diagnostics
are computed identically at every level. The conversion-report schema retains
its existing empty semantic-parity projection when no report is supplied.

| Evaluation in `HtmlCompilation::transform()` | Real consumers | `none` behavior |
| --- | --- | --- |
| Block validity | `HtmlValidationOutcome` → `DiagnosticsCollector`; diagnostics reach `ArtifactCompiler` acceptance and staged reductions, then `WordPressSitePlan` | Evaluate; skip report projection |
| Semantic parity | Same required outcome/diagnostic path; report additionally projected by `HtmlResultComposer` and `ConversionReportProjection` | Evaluate; skip report projection |
| Content round-trip | Same required outcome/diagnostic path | Evaluate; skip report projection |
| Source media retention | Only `HtmlResultComposer`'s report; no outcome, acceptance, staged transport or plan consumer | Skip evaluation and report |
| Dead projected selectors | Findings appended directly to diagnostics, consumed by artifact/staged acceptance; not part of `HtmlValidationOutcome` | Evaluate; skip report projection |
| Design usage | `BlockCompilationOutput.designUsage` → artifact documents and staged reductions → `WordPressSitePlan`'s `DesignSystem` / `PresetBinding`; affects canonical assets and plan identity | Mandatory, including collection |
| Editability | `BlockCompilationOutput.editabilityReport` → artifact documents, staged reductions, aggregate `EditabilityPolicy`, and plan's required policy / template-surface report | Mandatory, including report-shaped facts |

The issue's Static Site Importer audit states that `includes/` and `lib/` never
read the five `*_report` inputs above. SSI still needs required diagnostics,
editability and design usage through compiled documents/plans; absence of report
reads is not permission to remove their acceptance inputs. SSI source is not
vendored in this checkout.

Production fragment callers can pass `array('validation_evidence' => 'none')` to
`HtmlTransformer`, `FormatBridge`, or `ArtifactCompiler::compileFragment()`.
Full-artifact/staged callers can construct `ArtifactCompiler` with the existing
policy type, e.g. `validationEvidence: ValidationEvidencePolicy::fromOptions(
array('validation_evidence' => 'none'))`. Prepared-page workers inherit the same
policy. Report detail is not part of receipt identity: terminal reductions retain
the same required facts whichever detail level produced them.

The classified invalid-option throw remains a programmer error; its fixture key
and message now include `none`. No throw site or failure class was removed.

## Timing

Measured on the six HTML pages in `fixtures/websites/13-realistic-small-business`
using `tools/benchmarks/validation-evidence.php`, three fresh-process runs per
level, alternating full/none. Timings cover transform only (not file reads or
result serialization); no linked-CSS fetch. Median of run-wide mean compile time:
**185.224 ms/page full, 183.249 ms/page none** (1.974 ms / 1.07% lower).
Most validation work is mandatory, so this is a small, noise-sensitive saving.

| Page | Full ms (median) | None ms (median) |
| --- | ---: | ---: |
| events.html | 267.587 | 254.421 |
| index.html | 166.500 | 161.601 |
| plant-care.html | 159.906 | 163.917 |
| shop.html | 138.178 | 139.914 |
| visit.html | 176.586 | 179.810 |
| workshops.html | 190.665 | 193.360 |

## Required outcome boundary

`HtmlCompilation` evaluates block validity, semantic parity, and content round-trip once. `BlockValidityValidator::evaluateBlocks()` produces structural `BlockValidityEvaluation` facts and `validateBlocks()` projects its public report; `Runtime::evaluateBlockSerialization()` parses string input once and returns the evaluation merged with canonical save-shape findings. Its `report()` facade projects the unchanged detailed report. `SemanticParityReporter::evaluate()` and `ContentRoundTripReporter::evaluate()` each own their status and findings. `ValidationEvidencePolicy` projects semantic parity as full evidence or compact evidence, which omits only `landmarks` and `navigation_menus` and declares that omission in `evidence`; validation outcomes always retain the same statuses and findings. `HtmlCompilation` passes all evaluations' explicit status and findings to the implementation-independent `Contract\HtmlValidationOutcome`, which it carries in `BlockCompilationOutput`.

| Consumer | Required facts | Source |
| --- | --- | --- |
| `DiagnosticsCollector` | validator finding code, summary, severity, and the diagnostic-specific location field | `HtmlValidationOutcome` |
| `HtmlCompilation` result status | fallback/strict-context acceptance only | unchanged; validator outcomes do not alter existing status policy |
| `ArtifactCompiler`, staged plans, and `WordPressSitePlan` | flattened diagnostics and their severities for artifact acceptance | existing `TransformerResult::diagnostics`, populated from `HtmlValidationOutcome` |
| `HtmlResultComposer` and `ConversionReportProjection` | selected validation evidence detail, full by default | `source_reports.wp_block_validity` and `content_round_trip` remain complete; `semantic_parity` follows the explicit detail policy, and `conversion_report.semantic_parity` mirrors that same projection |

The required outcome stores each validator status plus only the original finding keys used to emit diagnostics. Block-validity, semantic parity, and content round-trip facts come from their evaluations, never report maps. Values remain mixed because the existing collector preserves any non-null severity and location value; it owns the existing defaults for missing or null `summary` and `severity`. Detailed report-only evidence remains outside the outcome and is serialized only through the existing report projections. Empty and parse-failure HTML results carry `BlockCompilationOutput::empty()` with explicit empty, `not_evaluated` validation outcomes.
