# Result Envelope Contract

Transformer APIs should return a stable result envelope instead of product-specific arrays.

Required top-level keys for successful transformations:

```json
{
  "schema": "blocks-engine/php-transformer/result/v1",
  "status": "success",
  "components": [],
  "block_types": [],
  "source_reports": {
    "conversion_report": {
      "schema": "blocks-engine/php-transformer/conversion-report/v1",
      "source_format": "html"
    }
  },
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

Artifact compiler callers that materialize WordPress output should read `source_reports.wordpress_site_plan` through `WordPressSitePlanView`. Its `assets` and `writes` are the complete destination-independent write contract, including canonical target paths, payloads, hashes, runtime loading facts, and asset-reference tokens. Downstream materializers own product-specific routing, upload, and receipt policy. Unsafe SVG text is withheld from writes and reported through diagnostics.

The materialization plan also includes product-neutral `routes`, `navigation_links`, and `menus` rows derived from compiled pages and navigation markup. These rows use source and target fields such as `source_path`, `target_path`, `target_slug`, `title`, `label`, `parent_source_path`, `source_relation`, `order`, and `kind`; they do not assign WordPress post IDs, terms, menu locations, or persistence policy. Product adapters remain responsible for deciding whether and how to create pages, menus, navigation entities, route rewrites, and imported assets.

Transformers may expose `source_reports.conversion_report` as a generic `blocks-engine/php-transformer/conversion-report/v1` projection over the same result envelope. It summarizes fallback diagnostics, source paths/selectors, artifact-local asset references, navigation candidates, presentation-gap signals, and metrics without applying product rewrite, import, routing, or visual-parity policy. For artifact compiles, `source_summary` mirrors canonical materialization counts such as `page_count`, `asset_count`, `route_count`, `navigation_link_count`, and `menu_count` so wrappers can validate report consistency without re-deriving write plans from product-specific assumptions.

Visual parity runners should exchange `blocks-engine/php-transformer/visual-parity-report/v1` reports and `blocks-engine/php-transformer/visual-parity-fixture/v1` configs. Those contracts live in `docs/contracts/visual-parity-report.md` and describe source/target render metadata, viewports, screenshot paths, DOM matches, computed-style deltas, optional visual diff metrics, findings, selector evidence, and recommendations without baking in downstream product policy.

`components` contains inspectable component candidates discovered before materialization. `block_types` contains generated block type artifacts promoted from `block.json` roots. Canonical result envelopes must not expose `legacy_mapping`; consumer-owned migration mappers should derive compatibility data at their own boundary.

`blocks` is always a list-shaped array of parsed WordPress block arrays when exposed through `TransformerResult` or `FormatBridge::toBlocks()`. `fallbacks` entries should include stable generic metadata such as `type`, `reason`, `diagnostic_code`, `source_format`, and the preserved source fragment needed by callers to inspect or replay unsupported content.

`context` contains normalized per-call behavior flags. The stable keys are `strict` and `allow_fallbacks`. Callers may pass these keys at the top level of the options array or under `context`.

`provenance` entries identify the transformer-owned operation and may include caller-supplied `source` and `scope` strings. These fields are generic metadata for wrappers and product integrations; canonical fixtures should avoid downstream package names.

`metrics` is a generic counters/timing envelope for wrappers and observability. Transformers should populate `input_bytes`, `block_count`, `fallback_count`, `diagnostic_count`, `transform_duration_ms`, and `output_bytes` when the values are available without changing conversion behavior. `block_count` counts parsed block arrays recursively when blocks are produced; artifact-only compilers may report `0` until they materialize parsed block arrays. Product-specific compatibility reports should map from this envelope instead of relying on package-specific events.

Callers may ignore keys they do not need. The package should keep these names stable enough for consumers to share contract fixtures while the draft API settles.
