# Consumer PR Plan: block-format-bridge

## Phase-1 PR Goal

Keep `chubes4/block-format-bridge` as a temporary downstream format-conversion compatibility layer while routing HTML, Markdown, and serialized block conversions through `php-transformer` adapters.

php-transformer is product-level primitive and old repos are downstream consumers.

Branch: `cook/php-transformer-format-wrapper`.

Commit sequence:

1. `Add transformer dependency for format bridge review`: add the path repository, requirement, and autoload wiring without changing adapter defaults.
2. `Add transformer-backed BFB adapters`: add BFB-owned adapters that implement current BFB interfaces and delegate internally.
3. `Preserve capability and report contracts`: add transformer metadata and fixture comparisons while keeping old report keys stable.
4. `Switch eligible conversions to transformer adapters`: change defaults only after current conversion tests and Static Site Importer-facing report checks pass.

## Composer Change

During review, add the transformer dependency beside the existing package requirements:

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
      "type": "vcs",
      "url": "https://github.com/chubes4/html-to-blocks-converter"
    }
  ],
  "require": {
    "php": "^8.1",
    "automattic/blocks-engine-php-transformer": "dev-cook/php-transformer-migration-no-perma-legacy as 0.1.x-dev",
    "league/commonmark": "^2.5",
    "league/html-to-markdown": "^5.1"
  }
}
```

Before merge, replace the path-only development constraint with the first tagged transformer release.

## File-Level Patch Skeleton

```diff
composer.json
  + repositories[].type=path url=../blocks-engine@cook-php-transformer-migration-no-perma-legacy/php-transformer
  + require.automattic/blocks-engine-php-transformer=dev-cook/php-transformer-migration-no-perma-legacy as 0.1.x-dev
library.php or plugin bootstrap
  + require vendor/autoload.php before adapter registry setup
includes/adapters/*
  + transformer-backed BFB_Format_Adapter implementations
includes/capabilities* or report helpers
  ~ add transformer package/version metadata without removing current keys
tests/*
  + conversion/report parity cases for HTML, Markdown, blocks, and unsupported fragments
README.md
  ~ document BFB as compatibility facade over transformer adapters
```

## Public Function Mapping

| Current public surface | Transformer target | Phase-1 wrapper behavior |
| --- | --- | --- |
| `bfb_convert( string $content, string $from, string $to, array $options = array() )` | `FormatBridge::convert()` | Keep string return shape and route through transformer bridge when both formats are supported. |
| `bfb_to_blocks( string $content, string $from, array $options = array() )` | `FormatBridge::toBlocks()` or registered transformer adapter | Return parse-block-compatible arrays exactly as current callers expect. |
| `bfb_normalize( string $content, string $format, array $options = array() )` | `Normalizer::normalize()` | Preserve `string|WP_Error`; translate transformer diagnostics to `WP_Error` only at the BFB boundary. |
| `bfb_convert_fragment( string $html, array $options = array() )` | `HtmlTransformer::transform()` plus fragment scope options | Preserve the current envelope keys: `success`, `status`, `content`, `serialized_blocks`, `blocks`, `diagnostics`, `provenance`, `report`. |
| `bfb_conversion_report( string $content, string $from, array $options = array() )` | `TransformerResult::toArray()` plus BFB quality analysis | Keep existing quality fields and add transformer package/version metadata. |
| `bfb_analyze_blocks( array $blocks )` | Result diagnostics helper or local compatibility analyzer | Keep local analyzer until transformer exposes all existing quality fields. |
| `bfb_capabilities()` | Transformer capability report | Preserve old capability keys and include transformer availability under a new metadata key. |
| `bfb_get_adapter( string $slug )` | `AdapterRegistry` | Continue returning `BFB_Format_Adapter` instances; adapters may delegate to transformer classes internally. |
| `BFB_Format_Adapter` | `FormatAdapterInterface` | Keep the BFB interface stable and bridge adapter implementations internally. |
| `BFB_Adapter_Registry` | `AdapterRegistry` | Keep filter-aware registration behavior; do not expose transformer registry directly from BFB. |

## PR Steps

1. Add the transformer dependency and autoload it in the existing BFB bootstrap.
2. Introduce BFB-owned adapter wrappers that implement `BFB_Format_Adapter` and delegate to transformer format adapters.
3. Preserve all existing BFB filters/actions listed by `bfb_capabilities()`.
4. Add transformer metadata to capabilities without removing current `bridge`, `formats`, `conversions`, `h2bc`, `block_coverage`, `hooks`, or `abilities` keys.
5. Run current BFB smoke tests against the path repository.
6. Document fixture-level differences in the PR body before changing default adapters.

## Acceptance Criteria

- `bfb_convert()`, `bfb_to_blocks()`, `bfb_normalize()`, `bfb_convert_fragment()`, and `bfb_conversion_report()` keep their current return types.
- Existing REST, CLI, ability, hook, and filter tests pass.
- Capabilities report includes transformer metadata while preserving old keys.
- Static Site Importer can keep calling `bfb_convert()` and `bfb_conversion_report()` unchanged during its compatibility phase.
- The PR does not move import, filesystem, theme, or activation logic into BFB.
- The wrapper does not require `php-transformer` package metadata, namespaces, release labels, or code paths to mention `block-format-bridge`.

Acceptance commands:

```sh
composer validate
composer test
git diff --check
```

Rollback plan: revert the adapter-default switch first. If transformer metadata changes break consumers, keep adapter classes but remove metadata additions from reports until the upstream contract is fixed.

## Archive Or Thin-Shim Exit

Archive this repository after Static Site Importer and any supported product adapters call `FormatBridge` directly and no supported consumer still requires `bfb_*` functions, `BFB_Format_Adapter`, CLI commands, abilities, or package metadata.

If external consumers still require the old package name, keep a thin shim that only preserves public BFB entrypoints, loads tagged `php-transformer`, emits deprecation notices where appropriate, and delegates to `FormatBridge` or other tagged transformer APIs. The shim must not add new conversion behavior or require `php-transformer` to carry BFB-specific compatibility branches.

## Blockers To Resolve Upstream First

- Missing transformer adapters for every format currently routed by `bfb_convert()` and `bfb_to_blocks()`.
- Missing fragment-scope option support in `HtmlTransformer`.
- Missing capability report data needed by BFB abilities and diagnostics.
