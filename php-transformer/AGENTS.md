# php-transformer: guide for contributors and coding agents

The transformer converts static HTML and CSS into WordPress block markup and a
WordPress site plan. Every behavior has one owning class. A change is easiest
to review, and keeps the codebase easy to extend, when it goes into that owner
and reuses the readers listed below.

## Where things live

| Concern | Owner |
| --- | --- |
| Converting one element kind to blocks | `src/HtmlToBlocks/Elements/*` (an `ElementConverter`, registered in `HtmlCompilation`'s ordered converter registry) |
| Recognizing larger patterns (navigation, carousels, forms, galleries) | `src/HtmlToBlocks/Patterns/*`, `src/HtmlToBlocks/Generators/*` |
| Computed style of a source element | `StyleResolver` and the owners it exposes as public readonly properties: `$styleResolver->cascade` (`SourceCascade`), `$styleResolver->authorViewport` (`AuthorViewportDeclarations`) and `$styleResolver->customProperties` (`CustomPropertyResolver`) |
| Resolve `var()` in a value | `$styleResolver->customProperties`: `resolve()` (reference viewport), `resolveCascaded()` (unconditional cascade), `resolveAtViewport()` |
| Re-emitting author stylesheets | `src/HtmlToBlocks/Style/AuthorStylesheetProjector.php` |
| Site plan composition | `src/WordPressSitePlan/*`; `SitePlanRun` holds the state of one composition |
| Orchestration | `src/ArtifactCompiler/*`, the composition root |
| Shared primitives with no conversion knowledge | `src/Css/*`, `src/Support/*`, `src/Contract/*` |

Layers depend in one direction, enforced by
`tests/contract/layer-direction.php`:

- Shared primitives (`Css`, `Support`, `AssetAnalysis`) import no layer above
  them.
- Materialization (`WordPressSitePlan`, `WordPress`, `StaticSite`) does not
  import `HtmlToBlocks`.
- `HtmlToBlocks` imports materialization only through the pinned set in that
  test.
- `ArtifactCompiler` composes the layers and may import any of them.

## Reading CSS

Use these readers rather than writing a regex, `explode(';')` or a local parse
helper; `tests/contract/declaration-readers.php` fails on a new
`(?:^|;)\s*property:` probe. They handle strings, comments, functions, `!important` and shorthand
order. Each has one implementation, so a fix in it reaches every caller.
`SourceDeclarations` lives in `HtmlToBlocks`; code in the lower layers reads
declarations through the `Css` readers.

| To read | Use |
| --- | --- |
| An element's `style` attribute as a property map | `SourceDeclarations::inline($element)` |
| One property's value, lowercased and without `!important` | `CssValueInspector::declared(SourceDeclarations::parse($style), 'display')` |
| Whether declarations hide the box, or lay out a flex column | `CssValueInspector::hidesBox($declarations)`, `CssValueInspector::isFlexColumn($declarations)` |
| Any declaration list as a property map (physical sides, font longhands) | `SourceDeclarations::parse($css)` |
| A declaration list with authored property names kept verbatim | `SourceDeclarations::verbatim($css)` |
| Declarations in source order, from any layer | `CssRuleAnalyzer::declarationList($css)` |
| Selected properties from a rule body | `CssRuleAnalyzer::declarations($body, $properties)` |
| Custom property declarations | `CssRuleAnalyzer::customProperties($css)` |
| Splitting values or lists at top level | `CssValueSplitter::splitTopLevel()`, `CssValueSplitter::splitTopLevelWhitespace()` |
| Selectors: parsing, matching, specificity | `CssSelectorMatcher::parse()`, `CssSelectorMatcher::specificityCounts()`, `CssSelectorTokenizer::tokenize()` |
| Lengths, colors, fonts | `CssLength::toPx()`, `CssColor::parse()`, `CssFontShorthand::expand()`, `CssFontFamily::names()` |
| DOM attributes and markup | `SourceDom` |

To decide which declaration wins on an element, ask the cascade
(`StyleResolver`, `SourceCascade`) rather than re-matching selectors locally.
When a reader is missing a capability, extend the reader so every caller gains
it.

## Making a change

- Put new behavior in the class that owns the concern. When none fits, add a
  small focused class next to its peers rather than growing a large file.
- When code you need already exists as a private helper elsewhere, move it to a
  shared owner and call it from both places.
- Prefer the general rule that explains a case over a branch for one site,
  theme or plugin.
- Remove code that a change makes unused in the same pull request.

## Tests

- Unit and contract tests are standalone PHP scripts under `tests/unit` and
  `tests/contract`; run one with `php tests/unit/<name>.php`. `composer test`
  runs the suite and parity fixtures; `composer lint` runs PHPCS and PHPStan.
- A test earns its place by failing without the change and passing with it,
  through the public interface (`HtmlTransformer`, `ArtifactCompiler`).
- Parity fixtures in `tests/fixtures/parity` pin end-to-end output. When a
  change alters output, update the fixture and explain the difference in the
  pull request.

## Pull requests

Describe why, what changed, and how output changed. A refactor states that
output is identical and how that was checked; a behavior change shows the
before and after for the affected markup.
