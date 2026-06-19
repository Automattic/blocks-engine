# Consumer PR Plan: static-site-importer

## Phase-1 PR Goal

Keep `chubes4/static-site-importer` as the WordPress product plugin while adding a Static Site Importer-owned adapter that can call either the current BFB/BAC functions or `php-transformer` classes behind one local boundary.

php-transformer is product-level primitive and old repos are downstream consumers.

Branch: `cook/php-transformer-adapter`.

Commit sequence:

1. `Add transformer review dependency for importer adapter`: add path repositories and requirements without changing product behavior.
2. `Add Static Site Importer transformer adapter`: add an SSI-owned adapter that defaults to legacy BFB/BAC calls.
3. `Route product conversions through adapter`: replace scattered direct BFB/BAC calls with adapter calls while keeping reports unchanged.
4. `Add legacy versus transformer report comparisons`: capture fixture tables for import reports, fallback counts, generated files, and ability/CLI outputs.
5. `Enable transformer adapter path`: switch defaults only after transformer and compatibility wrapper releases are tagged.

## Composer Change

During review, add the transformer path repository without removing the compatibility packages immediately:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../blocks-engine@cook-php-transformer-migration-no-perma-legacy/php-transformer",
      "options": {
        "symlink": true
      }
    },
    {
      "name": "block-artifact-compiler",
      "type": "vcs",
      "url": "https://github.com/chubes4/block-artifact-compiler"
    },
    {
      "type": "vcs",
      "url": "https://github.com/chubes4/block-format-bridge"
    }
  ],
  "require": {
    "php": "^8.1",
    "automattic/blocks-engine-php-transformer": "dev-cook/php-transformer-migration-no-perma-legacy as 0.1.x-dev",
    "chubes4/block-artifact-compiler": "dev-cook/php-transformer-artifact-wrapper",
    "chubes4/block-format-bridge": "dev-cook/php-transformer-format-wrapper"
  }
}
```

Before merge, replace wrapper branch constraints and the transformer path constraint with tagged compatibility releases in this order: transformer, H2BC, BFB, BAC, then Static Site Importer.

Do not use Composer `replace` or `provide` to satisfy `chubes4/block-format-bridge` or `chubes4/block-artifact-compiler` with `automattic/blocks-engine-php-transformer`. Static Site Importer needs the product-facing BFB/BAC compatibility surfaces until its own adapter has proven direct transformer parity.

Recommended merge constraints after compatibility tags are available:

```json
{
  "require": {
    "php": "^8.1",
    "chubes4/block-artifact-compiler": "^0.1.0",
    "chubes4/block-format-bridge": "^0.1.0"
  }
}
```

Add `automattic/blocks-engine-php-transformer:^0.1.0` directly only in the commit that introduces and verifies SSI-owned direct transformer adapter paths. Until then, consume transformer behavior through tagged wrapper compatibility releases.

## README And Issue Continuity

Add this banner near the top of the downstream README:

```markdown
> **Dependency notice:** Static Site Importer remains the product plugin for WordPress import workflows. Its lower-level conversion dependencies are moving toward `automattic/blocks-engine-php-transformer` through tagged compatibility releases before direct transformer adoption. Product workflow bugs for uploads, imports, reports, generated themes, assets, and CLI/ability output remain welcome here.
```

Issue template guidance should keep product workflow reports in Static Site Importer, route BFB/BAC public surface regressions to those compatibility repositories, and link upstream Blocks Engine issues only for missing reusable transformer APIs or result fields.

Do not archive Static Site Importer. Do not archive H2BC, BFB, or BAC as part of the SSI adapter PR; SSI should first prove it can consume tagged compatibility releases and then direct transformer calls without import-report or fallback regressions.

## File-Level Patch Skeleton

```diff
composer.json
  + repositories[].type=path url=../blocks-engine@cook-php-transformer-migration-no-perma-legacy/php-transformer
  + require.automattic/blocks-engine-php-transformer=dev-cook/php-transformer-migration-no-perma-legacy as 0.1.x-dev
  ~ require chubes4/block-format-bridge and chubes4/block-artifact-compiler wrapper branches during review only
includes/class-static-site-importer-transformer-adapter.php
  + SSI-owned adapter with legacy BFB/BAC default and transformer-backed branches
includes/class-static-site-importer-theme-generator.php
  ~ replace direct bfb_* and bac_* calls with adapter calls
includes/abilities* and includes/cli*
  ~ keep public schemas stable while sourcing reports through the adapter
tests/smoke-*.php and tests/fixtures/*
  + old-versus-transformer comparison assertions for reports and fallback counts
README.md or docs/*
  ~ document dependency release order and rollback switch
```

## Product Boundary

Static Site Importer continues to own:

- Uploaded ZIP and URL intake.
- Page creation and theme file generation.
- Asset materialization, media-library imports, and local asset reports.
- WooCommerce/product seeding gates.
- Admin UI, WP-CLI commands, Abilities API callbacks, and import report presentation.

`php-transformer` owns only reusable conversion, artifact compilation, result envelopes, diagnostics, and WordPress runtime adapters.

## Static Site Importer Adapter Skeleton

This is an example-only shape for the downstream PR. Keep it in Static Site Importer when implemented; do not add an implementation class to `php-transformer`.

```php
<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

final class Static_Site_Importer_Transformer_Adapter {
    public function convert_fragment( string $html, array $options = array() ): array {
        if ( class_exists( HtmlTransformer::class ) ) {
            $result = ( new HtmlTransformer() )->transform( $html )->toArray();
            return $this->to_bfb_fragment_envelope( $result, $html, $options );
        }

        return function_exists( 'bfb_convert_fragment' ) ? bfb_convert_fragment( $html, $options ) : array();
    }

    public function compile_website_artifact( array $artifact, array $options = array() ): array {
        if ( class_exists( ArtifactCompiler::class ) ) {
            return $this->to_bac_result( ( new ArtifactCompiler() )->compile( $artifact )->toArray() );
        }

        return function_exists( 'bac_compile_website_artifact' ) ? bac_compile_website_artifact( $artifact, $options ) : array();
    }

    public function blocks_to_html( array|string $blocks, array $options = array() ): string {
        if ( class_exists( FormatBridge::class ) ) {
            return is_string( $blocks )
                ? ( new FormatBridge() )->convert( $blocks, 'blocks', 'html', $options )
                : ( new \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime() )->renderBlocks( array_values( $blocks ) );
        }

        return is_string( $blocks ) && function_exists( 'bfb_convert' ) ? bfb_convert( $blocks, 'blocks', 'html', $options ) : '';
    }

    public function summarize_result( array $compiled ): array {
        if ( function_exists( 'bac_summarize_result' ) ) {
            return bac_summarize_result( $compiled );
        }

        return array(
            'schema' => isset( $compiled['schema'] ) ? (string) $compiled['schema'] : '',
            'status' => isset( $compiled['status'] ) ? (string) $compiled['status'] : 'unknown',
            'diagnostic_count' => isset( $compiled['diagnostics'] ) && is_array( $compiled['diagnostics'] ) ? count( $compiled['diagnostics'] ) : 0,
        );
    }
}
```

The copyable, linted version in `examples/compatibility/static-site-importer-transformer-adapter.php` includes the full `TransformerResult` to BAC result mapper. SSI should keep that mapper local so product reports stay stable while transformer internals evolve.

## Public Function And Call Mapping

| Current Static Site Importer surface | Current dependency | Adapter target | Phase-1 behavior |
| --- | --- | --- | --- |
| `Static_Site_Importer_Theme_Generator::import_theme()` | `bfb_convert_fragment()`, `bfb_convert()`, and conversion reports | `Static_Site_Importer_Transformer_Adapter::convert_fragment()` / `blocks_to_html()` | Keep import result and quality report shape unchanged. |
| `Static_Site_Importer_Theme_Generator::import_website_artifact()` | `bac_compile_website_artifact()` | `compile_website_artifact()` | Map transformer result arrays into existing BAC-compatible import args and report payloads. |
| `Static_Site_Importer_Source_Page::from_wordpress_document_artifact()` | BAC document artifact fields | Transformer document artifact fields through BAC-compatible mapper | Keep accepted document artifact fields stable. |
| `static-site-importer/import-theme` ability | Theme generator return array | Adapter hidden behind theme generator | Keep input and output schemas stable. |
| `static-site-importer/import-website-artifact` ability | BAC compile result and theme generator | Adapter hidden behind ability callback | Keep ability contract stable and report transformer metadata only inside reports. |
| `wp static-site-importer import-theme` | Ability output | Ability output unchanged | Keep CLI flags and JSON shape stable. |

## PR Steps

1. Add the adapter class in Static Site Importer with legacy BFB/BAC delegation as the initial default.
2. Replace direct `bfb_convert()` and `bac_compile_website_artifact()` calls in product code with adapter calls.
3. Add transformer-backed branches behind the adapter once compatibility package releases are available.
4. Compare import reports from legacy and transformer-backed paths for representative fixtures.
5. Switch the adapter default only after `--fail-on-quality` and `--max-fallbacks` gates produce equivalent or better results.
6. Remove direct product-code knowledge of BFB/BAC only after the compatibility packages are released and pinned.

## Acceptance Criteria

- Existing Static Site Importer smoke tests pass, including URL import, local asset materialization, media-library asset policy, mixed source pages, markdown frontmatter, WooCommerce gates, and ability/CLI errors.
- `static-site-importer/import-theme` and `static-site-importer/import-website-artifact` output schemas remain stable.
- Import reports show no fallback-count regression against the same fixtures.
- Generated theme file paths, page IDs, source cleanup behavior, and commerce dependency gates remain owned by Static Site Importer.
- The PR includes an old-versus-transformer report table for representative fixtures before changing defaults.
- Static Site Importer-owned adapters do not require `php-transformer` package metadata, namespaces, release labels, or code paths to mention Static Site Importer, BFB, BAC, or H2BC.

Acceptance commands:

```sh
composer validate
composer test
wp static-site-importer import-theme --help
wp static-site-importer import-website-artifact --help
git diff --check
```

Rollback plan: switch the adapter default back to legacy BFB/BAC calls first. If the adapter routing itself caused the regression, revert the product call-site routing commit while preserving fixture comparison artifacts for the upstream blocker.

## Wrapper Exit Path

Static Site Importer remains a product plugin in this wave, not an archive candidate. Its exit from the old wrapper stack is complete when product-owned adapters call tagged `php-transformer` APIs directly and BFB/BAC/H2BC are no longer runtime dependencies for supported import paths.

Until then, Static Site Importer may consume thin-shim releases of the old repositories. It must keep product workflow behavior local and must not require `php-transformer` to carry product-specific or old-repo-specific compatibility branches.

## Required Fixture Inventory

Run and compare these fixtures before changing adapter defaults:

| Fixture | Test or source path | Required comparison |
| --- | --- | --- |
| `wordpress-is-dead` | `tests/smoke-wordpress-is-dead-fixture.php` and `tests/fixtures/wordpress-is-dead` | Legacy versus adapter import report, fallback count, generated templates/parts, navigation, CSS bridge output, visual/semantic targets. |
| `mixed-source-site` | `tests/smoke-mixed-source-fixture.php`, `tests/smoke-mixed-source-link-rewrites.php`, and `tests/fixtures/mixed-source-site` | Source-document counts, Markdown page creation, skipped MDX diagnostics, rewritten links, conversion fragment keys. |
| `website-artifact-bundle` | `tests/fixtures/website-artifact-bundle/artifact.json` | BAC result envelope fields, `wordpress_artifacts` mapping, provenance, diagnostics, summary fields, materialized CSS/JS/file artifacts. |

## Blockers To Resolve Upstream First

- Missing transformer methods for serialized block markup from HTML fragments.
- Missing transformer option forwarding for HTML and artifact conversion paths.
- Missing artifact document fields currently consumed by Static Site Importer.
- Missing result summary fields needed by import-report summaries and quality gates.
- Missing asset materialization request diagnostics in the transformer result envelope.
