# Downstream Packaging Migration Evidence

This document records downstream packaging migration evidence. It is not canonical package API documentation for `automattic/blocks-engine-php-transformer`.

## Composer Package

The package name is `automattic/blocks-engine-php-transformer`.

php-transformer is product-level primitive and old repos are downstream consumers.

The canonical package identity is only the Composer package name, the `php-transformer/` package root, and the `Automattic\BlocksEngine\PhpTransformer\` namespace. Do not include old repository names, compatibility wrapper names, or product plugin names in package identity, package metadata, namespaces, or release labels.

The package root is `php-transformer/` inside this repository. It should be installable as a Composer package from that directory during review and from a tagged release after merge.

The public namespace remains `Automattic\BlocksEngine\PhpTransformer\`. Downstream wrappers and product plugins should depend on this namespace through Composer autoloading, not by copying package files or requiring `php-transformer` to know the old repositories.

## Existing Package Name Continuity

These Composer package names are known public entrypoints and should keep resolving during the migration:

| Existing package | Current role | Continuity plan |
| --- | --- | --- |
| `chubes4/html-to-blocks-converter` | WordPress plugin and Composer package for HTML-to-block conversion. | Publish compatibility releases that keep `html_to_blocks_*` functions and plugin hooks while delegating to tagged transformer APIs. |
| `chubes4/static-site-importer` | Product plugin that consumes `php-transformer` and owns WordPress import workflows. | Keep as an active product package with product-owned adapters that call tagged transformer APIs directly. |

The old names should not be archived at the transformer merge point. Archive decisions wait until tagged compatibility releases have shipped, supported product paths no longer require the old entrypoints, and issue traffic shows no active external dependency on those packages.

### README Banner Language

Use a short banner in the old repository READMEs during the compatibility phase:

```markdown
> **Continuity notice:** This package name remains supported for existing consumers while the canonical transformation implementation moves to `automattic/blocks-engine-php-transformer`. New reusable transformation primitives should be proposed upstream in Blocks Engine. Compatibility bugs for this package name remain welcome here until a tagged direct-migration path is published.
```

For Static Site Importer, use product-focused language instead:

```markdown
> **Dependency notice:** Static Site Importer remains the product plugin for WordPress import workflows. Its lower-level conversion dependencies are moving toward `automattic/blocks-engine-php-transformer` through tagged compatibility releases before direct transformer adoption.
```

### GitHub Redirect And Issue Template Guidance

Do not close issue trackers or replace them with repository archival banners during the first compatibility wave. Add issue template copy that routes reports by ownership:

| Report type | Open in |
| --- | --- |
| Old package installability, public functions, hooks, CLI commands, abilities, README examples, or tagged compatibility regressions. | The old package repository that owns that public surface. |
| Missing reusable transformer primitive, unstable result-envelope field, fixture parity gap, or package-level transformer API request. | `Automattic/blocks-engine` against `php-transformer`. |
| Static Site Importer admin UX, upload intake, theme generation, page creation, asset materialization, commerce gates, or import reports. | `chubes4/static-site-importer`. |

Suggested issue template note for wrapper repositories:

```markdown
This repository remains the support surface for its existing Composer package name and public functions during the compatibility phase. If your report needs a new reusable transformer primitive rather than a wrapper/package fix, link this issue to an upstream `Automattic/blocks-engine` issue for `php-transformer`.
```

Suggested upstream issue template note for Blocks Engine:

```markdown
Use this tracker for canonical `php-transformer` package issues: reusable transformer APIs, result envelopes, diagnostics, fixtures, Composer metadata, and migration blockers. Reports about old package entrypoints should link the downstream repository issue that owns the existing public surface.
```

GitHub repository descriptions for old wrappers can point to the canonical package without using archived or deprecated language: `Compatibility package for existing <package> consumers; canonical transformation primitives live in automattic/blocks-engine-php-transformer.`

### Composer replace/provide Policy

`automattic/blocks-engine-php-transformer` should not declare `replace` for the old package names. It is not a drop-in replacement for their WordPress plugin bootstraps, global functions, hooks, CLI commands, abilities, or product-owned report shapes. A `replace` claim would let Composer satisfy old requirements with a package that does not provide the old runtime surface.

`automattic/blocks-engine-php-transformer` should not declare `provide` for the old package names during the first wave for the same reason. If maintainers later define a virtual package such as `automattic/blocks-engine-transformer-implementation`, it should describe only the reusable transformer contract, not the old wrapper packages.

Old wrapper repositories may require `automattic/blocks-engine-php-transformer` and may optionally add `suggest` entries for related wrappers or product adopters. They should not `replace` each other. A thin-shim release may use `conflict` only for known-bad transformer versions that break its published compatibility contract.

## Monorepo Install Options

During review, downstream consumers may use either Composer VCS repositories or local path repositories.

After the transformer release is tagged, downstream consumers should remove custom repository entries and require the package by tag constraint:

```json
{
  "require": {
    "automattic/blocks-engine-php-transformer": "^0.1.0"
  }
}
```

The package is rooted at `php-transformer/`; release and packaging checks should run from that directory. Do not package the full Blocks Engine repository as the Composer artifact, and do not move the transformer files into a downstream repository to make Composer resolution easier.

Use a VCS repository when the consumer branch runs outside this machine or in CI:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/Automattic/blocks-engine"
    }
  ],
  "require": {
    "automattic/blocks-engine-php-transformer": "dev-cook/php-transformer-migration-no-perma-legacy as 0.1.x-dev"
  }
}
```

Use a path repository for local downstream wrapper and product PRs while the transformer package is still a draft:

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

Before any downstream PR merges, replace draft branch constraints with a tagged package constraint.

## Dependency Prefixing Policy

`php-transformer` should be authored as a normal Composer library and should not ship a PHP-Scoper build as its canonical package artifact.

WordPress plugins that vendor the package into distributed ZIPs own their own prefixing step. That keeps each plugin responsible for its runtime collision policy and avoids forcing one prefixing strategy on Studio, WordPress.com, Static Site Importer, and compatibility packages.

Downstream wrappers must support their current unprefixed development installs. If a wrapper ships a prefixed production artifact, it should preserve its existing public functions, classes, hooks, CLI commands, abilities, and result shapes at the wrapper boundary. This is a wrapper-owned distribution decision, not a requirement for `php-transformer` to ship wrapper-specific compatibility code.

## Versioning

The first tagged release should be `0.1.0` unless maintainers choose a repo-wide release scheme before merge.

The package does not declare a Composer `version` field. Composer derives immutable release versions from tags; the checked-in `VERSION` file and plugin header are Homeboy release targets that should match the first tag. The `dev-trunk` branch alias is only a pre-release convenience for review and Packagist metadata, not a substitute for the first `0.1.0` tag.

Release readiness preflight before tagging:

```sh
cd "$BLOCKS_ENGINE_WORKTREE/php-transformer"
composer validate --strict
composer test

cd "$BLOCKS_ENGINE_WORKTREE"
homeboy component show php-transformer
homeboy release php-transformer --dry-run --skip-publish --no-github-release
```

Operator-only release command after upstream PRs are merged, the dry-run passes, and the exact release commit is accepted:

```sh
homeboy release php-transformer --skip-publish --no-github-release
```

Run `composer test:packaging` separately when you only need to re-check Composer installability. Run release commands from the merged Blocks Engine branch that should own the tag, not from a downstream wrapper branch. Do not run release commands from review worktrees, draft downstream branches, or while path repositories, unpublished branch constraints, or local-only proof are still required by merge candidates. Keep `VERSION` as the Homeboy-managed package version source.

Before `1.0.0`, public PHP class names, constructor signatures, result-envelope keys, diagnostic codes, and Composer package metadata may change between minor versions, but each change must include downstream migration notes and fixture updates.

Patch releases should be reserved for bug fixes that preserve public package contracts. Breaking downstream-facing contract changes require a new minor version until the package reaches `1.0.0`.

Each release tag should identify the matching downstream compatibility floor in release notes so product teams can choose safe dependency ranges. That floor is a migration aid, not a permanent promise that old repositories remain first-class package surfaces.

## Version Constraints During Continuity

Use explicit review constraints only while PRs are draft:

| Dependency | Review constraint | Merge constraint |
| --- | --- | --- |
| `automattic/blocks-engine-php-transformer` | `dev-cook/php-transformer-migration-no-perma-legacy as 0.1.x-dev` | `^0.1.0` after the first transformer tag. |
| `chubes4/html-to-blocks-converter` | `dev-cook/php-transformer-html-wrapper` for dependent wrapper/product review only. | First tagged compatibility release, expected `^0.1.0` unless maintainers choose a different existing scheme. |

Review branches may use path repositories for local parity work and VCS repositories for CI. Merge candidates must use tags, not path repositories, unpublished branches, or inline aliases.

Prefer lower-bound compatible constraints such as `^0.1.0` for the compatibility wave so patch releases can carry bug fixes without forcing every product consumer to change constraints. Use an upper bound only when a downstream compatibility release has a known transformer API ceiling that cannot be expressed by Composer's normal pre-1.0 caret semantics.

## Downstream Release Order

Release downstream wrappers after the transformer package has a tag that contains the result-envelope and namespace contracts they consume.

1. Tag `automattic/blocks-engine-php-transformer` with stable package metadata, autoloading, result envelopes, and fixture coverage.
2. Release `chubes4/html-to-blocks-converter` as a temporary downstream wrapper over transformer HTML conversion while preserving current public helpers and plugin behavior.
3. Update `chubes4/static-site-importer` to move product-owned adapter internals to direct transformer calls when parity evidence is available.

## Archive And Thin-Shim Exit Paths

Old repositories are downstream entrypoints and consumers, not dependencies or identity anchors for `php-transformer`. The full repo-by-repo fate, keep-open criteria, archive criteria, issue routing, and package discoverability policy lives in [`current-repo-map.md`](current-repo-map.md).

Old repositories have two acceptable exits after product consumers move to tagged `php-transformer` contracts:

- Archive the old repository when no active product or public consumer still requires its functions, hooks, CLI commands, abilities, or package name.
- Keep a thin shim when external consumers still require the old package name or public entrypoints; the shim should own only Composer metadata, autoloading, deprecation notices, and direct delegation to tagged `php-transformer` APIs.

Thin shims must not grow new transformation logic, new product behavior, or repo-specific branches inside `php-transformer`. Any missing primitive blocks the downstream PR until the transformer contract is fixed upstream.

Do not archive popular old repositories as an immediate cleanup step. Keep them open while they still provide meaningful package discovery, issue routing, public entrypoint compatibility, or migration guidance. Archiving is safe only after active callers have moved, replacement package instructions are published, issue trackers are redirected, and maintainers agree the repository is no longer useful as a signpost.

Static Site Importer should not require unpublished wrapper branches on merge. If it still needs unpublished wrapper behavior, the transformer PR remains draft or the affected Static Site Importer scope stays out of the merge path.

## Draft Exit Criteria

The PR can leave draft when the package is reviewable as a releasable Composer library.

- `php-transformer/composer.json` declares the package name, PHP constraint, autoload rules, scripts, and package metadata needed by wrapper PRs.
- The README states package boundaries, draft status, and where wrapper/product migration plans live.
- Contract docs cover result envelopes and parity fixtures for downstream wrapper checks.
- Packaging docs define VCS/path repository use, versioning, prefixing ownership, and release order.
- Repository-map docs define keep-open versus archive criteria, repo-by-repo fate, issue routing, and package discoverability for old downstream entrypoints.
- Wrapper PR plans identify branch names, commit sequence, file-level patch skeletons, dependency constraints, acceptance commands, rollback plans, archive/thin-shim exits, and the first downstream acceptance signal for HTML conversion, format bridging, artifact compilation, and Static Site Importer adoption.
- No product-specific implementation behavior is required for the transformer package to install and run its own tests.
- Known blockers are tracked in docs or issues, not hidden as downstream workarounds.

## Merge Acceptance Criteria

Maintainers can merge the transformer package when these checks are true:

- Composer can install `automattic/blocks-engine-php-transformer` from the package directory and from the repository branch used for review.
- Package tests, including `composer test:packaging`, pass through the documented Composer scripts.
- Fixture documentation explains how downstream wrappers compare old behavior with transformer-backed behavior.
- The public namespace and result-envelope keys needed by phase-1 wrappers are stable enough to tag.
- The PR description includes the intended initial version, downstream release order, and the no-permanent-compatibility stance for old repositories.
- AI assistance is disclosed in the PR description if substantive agent-authored docs or code are included.

## Automattic Product Acceptance Criteria

Automattic products should accept the package only when the consuming PR proves the package does not weaken product outcomes.

- Product import reports, quality gates, generated blocks, asset manifests, and fallback counts remain equivalent or intentionally versioned.
- Studio and WordPress.com adoption paths can install the package through Composer without depending on local path repositories.
- Distributed plugin ZIPs have an explicit dependency-prefixing decision documented in the product repo.
- Product-owned adapters isolate transformer calls from admin screens, CLI commands, upload intake, theme activation, deployment behavior, and other product workflows.
- Rollback is a dependency change or adapter switch, not a rewrite of product code.
- Review evidence links to reachable PRs, issues, or CI artifacts rather than local filesystem paths.

## Blockers To Resolve Before Merge

- Missing package metadata or Composer installability.
- Unstable result-envelope keys required by the first wrapper releases.
- Downstream plans that require wrappers to change public behavior without an explicit versioned migration.
- Any plan that requires `php-transformer` to reference old repository names in canonical package identity or carry old-repo-specific compatibility branches.
- Any product PR that depends on local path repositories, unpublished wrapper branches, or manual file copies at merge time.
