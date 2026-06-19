# PHP Transformer Packaging And Draft Exit Criteria

This document makes the draft PR's packaging and merge-readiness requirements concrete without introducing implementation classes.

## Composer Package

The package name is `automattic/blocks-engine-php-transformer`.

php-transformer is product-level primitive and old repos are downstream consumers.

The canonical package identity is only the Composer package name, the `php-transformer/` package root, and the `Automattic\BlocksEngine\PhpTransformer\` namespace. Do not include old repository names, compatibility wrapper names, or product plugin names in package identity, package metadata, namespaces, or release labels.

The package root is `php-transformer/` inside this repository. It should be installable as a Composer package from that directory during review and from a tagged release after merge.

The public namespace remains `Automattic\BlocksEngine\PhpTransformer\`. Downstream wrappers and product plugins should depend on this namespace through Composer autoloading, not by copying package files or requiring `php-transformer` to know the old repositories.

## Monorepo Install Options

During review, downstream consumers may use either Composer VCS repositories or local path repositories.

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

Before `1.0.0`, public PHP class names, constructor signatures, result-envelope keys, diagnostic codes, and Composer package metadata may change between minor versions, but each change must include downstream migration notes and fixture updates.

Patch releases should be reserved for bug fixes that preserve public package contracts. Breaking downstream-facing contract changes require a new minor version until the package reaches `1.0.0`.

Each release tag should identify the matching downstream compatibility floor in release notes so product teams can choose safe dependency ranges. That floor is a migration aid, not a permanent promise that old repositories remain first-class package surfaces.

## Downstream Release Order

Release downstream wrappers after the transformer package has a tag that contains the result-envelope and namespace contracts they consume.

1. Tag `automattic/blocks-engine-php-transformer` with stable package metadata, autoloading, result envelopes, and fixture coverage.
2. Release `chubes4/html-to-blocks-converter` as a temporary downstream wrapper over transformer HTML conversion while preserving current public helpers and plugin behavior.
3. Release `chubes4/block-format-bridge` as a temporary downstream wrapper with transformer-backed adapters while preserving current `bfb_*` functions, format support, conversion reports, and capability metadata.
4. Release `chubes4/block-artifact-compiler` as a temporary downstream wrapper with transformer-backed compiler behavior while preserving current public compiler functions and report fields.
5. Update `chubes4/static-site-importer` to depend on compatibility releases first, then move product-owned adapter internals to direct transformer calls when parity evidence is available.

## Archive And Thin-Shim Exit Paths

Old repositories have two acceptable exits after product consumers move to tagged `php-transformer` contracts:

- Archive the old repository when no active product or public consumer still requires its functions, hooks, CLI commands, abilities, or package name.
- Keep a thin shim when external consumers still require the old package name or public entrypoints; the shim should own only Composer metadata, autoloading, deprecation notices, and direct delegation to tagged `php-transformer` APIs.

Thin shims must not grow new transformation logic, new product behavior, or repo-specific branches inside `php-transformer`. Any missing primitive blocks the downstream PR until the transformer contract is fixed upstream.

Static Site Importer should not require unpublished wrapper branches on merge. If it still needs unpublished wrapper behavior, the transformer PR remains draft or the affected Static Site Importer scope stays out of the merge path.

## Draft Exit Criteria

The PR can leave draft when the package is reviewable as a releasable Composer library.

- `php-transformer/composer.json` declares the package name, PHP constraint, autoload rules, scripts, and package metadata needed by wrapper PRs.
- The README states package boundaries, draft status, and where wrapper/product migration plans live.
- Contract docs cover result envelopes and parity fixtures for downstream wrapper checks.
- Packaging docs define VCS/path repository use, versioning, prefixing ownership, and release order.
- Wrapper PR plans identify branch names, commit sequence, file-level patch skeletons, dependency constraints, acceptance commands, rollback plans, archive/thin-shim exits, and the first downstream acceptance signal for HTML conversion, format bridging, artifact compilation, and Static Site Importer adoption.
- No product-specific implementation behavior is required for the transformer package to install and run its own tests.
- Known blockers are tracked in docs or issues, not hidden as downstream workarounds.

## Merge Acceptance Criteria

Maintainers can merge the transformer package when these checks are true:

- Composer can install `automattic/blocks-engine-php-transformer` from the package directory and from the repository branch used for review.
- Package tests pass through the documented Composer script.
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
