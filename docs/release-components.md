# Component Releases

The `release.yml` workflow runs the three package components in dependency order
on `trunk`. The jobs are intentionally serialized because Homeboy may commit
version and changelog updates; each later job must resolve the trunk tip
published by the preceding job.

## Native publication

- `@automattic/blocks-engine` publishes through the Node.js Homeboy extension.
- `php-transformer` creates its WordPress release archive and splits the
  repository `php-transformer/` prefix natively to
  `Automattic/blocks-engine-php-transformer`.
  Its source `php-transformer-vX.Y.Z` tag becomes `vX.Y.Z` in the mirror. The old
  tag-triggered shell split workflow is removed.
- `figma-transformer` retains GitHub tag/archive release while registry
  publication is explicitly disabled until standalone Packagist repository
  provisioning in #1364 exists.

For enabled publishers, publication is not optional. Registry credentials are
owned by the corresponding Homeboy extensions. The PHP subtree destination
also needs a Git write credential: the existing
`PHP_TRANSFORMER_MIRROR_DEPLOY_KEY` secret is mapped to the reusable
workflow's generic `PUBLISH_SSH_KEY` secret input, paired with pinned GitHub
host keys and `ssh-require-known-hosts: true`.

Blocks Engine does not notify Static Site Importer or any other consumer.
The repositories are decoupled: this workflow performs no
`repository_dispatch`, carries no dispatch token, and has no SSI-specific
configuration. A consumer that needs the published coordinates resolves them
itself — `homeboy release resolve` against this repository's tags for the
Figma archive route, or the mirror repository / npm registry directly for
PHP Transformer and `@automattic/blocks-engine`.

The component manifests explicitly declare their publisher extensions:
`nodejs` for the npm package and `wordpress` for the PHP/Figma release
archives. The npm publisher itself runs `npm publish`, but the pinned
homeboy-action reusable workflow currently exposes neither `NPM_TOKEN` nor an
OIDC-enabled npm publishing setup. The caller grants `id-token: write` in
preparation for npm trusted publishing, but npm publication remains blocked
until homeboy-action adds that credential contract (or its documented
equivalent). No raw consumer-side npm publish step is added here.

The separate `php-transformer-dev-trunk.yml` workflow is a temporary,
credentialed compatibility bridge for consumers of the mirror's `trunk`
branch. It has no tag path. Its header documents the exact single
homeboy-action invocation that replaces it and the one thing still blocking
that replacement: a released Homeboy CLI tag containing `homeboy git
subtree` (see the dependency table below). Do not replace it with a
hand-rolled `homeboy git subtree` invocation plus manual ssh-agent/known-hosts
setup in the meantime — that reintroduces the same consumer-owned credential
handling this bridge exists to retire, without the supported `ssh-key` /
`ssh-known-hosts` contract the native invocation gets from the action.

## Dependency activation checklist

The workflow pins these released, immutable revisions:

| Capability | Repository | Pinned revision | Release |
| --- | --- | --- | --- |
| Component-scoped reusable release, publisher SSH handoff, native `git` operation routing | `Extra-Chill/homeboy-action` | `b2ff19a4e71772a1d6b376b7c0bf00d8ef3415cc` | `v2.19.0` |
| Composer release refresh (`release.update_dependency`) | `Extra-Chill/homeboy-extensions` | `7f28a979517a96e003d0510d64bc5139c259837d` | `wordpress-v3.48.6` |

One capability remains unreleased and is **not** pinned to anything because
there is nothing released to pin: native Git subtree publication
(`homeboy git subtree`, and the release pipeline's own `release.subtree`
step). It was implemented in `Extra-Chill/homeboy#14851` (closing
`Extra-Chill/homeboy#14837`) and merged to the CLI's `main` branch, but as of
this change no released Homeboy tag contains it — confirmed by downloading
the actual latest release binary (`v0.382.0`) and running `homeboy git
subtree --help`, which reports "unrecognized subcommand". The Homeboy CLI
version this workflow uses is intentionally never pinned here (the
homeboy-action `version` input defaults to `latest`), so the `php` release
job and the dev-trunk mirror will both start working the moment Homeboy
cuts a release containing that commit — no change to this repository is
needed when that happens. Until then, `php-transformer`'s `release.subtree`
config in its `homeboy.json` deserializes as an unrecognized field against
the currently released CLI (confirmed with `homeboy release php-transformer
--dry-run` against `v0.382.0`, which reads the rest of the manifest and
fails only on the expected non-`trunk`-branch preflight guard) and is not
consulted, so an `--apply` publish through this workflow will not perform a
native subtree split until a Homeboy release contains `#14851`.

Figma's existing inline archive route remains usable. A consumer that wants
Figma coordinates uses `homeboy release resolve` against this monorepo's
`figma-transformer-v*` tags before invoking its own Composer dependency
updater; a one-item inline Composer repository cannot discover versions
itself.
