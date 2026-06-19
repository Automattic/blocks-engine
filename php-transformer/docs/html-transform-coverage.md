# HTML Transform Coverage

The PHP transformer HTML slice is covered by JSON parity fixtures in `tests/fixtures/parity`. These fixtures assert block names, selected attributes, and fallback counts so supported transforms are tracked outside the contract smoke runner.

Run the coverage fixtures with `composer parity` or as part of `composer test`.

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
| Website artifact bundle | `website-artifact-bundle.json` | HTML, CSS, and JS artifact inputs compile into the shared result envelope |
| Generated store artifacts | `generated-store-inferred-html.json`, `generated-store-manifest-valid-artifact.json`, `generated-store-manifest-invalid-artifact.json` | Product-shaped generated artifacts preserve files, manifests, components, and diagnostics without owning product validation |
| Mixed source markdown | `mixed-source-markdown.json` | Markdown documents compile into transformer document output |

## Current Boundaries

| Category | Status | Notes |
| --- | --- | --- |
| Supported | Heading, paragraph, unordered/ordered list, quote, pullquote, code, preformatted, table, image, buttons/button, shortcode | Fixtures assert the block names and representative attrs currently emitted by `HtmlTransformer`. |
| Unsupported fallback | Unknown/custom elements, form controls, other unsupported top-level HTML | Fallbacks use `type: unsupported_element`, include the source tag, and increment `coverage.0.fallback_count`. |
| Context-required | Interactive/form behavior, embeds, advanced layout semantics, raw-handler hooks | These require WordPress/Gutenberg runtime context or richer product converter behavior and remain outside the PHP transformer's supported slice. |
