# Consumer PR Plan: html-to-blocks-converter

## Phase-1 PR Goal

Turn `chubes4/html-to-blocks-converter` into a temporary downstream compatibility package that keeps its current WordPress plugin and function surface while delegating canonical HTML conversion to `Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer`.

php-transformer is product-level primitive and old repos are downstream consumers.

Branch: `cook/php-transformer-html-wrapper`.

Commit sequence:

1. `Add transformer package dependency for review`: add the path repository, requirement, and autoload include while preserving current behavior.
2. `Add HtmlTransformer compatibility wrapper`: route public conversion functions through a wrapper mapper behind the existing function names.
3. `Compare raw-handler parity fixtures`: add old-versus-transformer fixtures and document any intentional differences in the PR body.

## Composer Change

During review, add the transformer path repository and requirement:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../blocks-engine@cook-php-transformer-migration-no-perma-legacy/php-transformer",
      "options": {
        "symlink": true
      }
    }
  ],
  "require": {
    "php": ">=8.1",
    "automattic/blocks-engine-php-transformer": "dev-cook/php-transformer-migration-no-perma-legacy as 0.1.x-dev"
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
  + repositories[].type=path url=../blocks-engine@cook-php-transformer-migration-no-perma-legacy/php-transformer
  + require.automattic/blocks-engine-php-transformer=dev-cook/php-transformer-migration-no-perma-legacy as 0.1.x-dev
library.php
  + require vendor/autoload.php before legacy function declarations
  ~ html_to_blocks_raw_handler() delegates to HtmlTransformer through a local mapper
  ~ html_to_blocks_convert() preserves filters/actions around transformer output
tests/fixtures/*
  + representative raw-handler parity fixtures
tests/*
  + assertions comparing legacy block-array shape to transformer-backed output
README.md
  ~ document compatibility facade status and tagged dependency requirement
```

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
- The wrapper does not require `php-transformer` package metadata, namespaces, release labels, or code paths to mention `html-to-blocks-converter`.

Acceptance commands:

```sh
composer validate
composer test
git diff --check
```

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
