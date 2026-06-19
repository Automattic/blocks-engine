# Downstream Consumer PR Plans

These plans translate the first `php-transformer` adoption wave into concrete downstream PRs without changing the downstream repositories from this worktree.

## Repositories

| Repository | Phase-1 PR | Target dependency mode | Primary acceptance signal |
| --- | --- | --- | --- |
| `chubes4/html-to-blocks-converter` | Add a compatibility wrapper around `HtmlTransformer`. | Composer path repository during review, tagged transformer release before merge. | Existing raw-handler fixtures pass through the wrapper with the same block arrays. |
| `chubes4/block-format-bridge` | Add transformer-backed format adapters while preserving BFB public functions. | Composer path repository during review, tagged transformer release before merge. | Existing conversion and capability tests pass with transformer metadata present. |
| `chubes4/block-artifact-compiler` | Delegate public compiler functions/classes to `ArtifactCompiler`. | Composer path repository during review, tagged transformer release before merge. | Existing contract smoke fixtures pass with identical report fields required by Static Site Importer. |
| `chubes4/static-site-importer` | Add a Static Site Importer-owned adapter that isolates BFB/BAC versus transformer calls. | Composer path repository during review, compatibility releases before merge. | Import-theme and import-website-artifact quality gates match the current reports with no fallback regressions. |

## Local Composer Path Repository

Use this path repository in downstream PR branches while reviewing the transformer package before it is tagged:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "/Users/chubes/Developer/blocks-engine@cook-php-transformer-consumer-prep/php-transformer",
      "options": {
        "symlink": true
      }
    }
  ],
  "require": {
    "automattic/blocks-engine-php-transformer": "dev-cook/php-transformer-consumer-prep"
  }
}
```

If Composer cannot resolve the branch alias yet, pin the package as `dev-main` in the downstream branch and replace it with the tagged transformer version before merging.

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
