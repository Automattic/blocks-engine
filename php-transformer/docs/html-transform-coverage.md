# HTML Transform Coverage

The PHP transformer HTML slice is covered by JSON parity fixtures in `tests/fixtures/parity`. These fixtures assert block names, selected attributes, and fallback counts so supported transforms are tracked outside the contract smoke runner.

Run the coverage fixtures with `composer parity` or as part of `composer test`.

## Fixture Matrix

| Area | Fixture | Expected support |
| --- | --- | --- |
| Heading and paragraph | `simple-html.json`, `html-core-text-structure.json` | `core/heading`, `core/paragraph` |
| Lists | `html-core-text-structure.json` | `core/list`, `core/list-item` |
| Grouped description lists | `fixtures/websites/37-art-gallery-exhibition/current-exhibition.html`, `fixtures/websites/33-sports-team-league/team-roxbury-roar.html` | Observed corpus sources preserve valid `dl > div > dt/dd` row topology through the consumer-namespaced `description-list` companion; the synthetic `tests/fixtures/description-list-grouped-schedule.html` covers the closed wrapper-attribute policy. |
| Tabs | `html-tabs.json` | ARIA tab lists/panels and bounded CSS radio-label tabs emit the WordPress 7.1 Core Tabs family |
| Quotes | `html-core-text-structure.json`, `html-figure-quote-media.json` | `core/quote`, `core/pullquote`, figure-wrapped testimonial quotes with `figcaption` citation |
| Code | `html-core-text-structure.json` | `core/code`, `core/preformatted` |
| Tables | `html-core-media-actions.json` | `core/table` with head/body/caption attrs |
| Images | `html-core-media-actions.json`, `html-figure-quote-media.json` | `core/image` with URL, alt, dimensions, caption, identity, size, and class attrs |
| Media and text | `html-media-text.json` | `core/media-text` for strict two-pane image/video and text layouts with an authored horizontal mechanism (`display:flex`/`grid`, a usable grid template, or round-trip `wp-block-media-text` markup); matched `section`/`article` containers emit the block's canonical `div` wrapper; gates fail closed — mechanism-less containers, floated panes, unresolvable `var()` layout values, inherited RTL, and grid templates that cannot express a `mediaWidth` all decline into existing columns/group/author-layout handling |
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
| Supported | Heading, paragraph, unordered/ordered list, quote, pullquote, code, preformatted, table, image, media-text, buttons/button, tabs, shortcode | Fixtures assert the block names and representative attrs currently emitted by `HtmlTransformer`. Tabs require the WordPress 7.1 Core Tabs family and a clean one-to-one control/panel mapping. Media-text requires exactly two element children: one pure image/video side and one text-bearing side; ambiguous layouts retain existing columns/group behavior. |
| Unsupported fallback | Unknown/custom elements, SVG markup, form controls, other unsupported top-level HTML | Fallbacks use `type: unsupported_element`, include the source tag, selector, caller source/scope when provided, sanitized HTML, and increment `coverage.0.fallback_count`. |
| Context-required | Interactive/form behavior, embeds, advanced layout semantics, raw-handler hooks | These require WordPress/Gutenberg runtime context or richer product converter behavior and remain outside the PHP transformer's supported slice. |
| Gutenberg editor validation | Gap | The repository has no browser harness that boots Gutenberg, registers generated companion blocks, and validates load/edit/save output. `wp_block_validity` is a PHP structural and canonical save-shape check; the WordPress integration test and Playwright visual-parity tooling do not exercise the editor. |
| Captioned figure with a paragraph media carrier | Accepted height deviation | `<figure><p><img></p><figcaption>` converts to native `core/image` with a caption (#2018). The inner `p` is a transparent media carrier and is not reintroduced. Do not copy its box, or any in-flow image margin it accidentally preserved, onto the emitted image. Consumers should exclude captioned-figure pages from strict document-height parity. Decision and measurements: #2043. |

## Captioned figure promotion (#2043)

Markdown that renders `![alt](src)` plus a caption typically produces `<figure><p><img></p><figcaption>`. The transformer promotes that shape to `core/image` and drops the inner `p`. That is the correct WordPress representation. Re-wrapping the image to chase a height number would undo #2018.

The inner paragraph is not a spacing donor:

- Author CSS on the reproduction source zeros `p` and `figure > p` (Tailwind preflight plus typography `figure > *` / `figure > p`).
- Measured `p` margin on that source is `0` at both 1280×900 and 390×844. `p` height equals the image height.

The extra source height is an in-flow `.prose img` margin (2em at `xl:prose-xl`, 1.714em at `prose-sm`) that only applies while the image is **not** a direct `figure` child. Typography already zeros `figure > *`. Nested inside a `p`, the image keeps the in-flow margin; those margins collapse through the paragraph and become a gap above the figcaption. After promotion the image *is* `figure > *`, so the author's own reset applies. That is the shape a hand-written `<figure><img><figcaption>` would have had.

Unary-wrapper promotion (#926 / #2040) does not argue for copying this gap. It transfers a wrapper's own representable presentation when the wrapper boundary is proven unnecessary. The paragraph's vertical box is empty. #2040 also keeps unary paragraph margin wrappers because `p` vs `div` user-agent boxes are not equivalent. The lost pixels here are not even the paragraph's box — they are a descendant-selector accident.

Viewport dependence follows from those em-based image margins plus image used-width, not from a uniform per-figure UA margin:

| viewport | prose size | `.prose img` margin | figcaption `margin-top` | gap lost per figure | ×8 figures |
| --- | --- | ---: | ---: | ---: | ---: |
| 1280×900 | `xl:prose-xl` (20px) | 40px | 18px | 22px | **176px** |
| 390×844 | `prose-sm` (14px) | 24px | 8px | 16px | **128px** |

Unwrapping the inner `p` on the captured source itself changes document height by those totals (−176 / −128). Live vs imported `/hyundai-i30n/` is **−146** desktop because the same +30 article residual seen on the other post routes offsets the figure gap (−176 + 30). Mobile is near-exact on the measured import because the imported content column is 16px wider (`max-sm:px-2` not occupying the same box), so images render taller and cancel most of the 128px gap.

Carrying that leaked image margin onto `core/image` would fight the author's `figure > * { margin: 0 }`, invent spacing a correctly nested figure never had, and is not "the wrapper's representable vertical spacing." Accept the shorter imported page. Exclude captioned-figure routes from strict height parity.
