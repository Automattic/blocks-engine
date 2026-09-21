# Component Releases

The `release.yml` workflow runs the three package components in dependency order
on `trunk`. The jobs are intentionally serialized because Homeboy may commit
version and changelog updates; each later job must resolve the trunk tip
published by the preceding job.

## Native publication

- `@automattic/blocks-engine` publishes through the Node.js Homeboy extension.
- `php-transformer` publishes through Composer and splits its component root
  (`prefix: "."`) natively to `Automattic/blocks-engine-php-transformer`. The old
  tag-triggered shell split workflow is removed.
- `figma-transformer` is release-enabled with registry publication explicitly
  disabled until standalone Packagist repository provisioning in #1364 exists.
  This config does not treat a skipped registry publication as success.

For enabled publishers, publication is not optional: the reusable workflow
only dispatches SSI after Homeboy reports a successful consumable release. The
SSI dispatch requires either `DISPATCH_TOKEN` or a Homeboy App
installation token scoped to `Automattic/static-site-importer`. Registry
credentials are owned by the corresponding Homeboy extensions (`NPM_TOKEN`,
Composer/Packagist credentials); the PHP subtree destination needs a Git write
credential. The old `PHP_TRANSFORMER_MIRROR_DEPLOY_KEY` is not consumed by this
workflow. This repository does not add or infer secrets.

## Dependency activation checklist

This draft depends on the following exact local candidate revisions. They must
be released and pinned in `release.yml` before merging; `@v2` is retained here
only as the currently published reusable-workflow fallback while those
upstream changes are pending.

| Capability | Candidate repository | Candidate revision | Minimum release |
| --- | --- | --- | --- |
| Native subtree publication | `Extra-Chill/homeboy` | `84d83ac1bea431f54e1df8de419e24a8316b3e0e` plus the uncommitted #14837 contract | Homeboy release containing `release.subtree` |
| Component-scoped reusable release | `Extra-Chill/homeboy-action` | `6156c5e39945cb20689c49a830f69a15ad36d51c` plus the uncommitted #485 workflow changes | homeboy-action release containing `component` and prepared-ref inputs |
| Composer release refresh | `Extra-Chill/homeboy-extensions` | `e4eba09ee90fb48d26bc380cc3f19a7ca80a0f99` (`feat/2855-composer-release-refresh`) | extension release containing the `release.update_dependency` contract |

The candidate working trees were not committed when this draft was prepared,
so the first two entries deliberately include the exact base SHA and state the
uncommitted dependency. Do not merge this workflow until the final upstream
commit SHAs replace those entries and the `uses:` pins are updated from `v2`.
