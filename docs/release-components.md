# Component Releases

The `release.yml` workflow runs the three package components in dependency order
on `trunk`. The jobs are intentionally serialized because Homeboy may commit
version and changelog updates; each later job must resolve the trunk tip
published by the preceding job.

## Native publication

- `@automattic/blocks-engine` publishes through the Node.js Homeboy extension.
- `php-transformer` publishes through Composer and splits the repository
  `php-transformer/` prefix natively to `Automattic/blocks-engine-php-transformer`.
  Its source `php-transformer-vX.Y.Z` tag becomes `vX.Y.Z` in the mirror. The old
  tag-triggered shell split workflow is removed.
- `figma-transformer` is release-enabled with registry publication explicitly
  disabled until standalone Packagist repository provisioning in #1364 exists.
  This config does not treat a skipped registry publication as success.

For enabled publishers, publication is not optional. Registry credentials are
owned by the corresponding Homeboy extensions (`NPM_TOKEN`, Composer/Packagist
credentials). The PHP subtree destination also needs a Git write credential.
The old `PHP_TRANSFORMER_MIRROR_DEPLOY_KEY` cannot currently be passed through
the reusable release contract, so SSI notification remains deliberately
disabled until a supported mirror-auth and destination-coordinate handoff is
available. This avoids reporting the monorepo tag SHA as the mirror SHA.

The separate `php-transformer-dev-trunk.yml` workflow is a temporary,
credentialed compatibility bridge for consumers of the mirror's `trunk`
branch. It has no tag path and must be removed when Homeboy exposes the
branch-only shared subtree invocation.

## Dependency activation checklist

The workflow now pins these remotely addressable candidate revisions. They must
be released under their normal immutable release refs before merge.

| Capability | Candidate repository | Candidate revision | Minimum release |
| --- | --- | --- | --- |
| Native subtree publication | `Extra-Chill/homeboy` | `ef324f259af85ab86c6b5bc3f608bef3d823a7e7` | Homeboy release containing `release.subtree` |
| Component-scoped reusable release | `Extra-Chill/homeboy-action` | `d21c46a3a25b16895c36124a6af481a6f97f8636` | homeboy-action release containing `component` and prepared-ref inputs |
| Composer release refresh | `Extra-Chill/homeboy-extensions` | `b94cad68433a34c9129681b1c1a57e63364719db` | extension release containing the `release.update_dependency` contract |
| Figma coordinate discovery | `Extra-Chill/homeboy` | `bb993897850b0766270da0ce489458db753dc934` | Homeboy release containing `release resolve` |

Figma's existing inline archive route remains usable. SSI must use
`homeboy release resolve` against the monorepo's `figma-transformer-v*` tags
before invoking the Composer dependency updater; a one-item inline Composer
repository cannot discover versions itself.
