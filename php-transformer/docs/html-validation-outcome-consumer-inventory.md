# HTML Validation Outcome Consumer Inventory

`HtmlCompilation` evaluates block validity, semantic parity, and content round-trip once. `SemanticParityReporter::evaluate()` owns the semantic status, detailed enriched findings, landmark counts, and menu pairing/folding facts. Its `report()` facade projects the unchanged detailed report, while `HtmlCompilation` passes the evaluation's status and findings directly to `Contract\HtmlValidationOutcome`, which it carries in `BlockCompilationOutput`.

| Consumer | Required facts | Source |
| --- | --- | --- |
| `DiagnosticsCollector` | validator finding code, summary, severity, and the diagnostic-specific location field | `HtmlValidationOutcome` |
| `HtmlCompilation` result status | fallback/strict-context acceptance only | unchanged; validator outcomes do not alter existing status policy |
| `ArtifactCompiler`, staged plans, and `WordPressSitePlan` | flattened diagnostics and their severities for artifact acceptance | existing `TransformerResult::diagnostics`, populated from `HtmlValidationOutcome` |
| `HtmlResultComposer` and `ConversionReportProjection` | full detailed validator evidence | unchanged `source_reports.wp_block_validity`, `semantic_parity`, and `content_round_trip`; `SemanticParityEvaluation::report()` supplies semantic parity and it remains projected into `conversion_report` |

The required outcome stores each validator status plus only the original finding keys used to emit diagnostics. Semantic facts come from the evaluation, not from a report map. Values remain mixed because the existing collector preserves any non-null severity and location value; it owns the existing defaults for missing or null `summary` and `severity`. Detailed report-only evidence remains outside the outcome and is serialized only through the existing report projections. Empty and parse-failure HTML results carry `BlockCompilationOutput::empty()` with explicit empty, `not_evaluated` validation outcomes.
