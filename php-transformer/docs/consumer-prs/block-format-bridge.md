# Consumer PR Plan: block-format-bridge

## Phase-1 PR Goal

Keep `chubes4/block-format-bridge` as a temporary downstream format-conversion compatibility layer while routing HTML, Markdown, and serialized block conversions through `php-transformer` adapters.

php-transformer is product-level primitive and old repos are downstream consumers.

Branch: `cook/php-transformer-format-wrapper`.

Commit sequence:

1. `Add transformer dependency for format bridge review`: add the path repository, requirement, and autoload wiring without changing adapter defaults.
2. `Add transformer-backed BFB adapters`: add BFB-owned adapters that implement current BFB interfaces and delegate internally.
3. `Preserve capability and report contracts`: add transformer metadata and fixture comparisons while keeping old report keys stable.
4. `Switch eligible conversions to transformer adapters`: change defaults only after current conversion tests and Static Site Importer-facing report checks pass.

## Composer Change

During review, add the transformer dependency beside the existing package requirements:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../blocks-engine@cook-php-transformer-migration-no-perma-legacy/php-transformer",
      "options": {
        "symlink": true
      }
    },
    {
      "type": "vcs",
      "url": "https://github.com/chubes4/html-to-blocks-converter"
    }
  ],
  "require": {
    "php": "^8.1",
    "automattic/blocks-engine-php-transformer": "dev-cook/php-transformer-migration-no-perma-legacy as 0.1.x-dev",
    "league/commonmark": "^2.5",
    "league/html-to-markdown": "^5.1"
  }
}
```

Before merge, replace the path-only development constraint with the first tagged transformer release.

Do not add `replace` or `provide` for `chubes4/block-format-bridge` in `automattic/blocks-engine-php-transformer`. BFB owns `bfb_*` functions, adapter registration, REST/CLI/ability behavior, capability reports, and package metadata. The compatibility release should require the tagged transformer package instead.

Recommended merge constraint after the first transformer tag: `automattic/blocks-engine-php-transformer:^0.1.0`. Keep existing BFB constraints for `league/commonmark` and `league/html-to-markdown` until the wrapper no longer owns the compatibility adapter path. If BFB tags an initial transformer-backed compatibility release, document the transformer minimum and any wrapper package floor needed by Static Site Importer.

## README And Issue Continuity

Add this banner near the top of the downstream README:

```markdown
> **Continuity notice:** `chubes4/block-format-bridge` remains supported for existing Composer and WordPress plugin consumers while the canonical format transformation implementation moves to `automattic/blocks-engine-php-transformer`. New reusable format primitives should be proposed upstream in Blocks Engine. Compatibility bugs for `bfb_*`, adapters, capabilities, REST/CLI/ability surfaces, and package installability remain welcome here until a tagged direct-migration path is published.
```

Add issue template guidance that keeps reports about BFB public entrypoints in BFB and links upstream issues only when the blocker is a missing reusable transformer API, result-envelope field, diagnostic, or fixture.

Do not archive this repository in the wrapper PR. Keep the issue tracker open until Static Site Importer and supported external consumers no longer require BFB functions, adapters, commands, abilities, or the old Composer name.

## File-Level Patch Skeleton

```diff
composer.json
  + repositories[].type=path url=../blocks-engine@cook-php-transformer-migration-no-perma-legacy/php-transformer
  + require.automattic/blocks-engine-php-transformer=dev-cook/php-transformer-migration-no-perma-legacy as 0.1.x-dev
library.php or plugin bootstrap
  + require vendor/autoload.php before adapter registry setup
includes/adapters/*
  + transformer-backed BFB_Format_Adapter implementations
includes/capabilities* or report helpers
  ~ add transformer package/version metadata without removing current keys
tests/*
  + conversion/report parity cases for HTML, Markdown, blocks, and unsupported fragments
README.md
  ~ document BFB as compatibility facade over transformer adapters
```

## Public Function Mapping

| Current public surface | Transformer target | Phase-1 wrapper behavior |
| --- | --- | --- |
| `bfb_convert( string $content, string $from, string $to, array $options = array() )` | `FormatBridge::convert()` | Keep string return shape and route through transformer bridge when both formats are supported. |
| `bfb_to_blocks( string $content, string $from, array $options = array() )` | `FormatBridge::toBlocks()` or registered transformer adapter | Return parse-block-compatible arrays exactly as current callers expect. |
| `bfb_normalize( string $content, string $format, array $options = array() )` | `Normalizer::normalize()` | Preserve `string|WP_Error`; translate transformer diagnostics to `WP_Error` only at the BFB boundary. |
| `bfb_convert_fragment( string $html, array $options = array() )` | `HtmlTransformer::transform()` plus fragment scope options | Preserve the current envelope keys: `success`, `status`, `content`, `serialized_blocks`, `blocks`, `diagnostics`, `provenance`, `report`. |
| `bfb_conversion_report( string $content, string $from, array $options = array() )` | `TransformerResult::toArray()` plus BFB quality analysis | Keep existing quality fields and add transformer package/version metadata. |
| `bfb_analyze_blocks( array $blocks )` | Result diagnostics helper or local compatibility analyzer | Keep local analyzer until transformer exposes all existing quality fields. |
| `bfb_capabilities()` | Transformer capability report | Preserve old capability keys and include transformer availability under a new metadata key. |
| `bfb_get_adapter( string $slug )` | `AdapterRegistry` | Continue returning `BFB_Format_Adapter` instances; adapters may delegate to transformer classes internally. |
| `BFB_Format_Adapter` | `FormatAdapterInterface` | Keep the BFB interface stable and bridge adapter implementations internally. |
| `BFB_Adapter_Registry` | `AdapterRegistry` | Keep filter-aware registration behavior; do not expose transformer registry directly from BFB. |

## PR Steps

1. Add the transformer dependency and autoload it in the existing BFB bootstrap.
2. Introduce BFB-owned adapter wrappers that implement `BFB_Format_Adapter` and delegate to transformer format adapters.
3. Preserve all existing BFB filters/actions listed by `bfb_capabilities()`.
4. Add transformer metadata to capabilities without removing current `bridge`, `formats`, `conversions`, `h2bc`, `block_coverage`, `hooks`, or `abilities` keys.
5. Run current BFB smoke tests against the path repository.
6. Document fixture-level differences in the PR body before changing default adapters.

## Acceptance Criteria

- `bfb_convert()`, `bfb_to_blocks()`, `bfb_normalize()`, `bfb_convert_fragment()`, and `bfb_conversion_report()` keep their current return types.
- Existing REST, CLI, ability, hook, and filter tests pass.
- Capabilities report includes transformer metadata while preserving old keys.
- Static Site Importer can keep calling `bfb_convert()` and `bfb_conversion_report()` unchanged during its compatibility phase.
- The PR does not move import, filesystem, theme, or activation logic into BFB.
- The wrapper does not require `php-transformer` package metadata, namespaces, release labels, or code paths to mention `block-format-bridge`.

Acceptance commands:

```sh
composer validate
composer test
git diff --check
```

Rollback plan: revert the adapter-default switch first. If transformer metadata changes break consumers, keep adapter classes but remove metadata additions from reports until the upstream contract is fixed.

## Release Playbook

Release steps:

1. Replace the review path repository with the tagged transformer constraint, expected `^0.1.0` for the first transformer-backed BFB release.
2. Keep `league/commonmark`, `league/html-to-markdown`, and any existing BFB runtime dependencies pinned by the repo's current policy; only change them when required by the wrapper PR.
3. Run the smoke commands below against the tagged dependency and record the capability/report comparison table in the PR body.
4. Confirm Static Site Importer can still call `bfb_convert()`, `bfb_convert_fragment()`, and `bfb_conversion_report()` without code changes.
5. Tag only after old capability keys and conversion report keys are present with transformer metadata added under new metadata fields.

SemVer guidance:

| Change | Version guidance |
| --- | --- |
| First transformer-backed adapter release | `0.1.0`, unless the repo already has a higher compatible release line. |
| Fixes to adapter delegation, metadata reporting, or Composer installability with unchanged BFB public APIs | Patch release. |
| Additional format coverage or transformer-backed conversions with unchanged `bfb_*` contracts | Minor release while `<1.0.0`. |
| Removing `bfb_*`, changing conversion/report return types, or dropping adapter interfaces | Major release or archive once consumers have migrated. |

Release note text:

```md
## Transformer-backed format bridge compatibility release

This release keeps Block Format Bridge as a compatibility facade while routing eligible conversions through `automattic/blocks-engine-php-transformer` adapters.

- Dependency floor: `automattic/blocks-engine-php-transformer:^0.1.0`.
- Public API: `bfb_*` functions, adapter interfaces, CLI/ability surfaces, hooks, filters, capability keys, and report shapes remain supported.
- Smoke coverage: `composer validate`, `composer test`, capability report comparison, Static Site Importer-facing fragment/report fixture comparison, and `git diff --check`.
- Rollback: revert the adapter-default switch first; pin the previous BFB release if transformer metadata or adapter loading regresses production consumers.
- Exit path: archive after supported callers use `FormatBridge` directly, or keep a deprecation-only thin shim over tagged transformer APIs.
```

Smoke tests:

```sh
composer validate
composer test
php -r 'require "vendor/autoload.php"; var_export( function_exists( "bfb_convert" ) && function_exists( "bfb_capabilities" ) );'
git diff --check
```

Archive/thin-shim decision gate: archive if Static Site Importer and supported external consumers no longer call `bfb_*`, use `BFB_Format_Adapter`, or depend on BFB CLI/ability/package metadata. Keep a thin shim if any supported external consumer still requires those public entrypoints.

## Archive Or Thin-Shim Exit

Archive this repository after Static Site Importer and any supported product adapters call `FormatBridge` directly and no supported consumer still requires `bfb_*` functions, `BFB_Format_Adapter`, CLI commands, abilities, or package metadata.

If external consumers still require the old package name, keep a thin shim that only preserves public BFB entrypoints, loads tagged `php-transformer`, emits deprecation notices where appropriate, and delegates to `FormatBridge` or other tagged transformer APIs. The shim must not add new conversion behavior or require `php-transformer` to carry BFB-specific compatibility branches.

## Blockers To Resolve Upstream First

- Missing transformer adapters for every format currently routed by `bfb_convert()` and `bfb_to_blocks()`.
- Missing fragment-scope option support in `HtmlTransformer`.
- Missing capability report data needed by BFB abilities and diagnostics.
