# Consumer PR Plan: html-to-blocks-converter

## Decision

Make `chubes4/html-to-blocks-converter` the first downstream consumer PR.

Reasons:

- It owns the current `html_to_blocks_*` global function surface that downstream packages already call.
- `block-format-bridge`, `block-artifact-compiler`, and product adapters should consume the canonical transformer after the raw HTML facade is proven.
- The PR can be a bounded delegation/shim change with no product-specific behavior added to `php-transformer`.
- Its test suite already contains the widest raw-handler fixture matrix, fallback-hook checks, REST/write-path checks, and PHP-scoper callback checks.

Do not start with Static Site Importer or BFB. Those consumers should wait until the old raw-handler facade proves that supported HTML output, fallback observability, and package/plugin loading survive transformer delegation.

## Phase-1 PR Goal

Turn `chubes4/html-to-blocks-converter` into a temporary downstream compatibility package that keeps its current WordPress plugin and function surface while delegating canonical HTML conversion to `Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer`.

php-transformer is product-level primitive and old repos are downstream consumers.

Downstream branch: `cook/php-transformer-html-wrapper`.

Upstream review dependency while PR #1 is open: `/Users/chubes/Developer/blocks-engine@cook-php-transformer-first-consumer-plan/php-transformer`, branch `cook/php-transformer-first-consumer-plan`.

Commit sequence:

1. `Add transformer package dependency for review`: add the path repository, requirement, and autoload include while preserving current behavior.
2. `Delegate supported HTML conversion to HtmlTransformer`: route `html_to_blocks_raw_handler()` and `html_to_blocks_convert()` through a local compatibility adapter behind the existing function names.
3. `Preserve fallback hooks and legacy inspection`: map transformer fallbacks back to `core/html` blocks and `html_to_blocks_unsupported_html_fallback`, while keeping legacy classes/functions loadable.
4. `Compare raw-handler parity fixtures`: add old-versus-transformer fixture coverage in the old repo and document intentional differences in the PR body.

## Composer Change

During review, add the transformer path repository and requirement:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../blocks-engine@cook-php-transformer-first-consumer-plan/php-transformer",
      "options": {
        "symlink": true
      }
    }
  ],
  "require": {
    "php": ">=8.1",
    "automattic/blocks-engine-php-transformer": "dev-cook/php-transformer-first-consumer-plan as 0.1.x-dev"
  }
}
```

Before merge, replace the path-only development constraint with the first tagged transformer release.

Do not add `replace` or `provide` for `chubes4/html-to-blocks-converter` in `automattic/blocks-engine-php-transformer`. The old package name owns plugin activation, Composer file autoloading, `html_to_blocks_*` functions, legacy classes, and hooks. The wrapper should require the tagged transformer release instead.

Recommended merge constraint after the first transformer tag: `automattic/blocks-engine-php-transformer:^0.1.0`. If the wrapper keeps its current package versioning scheme, tag the compatibility release in that scheme and document the minimum transformer version in release notes.

## README And Issue Continuity

Add this banner near the top of the downstream README:

```markdown
> **Continuity notice:** `chubes4/html-to-blocks-converter` remains supported for existing Composer and WordPress plugin consumers while the canonical HTML transformation implementation moves to `automattic/blocks-engine-php-transformer`. New reusable transform primitives should be proposed upstream in Blocks Engine. Compatibility bugs for `html_to_blocks_*`, plugin hooks, and package installability remain welcome here until a tagged direct-migration path is published.
```

Add issue template guidance that routes package-name and hook reports here, and routes missing reusable transformer capabilities upstream to `Automattic/blocks-engine`.

Do not archive this repository in the wrapper PR. Keep the issue tracker open until supported consumers no longer require `html_to_blocks_*`, the plugin package, or the old Composer name.

## File-Level Patch Skeleton

```diff
composer.json
  + repositories[].type=path url=../blocks-engine@cook-php-transformer-first-consumer-plan/php-transformer
  + require.php=>=8.1
  + require.automattic/blocks-engine-php-transformer=dev-cook/php-transformer-first-consumer-plan as 0.1.x-dev
library.php
  + require vendor/autoload.php if readable before legacy function declarations
  = keep autoload.files entry so Composer consumers still get the global facade
raw-handler.php
  + use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer
  + add local html_to_blocks_transformer() factory/cache or equivalent private helper
  ~ html_to_blocks_raw_handler() preserves pre-wrapped block markup handling, shortcode splitting, empty handling, and normalization; each HTML piece delegates supported conversion to HtmlTransformer
  ~ html_to_blocks_convert() delegates to HtmlTransformer, then maps result fallbacks to legacy core/html blocks and fallback hooks
  = keep html_to_blocks_normalise_blocks(), html_to_blocks_create_unsupported_html_fallback_block(), and helper functions public for compatibility
includes/*
  = keep HTML_To_Blocks_* classes loadable for inspection/tests; do not advertise them as new extension points
tests/fixtures/*
  + representative raw-handler parity fixtures
tests/*
  + assertions comparing legacy block-array shape to transformer-backed output through html_to_blocks_raw_handler()
README.md
  ~ document compatibility facade status and tagged dependency requirement
```

## Adapter / Delegation Plan

The downstream adapter lives in `html-to-blocks-converter`; do not add old-repo-specific compatibility code to `php-transformer`.

1. `library.php` keeps the current version registry and `autoload.files` behavior. It loads `vendor/autoload.php` before the initializer so `HtmlTransformer` is available when `raw-handler.php` is required.
2. `raw-handler.php` keeps `html_to_blocks_raw_handler( $args )` as the raw-handler entry point. It should still read `$args['HTML']`, return `array()` for empty input, preserve existing `parse_blocks()` handling for already serialized blocks, run `html_to_blocks_shortcode_converter()`, and apply `html_to_blocks_normalise_blocks()` where the current code does.
3. `html_to_blocks_convert( $html, $args = array() )` becomes the transformer bridge. It calls `( new HtmlTransformer() )->transform( $html )` and returns a block array, not a `TransformerResult` envelope.
4. Supported transformer output returns `$result->blocks` with the current block-array shape. The facade must not expose transformer namespaces or result envelopes to existing callers.
5. Transformer fallbacks are converted back to legacy `core/html` fallback blocks by calling `html_to_blocks_create_unsupported_html_fallback_block( $fallback['html'], $context )` for each fallback with HTML. The context should include `reason => 'no_transform'`, `tag_name => strtoupper( $fallback['tag'] ?? '' )`, `source => 'php-transformer'`, and the transformer fallback payload.
6. Existing observability remains local: `html_to_blocks_unsupported_html_fallback` still fires from `html_to_blocks_create_unsupported_html_fallback_block()`. Metrics hooks such as `html_to_blocks_convert_metrics` can either remain legacy-only for code paths that still use old transforms or be documented as unavailable until transformer metrics are public.
7. Keep the legacy transform registry/classes loaded so tests and any external inspection keep working. New conversion behavior should not call `HTML_To_Blocks_Transform_Registry::get_raw_transforms()` except in a temporary fallback path explicitly covered by tests.
8. If a fixture needs behavior that `HtmlTransformer` does not expose yet, stop and add the missing generic upstream API/fixture in `php-transformer` first. Do not add downstream shims for missing transformer features.

`docs/consumer-prs/examples/html-to-blocks-converter-wrapper.php` is the copyable minimal migration shape. The real downstream patch should preserve more of `raw-handler.php` than the example because the old repo owns WordPress hooks, shortcode splitting, normalization, fallback actions, and versioned plugin/package loading.

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

## Public Function Preservation

The first PR must preserve these callable names and return contracts:

- `html_to_blocks_raw_handler( $args ): array` remains available after Composer autoload and plugin activation. It accepts an array with `HTML` and optional conversion context and returns `parse_blocks()`-compatible block arrays.
- `html_to_blocks_convert( $html, $args = array() ): array` remains callable by direct consumers and internal hooks.
- `html_to_blocks_normalise_blocks( $html )`, `html_to_blocks_get_parsed_block_html( array $block )`, `html_to_blocks_can_skip_normalise_blocks( string $html )`, and helper functions used by tests remain available unless the PR proves no external/package path can call them.
- `html_to_blocks_create_unsupported_html_fallback_block( string $element_html, array $context = array() ): array` remains the only place that creates legacy fallback blocks and fires `html_to_blocks_unsupported_html_fallback`.
- `HTML_To_Blocks_Versions`, `HTML_To_Blocks_Transform_Registry`, `HTML_To_Blocks_Block_Factory`, `HTML_To_Blocks_Attribute_Parser`, `HTML_To_Blocks_HTML_Element`, `HTML_To_Blocks_SVG_Icon_Classifier`, and `html_to_blocks_classify_inline_svg_icon()` remain loadable for old tests and inspection.
- Existing hook names stay unchanged: `html_to_blocks_unsupported_html_fallback`, `html_to_blocks_convert_metrics`, `html_to_blocks_supported_post_types`, and `html_to_blocks_loaded`.

## PR Steps

1. Raise the package PHP constraint to `>=8.1` to match `php-transformer`.
2. Add the transformer dependency through the path repository while the PR is under review.
3. Load Composer autoload before the existing library bootstrap.
4. Replace the body of `html_to_blocks_raw_handler()` and `html_to_blocks_convert()` with transformer delegation while preserving current hooks and return shapes.
5. Keep local transform-registry classes loaded for compatibility and tests until no external callers remain.
6. Update fixtures to assert old raw-handler output equals transformer output for representative HTML.

## Migration Comparison Fixtures

No new upstream fixture is required before opening the first downstream PR. This branch already has shared parity fixtures for the supported slice the first PR should target:

- `tests/fixtures/parity/simple-html.json`
- `tests/fixtures/parity/html-core-text-structure.json`
- `tests/fixtures/parity/html-core-media-actions.json`
- `tests/fixtures/parity/unsupported-fallback.json`
- `tests/fixtures/parity/html-unsupported-context-required.json`

The old repo should add migration comparison fixtures/tests that run the public facade, not the transformer directly:

- `html_to_blocks_raw_handler( array( 'HTML' => '<h2>Section title</h2><p>Intro <strong>copy</strong>.</p>' ) )` returns `core/heading` and `core/paragraph` with matching attrs/content.
- A media/action fixture with `<table>`, `<figure><img>`, and adjacent links returns `core/table`, `core/image`, and `core/buttons`/`core/button` through the old function names.
- An unsupported fixture with `<custom-card>` or an unknown `<iframe>` returns a `core/html` fallback block and fires `html_to_blocks_unsupported_html_fallback` with the legacy context shape.
- A serialized-block fixture keeps the current `parse_blocks()` preservation path and does not route already valid block markup through `HtmlTransformer` unnecessarily.
- A shortcode fixture preserves existing shortcode splitting and `core/shortcode` output.

Only add a new upstream parity fixture in this repository if the downstream PR exposes a generic transformer gap, such as missing form/context-required fallback metadata, missing SVG materialization data, or missing public options for raw-handler context. Keep any such fixture generic and repo-neutral.

## Tests To Run In html-to-blocks-converter

Run these in the downstream repo after the adapter patch, without changing that repo from this planning slice:

```sh
composer validate
homeboy test
php tests/smoke-core-block-transform-matrix.php
php tests/smoke-unsupported-html-fallback-hook.php
php tests/smoke-raw-handler-context.php
php tests/smoke-block-serialization-fidelity.php
php tests/smoke-php-scoper-callback.php
php tests/smoke-scoped-rest-callback.php
php tests/core-block-coverage-docs-smoke.php
git diff --check
```

If the downstream PR adds a Composer script for the existing smoke/unit matrix, prefer that script and keep the explicit smoke commands in the PR body as the reviewer-facing evidence list. The GitHub workflow runs `homeboy test` across PHP 8.1, 8.2, 8.3, and 8.4.

## Acceptance Criteria

- Existing smoke and unit tests pass without reducing fixture coverage.
- `html_to_blocks_raw_handler( array( 'HTML' => '<p>Hello</p>' ) )` returns the same block-array shape as before.
- Existing fallback hooks still fire for unsupported HTML fragments.
- Composer autoload still exposes the public globals through `autoload.files`.
- The plugin still loads through `html-to-blocks-converter.php` and the version registry.
- No Static Site Importer or BFB product-specific behavior is added to this repository.
- The PR body documents any output differences with fixture names and links to upstream transformer issues for missing behavior.
- The wrapper does not require `php-transformer` package metadata, namespaces, release labels, or code paths to mention `html-to-blocks-converter`.

Acceptance commands:

```sh
composer validate
composer test
BLOCKS_ENGINE_PARITY_LEGACY=1 BLOCKS_ENGINE_PARITY_LEGACY_HTML_TO_BLOCKS_CONVERTER_PATH=/Users/chubes/Developer/html-to-blocks-converter composer test:migration:legacy-parity
git diff --check
```

The legacy parity command is optional local evidence for this planning branch. It is expected to skip fixtures marked as WordPress-runtime-dependent until the downstream PR carries equivalent tests inside `html-to-blocks-converter`.

Rollback plan: revert the default delegation commit first. If installability is broken, revert the dependency/autoload commit and restore the previous lockfile.

## Release Playbook

Release steps:

1. Replace the review path repository with the tagged transformer constraint, expected `^0.1.0` for the first release.
2. Confirm `composer.lock` contains the tagged transformer release and no `/Users/...` path repository source.
3. Run the smoke commands below against the tagged dependency.
4. Add the raw-handler parity table to the PR body with fixture names, legacy block count, transformer block count, fallback count, and intentional differences.
5. Tag the wrapper release only after public `html_to_blocks_*` helpers and `HTML_To_Blocks_*` classes keep their old return shapes.

SemVer guidance:

| Change | Version guidance |
| --- | --- |
| First transformer-backed wrapper release | `0.1.0`, unless the repo already has a higher compatible release line. |
| Fixes to delegation, diagnostics mapping, or installability with unchanged public helpers | Patch release. |
| Additional transformer coverage that keeps old helper return shapes stable | Minor release while `<1.0.0`. |
| Dropping old helpers/classes or changing raw-handler return shapes | Major release or archive instead of wrapper release. |

Release note text:

```md
## Transformer-backed HTML compatibility wrapper

This release preserves the existing `html_to_blocks_*` public helpers while delegating eligible HTML conversion to `automattic/blocks-engine-php-transformer`.

- Dependency floor: `automattic/blocks-engine-php-transformer:^0.1.0`.
- Public API: existing helper functions, plugin bootstrap behavior, fallback hooks, and raw-handler block-array shapes remain supported.
- Smoke coverage: `composer validate`, `composer test`, representative `html_to_blocks_raw_handler()` fixture comparison, and `git diff --check`.
- Rollback: pin the previous wrapper release or revert the default delegation commit while preserving inert dependency setup if tests still pass.
- Exit path: archive after supported callers stop using `html_to_blocks_*`; otherwise keep a deprecation-only thin shim over tagged transformer APIs.
```

Smoke tests:

```sh
composer validate
composer test
php -r 'require "vendor/autoload.php"; var_export( function_exists( "html_to_blocks_raw_handler" ) );'
git diff --check
```

Archive/thin-shim decision gate: archive if no supported product, Composer package, WordPress plugin, hook consumer, or documented integration calls `html_to_blocks_*` or instantiates `HTML_To_Blocks_*`. Keep a thin shim if any supported external consumer still depends on the package name or helpers.

## Archive Or Thin-Shim Exit

Archive this repository after supported consumers no longer call `html_to_blocks_*`, `HTML_To_Blocks_*`, or the plugin package directly.

If external consumers still require the old package name, keep a thin shim that only loads tagged `php-transformer`, emits deprecation notices where appropriate, and delegates public helpers/classes to `HtmlTransformer`. The shim must not add new HTML transformation behavior or require `php-transformer` to carry old-repo-specific compatibility branches.

## Blockers To Resolve Upstream First

- Missing public transformer option for raw-handler context propagation.
- Missing stable fallback diagnostic shape in `TransformerResult`.
- Missing transform inventory/capability API if downstream tests still need to inspect supported transforms.
