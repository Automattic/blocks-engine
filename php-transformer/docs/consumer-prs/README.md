# Downstream Consumer PR Plans

These plans translate the first `php-transformer` adoption wave into concrete downstream PRs without changing the downstream repositories from this worktree.

php-transformer is product-level primitive and old repos are downstream consumers.

The old repositories are temporary wrappers or product consumers during migration. They must not become canonical package identity, namespace, release-label, or compatibility-policy inputs for `automattic/blocks-engine-php-transformer`.

## Repositories

| Repository | Branch | Phase-1 PR | Target dependency mode | Primary acceptance signal |
| --- | --- | --- | --- | --- |
| `chubes4/html-to-blocks-converter` | `cook/php-transformer-html-wrapper` | Add a temporary downstream wrapper around `HtmlTransformer`. | Composer path repository during review, tagged transformer release before merge. | Existing raw-handler fixtures pass through the wrapper with the same block arrays. |
| `chubes4/block-format-bridge` | `cook/php-transformer-format-wrapper` | Add transformer-backed format adapters in a temporary downstream wrapper while preserving BFB public functions. | Composer path repository during review, tagged transformer release before merge. | Existing conversion and capability tests pass with transformer metadata present. |
| `chubes4/block-artifact-compiler` | `cook/php-transformer-artifact-wrapper` | Delegate public compiler functions/classes to `ArtifactCompiler` from a temporary downstream wrapper. | Composer path repository during review, tagged transformer release before merge. | Existing contract smoke fixtures pass with identical report fields required by Static Site Importer. |
| `chubes4/static-site-importer` | `cook/php-transformer-adapter` | Add a Static Site Importer-owned adapter that isolates BFB/BAC versus transformer calls. | Composer path repository during review, compatibility releases before merge. | Import-theme and import-website-artifact quality gates match the current reports with no fallback regressions. |

The transformer review branch for all four downstream branches is `cook/php-transformer-migration-no-perma-legacy` in `Automattic/blocks-engine`.

## Local Composer Path Repository

Use this path repository in downstream PR branches while reviewing the transformer package before it is tagged:

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
    "automattic/blocks-engine-php-transformer": "dev-cook/php-transformer-migration-no-perma-legacy as 0.1.x-dev"
  }
}
```

If Composer cannot resolve the branch alias yet, add an inline alias in the downstream branch only: `dev-cook/php-transformer-migration-no-perma-legacy as 0.1.x-dev`. Replace all path repositories and dev constraints with the tagged transformer version before merging.

## Dependency Constraints

Use these constraints while the wrapper branches are under review:

| Package | Review constraint | Merge constraint |
| --- | --- | --- |
| `automattic/blocks-engine-php-transformer` | `dev-cook/php-transformer-migration-no-perma-legacy as 0.1.x-dev` | `^0.1.0` after the transformer is tagged. |
| `chubes4/html-to-blocks-converter` | `dev-cook/php-transformer-html-wrapper` when BFB or SSI needs the wrapper branch. | First tagged compatibility release, expected `^0.1.0` unless the repo already has a release scheme. |
| `chubes4/block-format-bridge` | `dev-cook/php-transformer-format-wrapper` when SSI needs the wrapper branch. | First tagged compatibility release, expected `^0.1.0` unless the repo already has a release scheme. |
| `chubes4/block-artifact-compiler` | `dev-cook/php-transformer-artifact-wrapper` when SSI needs the wrapper branch. | First tagged compatibility release, expected `^0.1.0` unless the repo already has a release scheme. |

Downstream merge candidates must not depend on `/Users/...` path repositories, unpublished branch constraints, or copied transformer files.

## Commit Sequence

Use this sequence so each downstream branch can be reviewed and rolled back independently:

1. `html-to-blocks-converter`: add Composer dependency and autoload wiring without changing behavior.
2. `html-to-blocks-converter`: add the wrapper delegation and parity fixtures, then switch the default only after old-versus-transformer checks pass.
3. `block-format-bridge`: add Composer dependency and transformer-backed adapter classes behind existing BFB registry boundaries.
4. `block-format-bridge`: add capability/report metadata and fixture comparisons, then switch supported conversions to transformer adapters.
5. `block-artifact-compiler`: add Composer dependency and BAC result mapper without changing public report keys.
6. `block-artifact-compiler`: delegate compiler entrypoints and add contract-smoke parity coverage.
7. `static-site-importer`: add an SSI-owned adapter that defaults to legacy BFB/BAC calls.
8. `static-site-importer`: route product call sites through the adapter without changing reports.
9. `static-site-importer`: enable transformer-backed adapter paths after compatibility releases are tagged and fixture reports prove no fallback regression.

## Compatibility Release Order

1. Merge and tag `automattic/blocks-engine-php-transformer` with the first stable result envelope and public class names.
2. Release `chubes4/html-to-blocks-converter` as a compatibility wrapper over `HtmlTransformer`.
3. Release `chubes4/block-format-bridge` with transformer-backed adapters and unchanged public `bfb_*` functions.
4. Release `chubes4/block-artifact-compiler` with transformer-backed compiler functions and unchanged BAC report fields.
5. Update `chubes4/static-site-importer` to depend on the compatibility releases first, then switch its internal adapter to direct transformer calls when reports prove equivalent.

## Shared Phase-1 Rules

- Keep old public functions, hooks, CLI commands, abilities, report shapes, and plugin entrypoints stable.
- Add transformer calls behind compatibility wrappers or product-owned adapters only.
- Do not move Static Site Importer product concerns into `php-transformer`.
- Capture old-versus-transformer fixture comparisons in the downstream PR body before changing defaults.
- Treat any missing transformer method as an upstream `php-transformer` blocker, not a downstream workaround.
- Do not add old-repo names, old package names, or wrapper-specific branches to canonical `php-transformer` package metadata, namespaces, or release labels.
- Treat wrapper preservation as temporary downstream migration work, not a permanent compatibility promise from `php-transformer`.

## Archive And Thin-Shim Exits

Each old repository must choose one exit once product consumers can depend directly on tagged `php-transformer` contracts:

| Repository | Preferred exit | Thin-shim exit when external consumers remain |
| --- | --- | --- |
| `chubes4/html-to-blocks-converter` | Archive after no supported product path calls `html_to_blocks_*` helpers. | Keep Composer metadata, load `php-transformer`, emit deprecation notices, and delegate public helpers to `HtmlTransformer`. |
| `chubes4/block-format-bridge` | Archive after Static Site Importer and product adapters call `FormatBridge` directly. | Keep `bfb_*` functions and `BFB_Format_Adapter` only as delegating facades over tagged transformer APIs. |
| `chubes4/block-artifact-compiler` | Archive after product artifact imports call `ArtifactCompiler` directly. | Keep BAC functions/classes only as delegating facades that map tagged transformer envelopes to old report keys. |
| `chubes4/static-site-importer` | Not an archive candidate in this wave; it remains a product plugin. | Keep product-owned adapter seams thin so direct transformer calls can replace BFB/BAC dependencies without changing import UX. |

Thin shims must not add new transformation behavior. New behavior belongs in `php-transformer`; product workflow behavior stays in the consuming product repo.

## Rollback Plan

- Revert the final default-switch commit in the affected downstream branch first; keep dependency/autoload commits if they are inert and tests still pass.
- If the dependency itself causes install/runtime failures, revert the Composer dependency commit and regenerate the lockfile in that downstream repo.
- Static Site Importer rollback should switch its adapter default back to legacy BFB/BAC calls before reverting product call-site routing.
- Wrapper rollback should preserve public functions/classes and only remove transformer delegation internals.
- Any rollback caused by a missing transformer contract becomes an upstream transformer blocker before retrying the downstream PR.
