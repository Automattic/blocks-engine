# Consumer PR Plan: block-format-bridge

## Phase-1 PR Goal

Keep `chubes4/block-format-bridge` as the public format-conversion compatibility layer while routing HTML, Markdown, and serialized block conversions through `php-transformer` adapters.

## Composer Change

During review, add the transformer dependency beside the existing package requirements:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "/Users/chubes/Developer/blocks-engine@cook-php-transformer-consumer-prep/php-transformer",
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
    "automattic/blocks-engine-php-transformer": "dev-cook/php-transformer-consumer-prep",
    "league/commonmark": "^2.5",
    "league/html-to-markdown": "^5.1"
  }
}
```

Before merge, replace the path-only development constraint with the first tagged transformer release.

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

## Blockers To Resolve Upstream First

- Missing transformer adapters for every format currently routed by `bfb_convert()` and `bfb_to_blocks()`.
- Missing fragment-scope option support in `HtmlTransformer`.
- Missing capability report data needed by BFB abilities and diagnostics.
