# html-to-blocks-converter Wrapper Plan

`chubes4/html-to-blocks-converter` should become a compatibility package around `Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer` after the HTML migration slice lands.

## Public Surface Mapping

| Existing surface | Target transformer surface | Wrapper behavior |
| --- | --- | --- |
| `html_to_blocks_raw_handler( array $args )` | `HtmlTransformer::transform( string $html )` | Read `$args['HTML']`, call the transformer, return `$result->blocks`. |
| `html_to_blocks_convert( string $html, array $args = array() )` | `HtmlTransformer::transform( string $html )` | Preserve the old direct conversion helper and forward options once `HtmlTransformer` accepts context. |
| `HTML_To_Blocks_Transform_Registry::get_raw_transforms()` | Internal transformer transform registry | Keep this as a legacy inspection hook only if external callers are found. New callers should not depend on transform-array internals. |
| `HTML_To_Blocks_Block_Factory` helpers | Transformer internals | Do not expose from `php-transformer` unless another package needs stable block construction utilities. |
| `html_to_blocks_safe_inline_svg_icon` action | Transformer diagnostics/assets | Keep the action in the wrapper while also mapping SVG materialization data into the result envelope. |

## Adapter Skeleton

See `docs/consumer-prs/examples/html-to-blocks-converter-wrapper.php` for a copyable migration wrapper sketch.

## Migration Notes

- Keep the old Composer `autoload.files` entry in `html-to-blocks-converter` so existing consumers continue to receive global functions.
- Move canonical transform behavior into `HtmlTransformer`; wrappers should only translate old arguments and return shapes.
- Preserve the current WordPress runtime assumptions in the wrapper: `parse_blocks()`, `serialize_blocks()`, `WP_HTML_Processor`, filters, and actions stay guarded by WordPress availability.
- Treat transform metrics, unsupported-HTML fallback diagnostics, and SVG icon materialization as result-envelope fields before removing any old hooks.
- Keep current smoke fixtures in the old repo until they are represented as shared `php-transformer` contract fixtures.
