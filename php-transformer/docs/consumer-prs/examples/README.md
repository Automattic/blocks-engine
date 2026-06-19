# Downstream Migration Examples

These files are downstream-only migration sketches for wrapper and product PRs. They are copy/adapt references, not package API shipped by `automattic/blocks-engine-php-transformer`.

Use them with the release playbooks in `php-transformer/docs/consumer-prs/`:

| Example | Downstream owner | Release use |
| --- | --- | --- |
| `html-to-blocks-converter-wrapper.php` | `chubes4/html-to-blocks-converter` | Preserve `html_to_blocks_*` helpers while delegating HTML conversion to `HtmlTransformer`. |
| `block-format-bridge-wrapper.php` | `chubes4/block-format-bridge` | Preserve `bfb_*` functions and capability/report shapes while delegating supported conversions to `FormatBridge`. |
| `block-artifact-compiler-wrapper.php` | `chubes4/block-artifact-compiler` | Preserve BAC functions and summaries while delegating artifact compilation to `ArtifactCompiler`. |
| `static-site-importer-transformer-adapter.php` | `chubes4/static-site-importer` | Keep importer workflows product-owned while mapping transformer results into current BFB/BAC-compatible reports. |

Before using an example in a release branch:

1. Load Composer autoload from the downstream repository, not from this package path.
2. Preserve the downstream repository's existing hooks, filters, CLI commands, abilities, and public result shapes.
3. Add fixture comparisons before switching defaults.
4. Replace review path repositories with tagged release constraints before merge.
5. Treat missing transformer primitives as upstream blockers, not local workaround space.

Smoke checks for copied migration examples should include `composer validate`, the downstream test suite, one direct public-entrypoint invocation, and `git diff --check`.
