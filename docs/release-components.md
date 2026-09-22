# Component Releases

The `release.yml` workflow runs the three package components in dependency
order on `trunk`. Jobs are serialized because Homeboy may commit version and
changelog updates; each later job resolves the refreshed trunk tip.

## Native Publication

- `@automattic/blocks-engine` publishes through the Node.js Homeboy extension.
- `php-transformer` creates its WordPress release archive and publishes the
  `php-transformer/` prefix to
  `Automattic/blocks-engine-php-transformer`. Its source
  `php-transformer-vX.Y.Z` tag becomes `vX.Y.Z` in the mirror.
- `figma-transformer` retains GitHub tag/archive release while registry
  publication remains disabled until standalone Packagist provisioning in
  #1364 exists.

The `php-transformer-dev-trunk.yml` workflow mirrors the package's `trunk`
branch with one supported `homeboy-action` invocation. Credentials and host
verification are owned by the action; the consumer does not configure an
ssh-agent, write keys or known hosts, or run `git push`.

Blocks Engine does not notify Static Site Importer or any other consumer. The
repositories are decoupled: consumers resolve published coordinates from the
mirror repository, npm registry, or release tags themselves.

## Pins

The workflow uses released tags, verified by resolving each tag's peeled
commit directly:

| Capability | Repository | Pinned tag | Peeled commit |
| --- | --- | --- | --- |
| Component release workflow and native Git operation routing | `Extra-Chill/homeboy-action` | `v2.19.1` | `d9c6d0ee1560612f55645b4b94d0f45ae7dba9b3` |
| `nodejs` extension | `Extra-Chill/homeboy-extensions` | `nodejs-v3.7.4` | `c9a119745e5d961dccb0206e1231faa01c62f0ae` |
| `wordpress` extension | `Extra-Chill/homeboy-extensions` | `wordpress-v3.48.6` | `7f28a979517a96e003d0510d64bc5139c259837d` |

Each `extension-ref` matches the extension declared by that component. The
pin is required because an empty extension revision floats to the extension
repository's default branch rather than a released version.

## Subtree Verification

Native Git subtree publication (`homeboy git subtree`) is available in
released Homeboy `v0.383.4`. Verification used the actual release
binary and disposable local bare repositories. Preview exited 0 without
creating refs; `--apply` exited 0 and published a tree identical to the
source subtree; a re-run reported `already-identical`; `--branch-only` left
tags untouched; normal mode published the configured tag; and sibling
components remained excluded. The success-path serialization failure found
in Homeboy `v0.383.0` is fixed in `v0.383.4`.

The historical `backfill.sh` remains only as disaster recovery after loss of
the mirror repository. It calls the same native Homeboy subtree command for
each historical `php-transformer-v*` source tag and for `origin/trunk`; routine
trunk mirroring uses the workflow above.

## Registry Credentials

The PHP subtree destination uses the existing
`PHP_TRANSFORMER_MIRROR_DEPLOY_KEY` repository secret through the action's
`ssh-key` input. GitHub host verification is pinned in the workflow.

Npm publication for the JavaScript package remains blocked on the upstream
credential contract tracked in `Extra-Chill/homeboy-action#492`. No released
contract report was available while updating this PR, so no raw consumer-side
`npm publish` step is added. After an action release carries that contract,
operators must provision the repository's npm publishing credential or trusted
publishing configuration according to that released contract.

## Activation Checklist

- `PHP_TRANSFORMER_MIRROR_DEPLOY_KEY` in `Automattic/blocks-engine`, with
  write access to `Automattic/blocks-engine-php-transformer`.
- `HOMEBOY_APP_ID` and `HOMEBOY_APP_PRIVATE_KEY` are optional; provision them
  only if release commits and tags should use a GitHub App identity.
- Npm credentials remain blocked until the released action contract exists.

No merge, release, publication, tag, or secret change is performed by this
repository change.
