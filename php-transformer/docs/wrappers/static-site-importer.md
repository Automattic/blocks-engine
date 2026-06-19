# Static Site Importer Adapter Strategy

`chubes4/static-site-importer` should remain a WordPress product plugin. It should consume `php-transformer` for conversion work while continuing to own intake, page creation, theme generation, admin/CLI UX, quality gates, and activation flows.

## Current Dependency Points

| Current Static Site Importer path | Current dependency | Target dependency |
| --- | --- | --- |
| `Static_Site_Importer_Theme_Generator::import_theme()` | `bfb_convert()` for fragment/page/template conversion | Adapter service that calls `FormatBridge`/`HtmlTransformer` and returns the old serialized block strings until the report schema changes. |
| `Static_Site_Importer_Theme_Generator::import_website_artifact()` | `bac_compile_website_artifact()` and `bac_summarize_result()` | Adapter service that calls `ArtifactCompiler` and translates `TransformerResult` into the existing import report fields. |
| Conversion report recording | BFB and BAC result envelopes | A `TransformerResult` to import-report mapper owned by Static Site Importer. |
| CLI/ability output | Static Site Importer result arrays | No direct transformer exposure; keep CLI and ability contracts stable. |

## Adapter Contract

Create a small Static Site Importer-owned adapter class with exactly these public methods:

```php
final class Static_Site_Importer_Transformer_Adapter {
    public function convert_fragment( string $html, array $options = array() ): array {}
    public function compile_website_artifact( array $artifact, array $options = array() ): array {}
    public function blocks_to_html( array|string $blocks, array $options = array() ): string {}
    public function summarize_result( array $compiled ): array {}
}
```

The adapter should be the only Static Site Importer class that knows whether the active conversion engine is old BFB/BAC or `php-transformer`.

### `convert_fragment()`

`convert_fragment()` replaces SSI's fragment-level dependency on BFB/H2BC. It accepts a standalone HTML fragment and provenance/conversion options, then returns the BFB fragment envelope shape SSI can record without changing import reports:

| Key | Shape | Notes |
| --- | --- | --- |
| `success` | `bool` | `false` only when conversion status is `failed`. |
| `status` | `string` | Transformer status, e.g. `success`, `success_with_warnings`, or `failed`. |
| `from` / `to` | `string` | Always `html` to `blocks` for SSI fragments. |
| `scope` | `array<string,string>` | `type=fragment` plus `source_id`, `source_selector`, `region_id`, and `label` when supplied. |
| `content` / `serialized_blocks` | `string` | Serialized block markup consumed by theme generation. |
| `blocks` | `array` | Parse-block-compatible arrays for diagnostics or later rendering. |
| `diagnostics` | `array` | Scoped diagnostics; every row should carry `scope`. |
| `provenance` | `array` | `scope`, `source_bytes`, and `source_hash`. |
| `report` | `array` | Compact BFB-style report fields SSI can embed under conversion fragments. |

### `compile_website_artifact()`

`compile_website_artifact()` returns the current BAC result envelope, not the raw `TransformerResult` envelope. That keeps `Static_Site_Importer_Theme_Generator::import_website_artifact()` and `record_website_artifact_compiler_result()` stable while php-transformer becomes the compiler implementation.

| BAC result key | TransformerResult source |
| --- | --- |
| `schema` | Literal `block-artifact-compiler/result/v1`. |
| `status` | `status`. |
| `input` | `source_reports.artifact`, preserving `entry_path`, counts, file maps, original schema, and nested `source_report`. |
| `wordpress_artifacts.block_markup` | `serialized_blocks`. |
| `wordpress_artifacts.blocks` | `blocks`. |
| `wordpress_artifacts.block_tree` | Adapter-derived compact block count/depth report. |
| `wordpress_artifacts.block_types` | `block_types`. |
| `wordpress_artifacts.components` | `components`. |
| `wordpress_artifacts.documents` | `documents`. |
| `wordpress_artifacts.files` | `assets`. |
| `provenance.source_hash` | First transformer provenance row `source_hash`, falling back to `source_reports.artifact.source_hash`. |
| `provenance.source` | Artifact `entry_path`, falling back to `website_artifact`. |
| `diagnostics` | `diagnostics`. |
| `bfb_report` | Compact status, serialized blocks, diagnostics, and fallbacks for current SSI report payloads. |

### `blocks_to_html()`

`blocks_to_html()` is the reverse path SSI needs for previews, report evidence, or future repair loops. It accepts either serialized block markup or parse-block-compatible arrays and returns rendered HTML through `FormatBridge`/`Runtime`.

### `summarize_result()`

`summarize_result()` mirrors current `bac_summarize_result()` fields used in SSI import reports: schema, status, source, source element/class/CSS-selector counts, block count/depth, block type count, component count, file count, and diagnostic count.

## Acceptance Fixture Inventory

Use these Static Site Importer fixtures to compare the legacy BFB/BAC path against the adapter path before changing defaults:

| Fixture | SSI reference | Adapter surfaces | Acceptance focus |
| --- | --- | --- | --- |
| `wordpress-is-dead` | `tests/fixtures/wordpress-is-dead`, exercised by `tests/smoke-wordpress-is-dead-fixture.php` | `convert_fragment()`, `blocks_to_html()` | Multi-page HTML import, header/footer extraction, navigation rewriting, CSS preservation, zero empty HTML fallback regressions, visual/semantic comparison target reporting. |
| `mixed-source-site` | `tests/fixtures/mixed-source-site`, exercised by `tests/smoke-mixed-source-fixture.php` and `tests/smoke-mixed-source-link-rewrites.php` | `convert_fragment()` for HTML fragments, BAC-compatible document/report mapping for Markdown documents | Markdown page creation, skipped MDX diagnostics, link rewrites away from `.md`, source-document report counts. |
| `website-artifact-bundle` | `tests/fixtures/website-artifact-bundle/artifact.json` | `compile_website_artifact()`, `summarize_result()` | BAC-compatible result envelope, `wordpress_artifacts.block_markup`, materializable CSS/JS/file artifacts, provenance, diagnostics, and import-report summary fields. |

The adapter PR should include an old-versus-adapter report table for these fixtures before switching the adapter default to transformer-backed conversion.

## Phased Switch

1. Add the adapter while it still delegates to `bfb_convert()` and `bac_compile_website_artifact()`.
2. Add transformer-backed code paths behind the adapter once `php-transformer` implements equivalent behavior.
3. Compare old and transformer-backed import reports on representative fixtures.
4. Switch the adapter default only after quality gates pass with zero fallback regressions.
5. Remove direct `bfb_*` and `bac_*` calls from Static Site Importer after the compatibility packages are released.

## Adapter Skeleton

See `examples/compatibility/static-site-importer-transformer-adapter.php` for a copyable Static Site Importer-owned adapter contract example.
