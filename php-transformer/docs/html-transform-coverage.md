# HTML Transform Coverage

The PHP transformer HTML slice is covered by JSON parity fixtures in `tests/fixtures/parity`. These fixtures assert block names, selected attributes, and fallback counts so supported transforms are tracked outside the contract smoke runner.

Run the coverage fixtures with `composer parity` or as part of `composer test`.

Legacy repository comparisons are opt-in and are not required by default CI. Set `BLOCKS_ENGINE_PARITY_LEGACY=1` plus the repo-specific path for the legacy package you want to compare:

```sh
BLOCKS_ENGINE_PARITY_LEGACY=1 \
BLOCKS_ENGINE_PARITY_LEGACY_BLOCK_FORMAT_BRIDGE_PATH=/Users/chubes/Developer/block-format-bridge \
composer parity
```

Supported path variables are:

| Repository | Environment variable |
| --- | --- |
| `html-to-blocks-converter` | `BLOCKS_ENGINE_PARITY_LEGACY_HTML_TO_BLOCKS_CONVERTER_PATH` |
| `block-format-bridge` | `BLOCKS_ENGINE_PARITY_LEGACY_BLOCK_FORMAT_BRIDGE_PATH` |
| `block-artifact-compiler` | `BLOCKS_ENGINE_PARITY_LEGACY_BLOCK_ARTIFACT_COMPILER_PATH` |

Legacy code runs in an isolated PHP subprocess so old global functions, classes, constants, and bundled dependencies do not leak into the current transformer test process. Fixtures must opt in with `legacy_comparison.safe=true`; fixtures that require WordPress/Gutenberg runtime behavior should keep an explicit `skip` reason. The runner prints skipped comparisons with the reason, and any loaded safe comparison fails the parity run when the normalized legacy snapshot differs from the current transformer snapshot.

## Fixture Matrix

| Area | Fixture | Expected support |
| --- | --- | --- |
| Heading and paragraph | `simple-html.json`, `html-core-text-structure.json` | `core/heading`, `core/paragraph` |
| Lists | `html-core-text-structure.json` | `core/list`, `core/list-item` |
| Quotes | `html-core-text-structure.json` | `core/quote`, `core/pullquote` |
| Code | `html-core-text-structure.json` | `core/code`, `core/preformatted` |
| Tables | `html-core-media-actions.json` | `core/table` with head/body/caption attrs |
| Images | `html-core-media-actions.json` | `core/image` with URL, alt, dimensions, caption attrs |
| Buttons | `html-core-media-actions.json` | `core/buttons` containing `core/button` children |
| Shortcodes | `html-core-media-actions.json` | `core/shortcode` for standalone shortcode text |
| Unsupported fallback | `unsupported-fallback.json`, `html-unsupported-context-required.json` | Unsupported elements are reported in `fallbacks` and do not fail supported siblings |

## Current Boundaries

| Category | Status | Notes |
| --- | --- | --- |
| Supported | Heading, paragraph, unordered/ordered list, quote, pullquote, code, preformatted, table, image, buttons/button, shortcode | Fixtures assert the block names and representative attrs currently emitted by `HtmlTransformer`. |
| Unsupported fallback | Unknown/custom elements, form controls, other unsupported top-level HTML | Fallbacks use `type: unsupported_element`, include the source tag, and increment `coverage.0.fallback_count`. |
| Context-required | Interactive/form behavior, embeds, advanced layout semantics, raw-handler hooks | These require WordPress/Gutenberg runtime context or richer legacy converter behavior and remain outside the PHP transformer's supported slice. |
