# PHP Transformer Packaging And Draft Exit Criteria

This document makes the draft PR's packaging and merge-readiness requirements concrete without introducing implementation classes.

## Composer Package

The package name is `automattic/blocks-engine-php-transformer`.

The package root is `php-transformer/` inside this repository. It should be installable as a Composer package from that directory during review and from a tagged release after merge.

The public namespace remains `Automattic\BlocksEngine\PhpTransformer\`. Consumers should depend on this namespace through Composer autoloading, not by copying package files.

## Monorepo Install Options

During review, consumers may use either Composer VCS repositories or local path repositories.

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
    "automattic/blocks-engine-php-transformer": "dev-cook/php-transformer-downstream-prep as 0.1.x-dev"
  }
}
```

Use a path repository for local consumer PRs while the transformer package is still a draft:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../blocks-engine@cook-php-transformer-downstream-prep/php-transformer",
      "options": {
        "symlink": true
      }
    }
  ],
  "require": {
    "automattic/blocks-engine-php-transformer": "dev-cook/php-transformer-downstream-prep as 0.1.x-dev"
  }
}
```

Before any downstream PR merges, replace draft branch constraints with a tagged package constraint.

## Dependency Prefixing Policy

`php-transformer` should be authored as a normal Composer library and should not ship a PHP-Scoper build as its canonical package artifact.

WordPress plugins that vendor the package into distributed ZIPs own their own prefixing step. That keeps each plugin responsible for its runtime collision policy and avoids forcing one prefixing strategy on every consumer.

## Versioning

The first tagged release should be `0.1.0` unless maintainers choose a repo-wide release scheme before merge.

Before `1.0.0`, public PHP class names, constructor signatures, result-envelope keys, diagnostic codes, and Composer package metadata may change between minor versions, but each change must include migration notes and fixture updates.

Patch releases should be reserved for bug fixes that preserve public package contracts. Breaking consumer-facing contract changes require a new minor version until the package reaches `1.0.0`.

Each release tag should identify the package contract level in release notes so consumers can choose safe dependency ranges.

## Draft Exit Criteria

The PR can leave draft when the package is reviewable as a releasable Composer library.

- `php-transformer/composer.json` declares the package name, PHP constraint, autoload rules, scripts, and package metadata needed by consumers.
- The README states package boundaries and draft status.
- Contract docs cover result envelopes and parity fixtures for package behavior.
- Packaging docs define VCS/path repository use, versioning, and prefixing ownership.
- Transitional consumer plans are documented separately from the package API.
- No product-specific implementation behavior is required for the transformer package to install and run its own tests.
- Known blockers are tracked in docs or issues, not hidden as downstream workarounds.

## Merge Acceptance Criteria

Maintainers can merge the transformer package when these checks are true:

- Composer can install `automattic/blocks-engine-php-transformer` from the package directory and from the repository branch used for review.
- Package tests pass through the documented Composer script.
- Fixture documentation explains the current transformer behavior under test.
- The public namespace and result-envelope keys are stable enough to tag.
- The PR description includes the intended initial version.
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
- Unstable result-envelope keys required by initial consumers.
- Downstream plans that require public behavior changes without an explicit versioned migration.
- Any product PR that depends on local path repositories, unpublished branches, or manual file copies at merge time.
