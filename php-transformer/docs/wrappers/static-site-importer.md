# Static Site Importer Adapter Strategy

`chubes4/static-site-importer` should remain a WordPress product plugin. It should consume `php-transformer` for conversion work while continuing to own intake, page creation, theme generation, admin/CLI UX, quality gates, and activation flows.

## Current Dependency Points

| Current Static Site Importer path | Current dependency | Target dependency |
| --- | --- | --- |
| `Static_Site_Importer_Theme_Generator::import_theme()` | `bfb_convert()` for fragment/page/template conversion | Adapter service that calls `FormatBridge`/`HtmlTransformer` and returns the old serialized block strings until the report schema changes. |
| `Static_Site_Importer_Theme_Generator::import_website_artifact()` | `bac_compile_website_artifact()` and `bac_summarize_result()` | Adapter service that calls `ArtifactCompiler` and translates `TransformerResult` into the existing import report fields. |
| Conversion report recording | BFB and BAC result envelopes | A `TransformerResult` to import-report mapper owned by Static Site Importer. |
| CLI/ability output | Static Site Importer result arrays | No direct transformer exposure; keep CLI and ability contracts stable. |

## Recommended Adapter Shape

Create a small Static Site Importer-owned adapter class after the transformer methods are implemented:

```php
final class Static_Site_Importer_Transformer_Adapter {
    public function html_to_block_markup( string $html, array $options = array() ): string {}
    public function compile_website_artifact( array $artifact, array $options = array() ): array {}
    public function summarize_result( array $compiled ): array {}
}
```

The adapter should be the only Static Site Importer class that knows whether the active conversion engine is old BFB/BAC or `php-transformer`.

## Phased Switch

1. Add the adapter while it still delegates to `bfb_convert()` and `bac_compile_website_artifact()`.
2. Add transformer-backed code paths behind the adapter once `php-transformer` implements equivalent behavior.
3. Compare old and transformer-backed import reports on representative fixtures.
4. Switch the adapter default only after quality gates pass with zero fallback regressions.
5. Remove direct `bfb_*` and `bac_*` calls from Static Site Importer after the compatibility packages are released.

## Adapter Skeleton

See `examples/compatibility/static-site-importer-transformer-adapter.php` for a copyable Static Site Importer-owned adapter sketch.
