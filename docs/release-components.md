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
that replacement — not a missing subcommand anymore, see below. Do not
replace it with a hand-rolled `homeboy git subtree` invocation plus manual
ssh-agent/known-hosts setup in the meantime — that reintroduces the same
consumer-owned credential handling this bridge exists to retire, without the
supported `ssh-key` / `ssh-known-hosts` contract the native invocation gets
from the action.

## Dependency activation checklist

The workflow pins these released, immutable revisions (every SHA verified by
fetching the tag directly and checking `^{commit}` equality, not assumed
from the tag name):

| Capability | Repository | Pinned tag | Peeled commit |
| --- | --- | --- | --- |
| Component-scoped reusable release, publisher SSH handoff, native `git` operation routing | `Extra-Chill/homeboy-action` | `v2.19.1` | `d9c6d0ee1560612f55645b4b94d0f45ae7dba9b3` (contains v2.19.0's `b2ff19a4e71772a1d6b376b7c0bf00d8ef3415cc` plus homeboy-action#490, #491) |
| `nodejs` extension for the `packages/blocks-engine` job | `Extra-Chill/homeboy-extensions` | `nodejs-v3.7.4` | `52d3ecffec1ffbb98ef68e3539e1da1b85a14e8e` |
| `wordpress` extension for the `php-transformer`/`figma-transformer` jobs | `Extra-Chill/homeboy-extensions` | `wordpress-v3.48.6` | `7f28a979517a96e003d0510d64bc5139c259837d` (composer release refresh, `release.update_dependency`) |

`extension-ref` is required on every job, not optional: reading Homeboy
v0.383.0's own source
(`crates/homeboy-core/src/extension/lifecycle/{mod,install_sources}.rs`)
shows that when `extension-ref` is empty, `install_for_component` clones the
extension source with no revision — the source repository's current default
branch tip, not a released version. The previous revision of this workflow
pinned all three jobs to the same `wordpress` release commit regardless of
which extension each job actually declares; `javascript` now correctly pins
to `nodejs`'s own release tag instead.

Native Git subtree publication (`homeboy git subtree`, implemented in
`Extra-Chill/homeboy#14851`) shipped in Homeboy `v0.383.0` and is no longer
missing. This was verified behaviorally, not by CLI `--help` shape: the
released `v0.383.0` binary was downloaded and run against a disposable
bare-repository fixture reproducing this repository's `homeboy.json`
`release.subtree` schema. Preview mutates nothing; `--apply` publishes a
tree byte-identical to the source subtree; a re-run is idempotent;
`--branch-only` correctly excludes tag publication (tag publication itself
works correctly when the flag is omitted and `--version` is supplied); a
`fast_forward` `branch_policy` correctly refuses to force-push over
divergent mirror history instead of silently overwriting it; and publishing
one component's subtree never touches a sibling component's mirror.

**New residual blocker, found by that same verification**: `homeboy git
subtree` in v0.383.0 has a reproducible bug in its own result serialization
on the success path only. Every invocation that actually performs the
subtree split-and-push correctly — preview and `--apply` alike — still exits
non-zero afterward with `{"success": false, "exit_code": 1, "diagnostics":
{"code": "internal.json_error", ...}}`. The underlying git operation was
confirmed to succeed in every case by inspecting the destination ref
directly; pointing the same command at a genuinely broken remote instead
produces a normal, correctly serialized failure result (`git.command_failed`,
exit code 20), isolating the bug to success-path serialization specifically.
`homeboy-action`'s `scripts/operations/run-operations.sh` (which routes
`commands: git subtree ...`) has no special case for this: it maps any
nonzero `homeboy` exit code straight to a failed step. Because of this,
`php-transformer`'s `release.subtree` config in `homeboy.json` is consulted
by the currently released CLI (the schema-mismatch problem from the prior
revision of this document is gone), but a real `php` release `--apply`
through `release.yml` that reaches the `release.subtree` step, and the
dev-trunk mirror workflow if switched to the native invocation today, would
both risk reporting failure even on a successful publish. This repository
does not work around that by hand-rolling exit-code suppression around a
Homeboy command; the fix belongs upstream in Homeboy's result serialization.
The dev-trunk workflow therefore keeps its working shell-based bridge (see
its header) until a released Homeboy tag fixes this.

Figma's existing inline archive route remains usable. A consumer that wants
Figma coordinates uses `homeboy release resolve` against this monorepo's
`figma-transformer-v*` tags before invoking its own Composer dependency
updater; a one-item inline Composer repository cannot discover versions
itself.
