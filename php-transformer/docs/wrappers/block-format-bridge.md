# block-format-bridge Wrapper Plan

`chubes4/block-format-bridge` should become a compatibility package around `Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge` after the format conversion slice lands.

## Public Surface Mapping

| Existing surface | Target transformer surface | Wrapper behavior |
| --- | --- | --- |
| `bfb_convert( string $content, string $from, string $to, array $options = array() )` | `FormatBridge::convert( $content, $from, $to, $options )` | Return the target format string for serialized outputs. |
| `bfb_to_blocks( string $content, string $from, array $options = array() )` | `FormatBridge::toBlocks( $content, $from, $options )` | Return parse-block-compatible arrays. |
| `bfb_convert_fragment( string $html, array $options = array() )` | `FormatBridge::convertFragment( $html, $options )` or `HtmlTransformer::transform()` | Preserve the current fragment envelope keys: `success`, `status`, `content`, `serialized_blocks`, `blocks`, `diagnostics`, `provenance`, and `report`. |
| `bfb_conversion_report( string $content, string $from, array $options = array() )` | `TransformerResult::toArray()` plus bridge-specific summary | Keep old report keys until Static Site Importer no longer consumes them. |
| `BFB_Format_Adapter` implementations | Transformer format adapters | Keep adapter registration as compatibility glue; new implementations should target transformer contracts first. |
| `bfb_capabilities()` | Transformer capability report | Return the old capability shape and add transformer package/version metadata. |

## Adapter Skeleton

See `examples/compatibility/block-format-bridge-wrapper.php` for a copyable wrapper sketch.

## Migration Notes

- Preserve the block-array pivot: every old cross-format conversion is `source format -> blocks -> target format`.
- Keep old filter names active from the wrapper while implementation moves behind transformer classes.
- Maintain the prefixed/unprefixed dependency tolerance in the compatibility repo build; `php-transformer` should not own php-scoper decisions for downstream plugins.
- Static Site Importer currently depends on `bfb_convert()` and conversion report shapes, so BFB wrappers should migrate before Static Site Importer switches package dependencies.
