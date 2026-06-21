# Result Envelope Contract

Transformer APIs should return a stable result envelope instead of product-specific arrays.

Required top-level keys for successful transformations:

```json
{
  "schema": "blocks-engine/php-transformer/result/v1",
  "status": "success",
  "components": [],
  "block_types": [],
  "source_reports": {},
  "legacy_mapping": {},
  "blocks": [],
  "serialized_blocks": "",
  "documents": [],
  "assets": [],
  "diagnostics": [],
  "fallbacks": [],
  "provenance": [],
  "coverage": [],
  "context": {},
  "metrics": {
    "input_bytes": 0,
    "block_count": 0,
    "fallback_count": 0,
    "diagnostic_count": 0,
    "transform_duration_ms": 0.0,
    "output_bytes": 0
  }
}
```

`status` is one of `success`, `success_with_warnings`, or `failed`. Artifact compiler callers should use `source_reports.artifact` for normalized input metadata such as entry path, accepted/rejected file counts, MIME/kind/role counts, and source HTML metrics.

Artifact compiler callers that need a compiled site view should read `source_reports.compiled_site`. It exposes a generic `blocks-engine/php-transformer/compiled-site/v1` report with normalized `pages`, per-page `metadata`, full page `block_markup`, `assets`, `template_parts`, `visual_repair`, and `theme` buckets for stylesheets, scripts, fonts, images, template parts, and generated block types. Product adapters should map from this report plus the top-level `documents` and `assets` arrays instead of depending on product-specific artifact semantics.

Artifact compiler callers that write compiled output should read `source_reports.materialization_plan`. Its `assets` list is product-neutral write intent: each row may include `source`, `path`, `target_path`, `kind`, `role`, `intent`, `media_type`, `mime_type`, `bytes`, `binary`, `content_encoding`, `content`, `content_base64`, and `hash`. `target_path` defaults to the normalized artifact-relative `path`; downstream materializers own any product-specific routing, upload, or rewrite policy. Text rows expose `content` with `content_encoding: "text"`; binary rows expose `content_base64` with `content_encoding: "base64"` when the payload is available. Unsafe SVG text is withheld from write rows and reported through diagnostics.

Transformers may expose `source_reports.conversion_report` as a generic `blocks-engine/php-transformer/conversion-report/v1` projection over the same result envelope. It summarizes fallback diagnostics, source paths/selectors, artifact-local asset references, navigation candidates, presentation-gap signals, and metrics without applying product rewrite, import, routing, or visual-parity policy.

`components` contains inspectable component candidates discovered before materialization. `block_types` contains generated block type artifacts promoted from `block.json` roots. `legacy_mapping` is transitional metadata for consumer-owned migration mappers and is not a long-term package feature commitment.

`blocks` is always a list-shaped array of parsed WordPress block arrays when exposed through `TransformerResult` or `FormatBridge::toBlocks()`. `fallbacks` entries should include stable generic metadata such as `type`, `reason`, `diagnostic_code`, `source_format`, and the preserved source fragment needed by callers to inspect or replay unsupported content.

`context` contains normalized per-call behavior flags. The stable keys are `strict` and `allow_fallbacks`. Callers may pass these keys at the top level of the options array or under `context`.

`provenance` entries identify the transformer-owned operation and may include caller-supplied `source` and `scope` strings. These fields are generic metadata for wrappers and product integrations; canonical fixtures should avoid downstream package names.

`metrics` is a generic counters/timing envelope for wrappers and observability. Transformers should populate `input_bytes`, `block_count`, `fallback_count`, `diagnostic_count`, `transform_duration_ms`, and `output_bytes` when the values are available without changing conversion behavior. `block_count` counts parsed block arrays recursively when blocks are produced; artifact-only compilers may report `0` until they materialize parsed block arrays. Product-specific compatibility reports should map from this envelope instead of relying on package-specific events.

Callers may ignore keys they do not need. The package should keep these names stable enough for consumers to share contract fixtures while the draft API settles.
