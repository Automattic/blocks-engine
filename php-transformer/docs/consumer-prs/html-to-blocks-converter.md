# Consumer PR Plan: html-to-blocks-converter

## Phase-1 PR Goal

Turn `chubes4/html-to-blocks-converter` into a compatibility package that keeps its current WordPress plugin and function surface while delegating canonical HTML conversion to `Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer`.

## Composer Change

During review, add the transformer path repository and requirement:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "/Users/chubes/Developer/blocks-engine@cook-php-transformer-consumer-prep/php-transformer",
      "options": {
        "symlink": true
      }
    }
  ],
  "require": {
    "php": ">=8.1",
    "automattic/blocks-engine-php-transformer": "dev-cook/php-transformer-consumer-prep"
  }
}
```

Before merge, replace the path-only development constraint with the first tagged transformer release.

## Public Function Mapping

| Current public surface | Transformer target | Phase-1 wrapper behavior |
| --- | --- | --- |
| `html_to_blocks_raw_handler( array $args )` | `HtmlTransformer::transform( string $html ): TransformerResult` | Read `$args['HTML']`, call the transformer, and return `$result->blocks`. Keep non-HTML context local until transformer options are public. |
| `html_to_blocks_convert( string $html, array $args = array() )` | `HtmlTransformer::transform()` | Return block arrays from the result envelope; preserve existing filter/action hooks around the call. |
| `html_to_blocks_normalise_blocks( string $html )` | `HtmlTransformer` preprocessing or a future normalizer method | Keep local helper until an upstream transformer normalizer is public; do not duplicate new behavior in the wrapper. |
| `html_to_blocks_create_unsupported_html_fallback_block()` | `TransformerResult::$diagnostics` plus fallback block output | Preserve the current fallback block shape and hook emissions for existing tests. |
| `HTML_To_Blocks_Transform_Registry::get_raw_transforms()` | Transformer-owned transform registry | Keep as legacy inspection only; new callers should use transformer result diagnostics/capabilities. |
| `HTML_To_Blocks_Block_Factory` | Transformer internals | Keep for compatibility tests and legacy helpers; avoid documenting it as a new extension point. |
| `HTML_To_Blocks_Attribute_Parser` | Transformer internals | Keep for legacy transforms until the upstream transformer exposes equivalent parsing contracts. |

## PR Steps

1. Raise the package PHP constraint to `>=8.1` to match `php-transformer`.
2. Add the transformer dependency through the path repository while the PR is under review.
3. Load Composer autoload before the existing library bootstrap.
4. Replace the body of `html_to_blocks_raw_handler()` and `html_to_blocks_convert()` with transformer delegation while preserving current hooks and return shapes.
5. Keep local transform-registry classes loaded for compatibility and tests until no external callers remain.
6. Update fixtures to assert old raw-handler output equals transformer output for representative HTML.

## Acceptance Criteria

- Existing smoke and unit tests pass without reducing fixture coverage.
- `html_to_blocks_raw_handler( array( 'HTML' => '<p>Hello</p>' ) )` returns the same block-array shape as before.
- Existing fallback hooks still fire for unsupported HTML fragments.
- No Static Site Importer or BFB product-specific behavior is added to this repository.
- The PR body documents any output differences with fixture names and links to upstream transformer issues for missing behavior.

## Blockers To Resolve Upstream First

- Missing public transformer option for raw-handler context propagation.
- Missing stable fallback diagnostic shape in `TransformerResult`.
- Missing transform inventory/capability API if downstream tests still need to inspect supported transforms.
