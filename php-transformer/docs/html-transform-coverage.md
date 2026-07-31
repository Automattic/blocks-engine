# HTML Transform Coverage

The PHP transformer HTML slice is covered by JSON parity fixtures in `tests/fixtures/parity`. These fixtures assert block names, selected attributes, and fallback counts so supported transforms are tracked outside the contract smoke runner.

Run the coverage fixtures with `composer parity` or as part of `composer test`.

## Fixture Matrix

| Area | Fixture | Expected support |
| --- | --- | --- |
| Heading and paragraph | `simple-html.json`, `html-core-text-structure.json` | `core/heading`, `core/paragraph` |
| Lists | `html-core-text-structure.json` | `core/list`, `core/list-item` |
| Grouped description lists | `fixtures/websites/37-art-gallery-exhibition/current-exhibition.html`, `fixtures/websites/33-sports-team-league/team-roxbury-roar.html` | Observed corpus sources preserve valid `dl > div > dt/dd` row topology through `blocks-engine/description-list`; the synthetic `tests/fixtures/description-list-grouped-schedule.html` covers the closed wrapper-attribute policy. |
| Quotes | `html-core-text-structure.json`, `html-figure-quote-media.json` | `core/quote`, `core/pullquote`, figure-wrapped testimonial quotes with `figcaption` citation |
| Code | `html-core-text-structure.json` | `core/code`, `core/preformatted` |
| Tables | `html-core-media-actions.json` | `core/table` with head/body/caption attrs |
| Images | `html-core-media-actions.json`, `html-figure-quote-media.json` | `core/image` with URL, alt, dimensions, caption, identity, size, and class attrs |
| Media and text | `html-media-text.json` | `core/media-text` for strict two-pane image/video and text layouts; ambiguous or impure media layouts continue through existing columns/group handling |
| Buttons | `html-core-media-actions.json` | `core/buttons` containing `core/button` children |
| Shortcodes | `html-core-media-actions.json` | `core/shortcode` for standalone shortcode text |
| Wrapper provenance and safety | `html-provenance-wrapper-safety.json` | Presentational semantic wrappers are preserved as `core/group`; unsupported fallback records include selector/source metadata and sanitized fallback HTML |
| Unsupported fallback | `unsupported-fallback.json`, `html-unsupported-context-required.json` | Unsupported elements are reported in `fallbacks` and do not fail supported siblings |
| Website artifact bundle | `website-artifact-bundle.json` | HTML, CSS, and JS artifact inputs compile into the shared result envelope |
| Compiled site contract | `compiled-site-contract.json` | Generic site artifacts expose normalized pages, document metadata, full page block markup, template parts, visual-repair stylesheets, assets, and theme buckets in `source_reports.compiled_site` |
| Generated store artifacts | `generated-store-inferred-html.json`, `generated-store-manifest-valid-artifact.json`, `generated-store-manifest-invalid-artifact.json` | Product-shaped generated artifacts preserve files, manifests, components, and diagnostics without owning product validation |
| Mixed source markdown | `mixed-source-markdown.json` | Markdown documents compile into transformer document output |

## Current Boundaries

| Category | Status | Notes |
| --- | --- | --- |
| Supported | Heading, paragraph, unordered/ordered list, quote, pullquote, code, preformatted, table, image, media-text, buttons/button, shortcode | Fixtures assert the block names and representative attrs currently emitted by `HtmlTransformer`. Media-text requires exactly two element children: one pure image/video side and one text-bearing side; ambiguous layouts retain existing columns/group behavior. |
| Unsupported fallback | Unknown/custom elements, SVG markup, form controls, other unsupported top-level HTML | Fallbacks use `type: unsupported_element`, include the source tag, selector, caller source/scope when provided, sanitized HTML, and increment `coverage.0.fallback_count`. |
| Context-required | Interactive/form behavior, embeds, advanced layout semantics, raw-handler hooks | These require WordPress/Gutenberg runtime context or richer product converter behavior and remain outside the PHP transformer's supported slice. |
| Gutenberg editor validation | Gap | The repository has no browser harness that boots Gutenberg, registers generated companion blocks, and validates load/edit/save output. `wp_block_validity` is a PHP structural and canonical save-shape check; the WordPress integration test and Playwright visual-parity tooling do not exercise the editor. |
