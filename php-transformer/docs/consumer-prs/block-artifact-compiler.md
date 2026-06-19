# Consumer PR Plan: block-artifact-compiler

## Phase-1 PR Goal

Keep `chubes4/block-artifact-compiler` as a temporary downstream compatibility package for website artifact compilation while delegating canonical compilation to `Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler`.

php-transformer is product-level primitive and old repos are downstream consumers.

Branch: `cook/php-transformer-artifact-wrapper`.

Commit sequence:

1. `Add transformer dependency for artifact compiler review`: add the path repository, requirement, and autoload wiring without changing public output.
2. `Add BAC result mapper for transformer output`: introduce a BAC-owned mapper from `TransformerResult::toArray()` to existing BAC report fields.
3. `Delegate compiler entrypoints`: route `Block_Artifact_Compiler` and global functions through the transformer plus mapper.
4. `Lock artifact parity coverage`: update contract smoke fixtures and README examples for the compatibility facade.

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

Do not add `replace` or `provide` for `chubes4/block-artifact-compiler` in `automattic/blocks-engine-php-transformer`. BAC owns its plugin/package bootstrap, global functions, legacy class names, report summary helpers, and Static Site Importer-facing report fields. The compatibility release should require the tagged transformer package instead.

Recommended merge constraint after the first transformer tag: `automattic/blocks-engine-php-transformer:^0.1.0`. Tag the BAC compatibility release after the transformer result-envelope fields used by BAC are stable enough to map to old report keys.

## README And Issue Continuity

Add this banner near the top of the downstream README:

```markdown
> **Continuity notice:** `chubes4/block-artifact-compiler` remains supported for existing Composer and WordPress plugin consumers while the canonical artifact compilation implementation moves to `automattic/blocks-engine-php-transformer`. New reusable compiler primitives should be proposed upstream in Blocks Engine. Compatibility bugs for BAC functions/classes, report fields, summaries, and package installability remain welcome here until a tagged direct-migration path is published.
```

Add issue template guidance that keeps reports about BAC public entrypoints and report compatibility in BAC, while upstreaming missing reusable artifact compiler APIs, result fields, diagnostics, and fixture gaps to `Automattic/blocks-engine`.

Do not archive this repository in the wrapper PR. Keep the issue tracker open until supported consumers no longer require BAC functions/classes, report helpers, or the old Composer name.

## File-Level Patch Skeleton

```diff
composer.json
  + repositories[].type=path url=../blocks-engine@cook-php-transformer-migration-no-perma-legacy/php-transformer
  + require.automattic/blocks-engine-php-transformer=dev-cook/php-transformer-migration-no-perma-legacy as 0.1.x-dev
library.php
  + require vendor/autoload.php before global BAC functions are exposed
src/Block_Artifact_Compiler.php or equivalent
  ~ compile() and compile_fragment() delegate to ArtifactCompiler through a BAC mapper
src/*Result*Mapper*.php or includes/report helpers
  + local mapper preserving current BAC report keys consumed by Static Site Importer
tests/contract-smoke.php
  ~ compare legacy report fields against transformer-backed report fields
README.md
  ~ describe BAC as a compatibility facade and list tagged dependency requirement
```

## Public Function Mapping

| Current public surface | Transformer target | Phase-1 wrapper behavior |
| --- | --- | --- |
| `bac_compile_website_artifact( array $artifact, array $options = array() )` | `ArtifactCompiler::compile( array $artifact ): TransformerResult` | Return the existing BAC array shape by mapping `TransformerResult::toArray()` into current report fields. Keep BAC options local until transformer compiler options are public. |
| `bac_compile_fragment( string $content, string $source = 'fragment', string $format = 'html', array $options = array() )` | `ArtifactCompiler::compileFragment()` or `FormatBridge` plus result envelope | Preserve the current array shape for fragment compiler callers. |
| `bac_summarize_result( array $compiled )` | Transformer summary helper or BAC-owned mapper | Keep summary keys stable for Static Site Importer import reports and CLI output. |
| `Block_Artifact_Compiler::compile()` | `ArtifactCompiler::compile()` | Legacy class method delegates to the transformer and maps the result. |
| `Block_Artifact_Compiler::compile_fragment()` | `ArtifactCompiler` fragment path | Legacy method delegates and maps result shape. |
| `Block_Artifact_Compiler::summarize_result()` | Transformer summary helper or local mapper | Keep as a compatibility method until Static Site Importer no longer calls BAC summary helpers. |
| `bac_sanitize_key()` | WordPress runtime adapter or local compatibility helper | Keep local helper unless transformer exposes a package-safe equivalent. |
| `bac_json_encode()` | PHP native `json_encode()` wrapper or transformer helper | Keep local helper for report compatibility and JSON flags. |

## PR Steps

1. Add the transformer dependency and autoload it before `library.php` exposes BAC functions.
2. Replace `Block_Artifact_Compiler::compile()` internals with transformer delegation plus a BAC report mapper.
3. Keep private normalization/report helpers only where they adapt existing public BAC report shapes.
4. Port `tests/contract-smoke.php` expectations to compare legacy output fields against transformer-backed fields.
5. Keep `bac_compile_fragment()` working for current BFB and Static Site Importer callers.
6. Update README examples to describe BAC as a compatibility facade over the transformer.

## Acceptance Criteria

- `composer test` passes in `block-artifact-compiler`.
- `bac_compile_website_artifact()` returns the existing keys consumed by Static Site Importer, including documents, diagnostics, input/source reports, block reports, and summary-compatible fields.
- `bac_summarize_result()` output remains stable for current import-report summaries.
- Empty artifact, schema-less artifact, markdown, MDX, nested source, and generated block fixture cases stay covered.
- Any missing transformer report field is tracked upstream before the BAC wrapper paper-cuts around it.
- The wrapper does not require `php-transformer` package metadata, namespaces, release labels, or code paths to mention `block-artifact-compiler`.

Acceptance commands:

```sh
composer validate
composer test
git diff --check
```

Rollback plan: revert compiler entrypoint delegation first and keep the mapper commit only if it remains unused and tests pass. Revert the dependency/autoload commit if Composer installability regresses.

## Release Playbook

Release steps:

1. Replace the review path repository with the tagged transformer constraint, expected `^0.1.0` for the first release.
2. Confirm the BAC mapper is the only place translating transformer envelopes to old BAC report keys.
3. Run the smoke commands below against the tagged dependency and record the artifact fixture comparison table in the PR body.
4. Confirm Static Site Importer fixture imports still receive the BAC keys they consume for documents, diagnostics, summaries, and `wordpress_artifacts`.
5. Tag only after public BAC functions/classes and summary helpers keep their old return shapes.

SemVer guidance:

| Change | Version guidance |
| --- | --- |
| First transformer-backed BAC wrapper release | `0.1.0`, unless the repo already has a higher compatible release line. |
| Fixes to result mapping, diagnostics mapping, or installability with unchanged BAC public APIs | Patch release. |
| Additional artifact fixture support with unchanged public report shape | Minor release while `<1.0.0`. |
| Removing BAC functions/classes or changing report keys consumed by products | Major release or archive after consumers migrate. |

Release note text:

```md
## Transformer-backed artifact compiler compatibility release

This release preserves the existing Block Artifact Compiler public API while delegating eligible website artifact compilation to `automattic/blocks-engine-php-transformer`.

- Dependency floor: `automattic/blocks-engine-php-transformer:^0.1.0`.
- Public API: BAC functions, classes, summary helpers, CLI/ability surfaces, and report keys consumed by Static Site Importer remain supported.
- Smoke coverage: `composer validate`, `composer test`, artifact contract-smoke fixture comparison, Static Site Importer report-key check, and `git diff --check`.
- Rollback: revert compiler entrypoint delegation first; pin the previous BAC release if mapped report fields regress consumers.
- Exit path: archive after supported import paths call `ArtifactCompiler` directly, or keep a deprecation-only thin shim that maps tagged transformer envelopes to old BAC report keys.
```

Smoke tests:

```sh
composer validate
composer test
php -r 'require "vendor/autoload.php"; var_export( function_exists( "bac_compile_website_artifact" ) && function_exists( "bac_summarize_result" ) );'
git diff --check
```

Archive/thin-shim decision gate: archive if Static Site Importer and supported external consumers no longer call BAC functions/classes, CLI commands, abilities, report helpers, or Composer package metadata. Keep a thin shim if any supported external consumer still requires old BAC entrypoints or report keys.

## Archive Or Thin-Shim Exit

Archive this repository after Static Site Importer and any supported product import paths call `ArtifactCompiler` directly and no supported consumer still requires BAC functions, BAC classes, CLI commands, abilities, report helpers, or package metadata.

If external consumers still require the old package name, keep a thin shim that only preserves public BAC entrypoints, loads tagged `php-transformer`, emits deprecation notices where appropriate, and maps tagged transformer envelopes to old report keys. The shim must not add new artifact compilation behavior or require `php-transformer` to carry BAC-specific compatibility branches.

## Blockers To Resolve Upstream First

- Missing `ArtifactCompiler::compileFragment()` equivalent for `bac_compile_fragment()`.
- Missing public compiler options on `ArtifactCompiler::compile()` for BAC option forwarding.
- Missing document artifact fields required by `Static_Site_Importer_Source_Page::from_wordpress_document_artifact()`.
- Missing diagnostics/report keys required by Static Site Importer quality gates.
