# PR Body Draft

## Summary

This draft introduces `php-transformer` as the origin-clean PHP package for Blocks Engine transformation primitives. The package is `automattic/blocks-engine-php-transformer`, rooted at `php-transformer/`, and exposed through the `Automattic\BlocksEngine\PhpTransformer\` namespace.

The package owns reusable conversion contracts for HTML, declared content formats, and generated website artifacts. It does not own product workflows such as importer screens, uploaded ZIP intake, theme activation, Studio orchestration, WordPress.com deployment behavior, or generation-loop policy.

## Direction

This PR should be reviewed as a standalone package, not as a renamed predecessor repository. Existing consumer code paths are downstream migration evidence.

Implementation should collapse into `php-transformer` where the behavior is a reusable primitive. Repository archive decisions are separate: old repositories can be archived only when no product or public consumer still needs their package name, functions, hooks, CLI commands, abilities, or other public entrypoints. If those entrypoints remain in use, the old repository should become a thin shim over tagged transformer APIs instead of adding new transformation logic.

## Current Package API

Current public entrypoints are:

- `Contract\TransformerResult` for the serializable result envelope. Use `toArray()` across fixture, HTTP, process, and compatibility boundaries.
- `HtmlToBlocks\HtmlTransformer` for supported HTML to parsed block arrays and serialized block markup.
- `FormatBridge\FormatBridge` for declared `html`, `markdown`, and serialized `blocks` normalization and conversion. New consumers should prefer `convertResult()`.
- `FormatBridge\FormatAdapterInterface` when a consumer needs a package-level format extension point.
- `ArtifactCompiler\ArtifactCompiler` for generated website artifact bundle normalization into the shared result envelope.
- `WordPress\Runtime` for WordPress function calls that need to work inside and outside WordPress.

Other bundled classes are implementation details unless the README explicitly marks them public or they are part of an injected adapter contract.

## Verification

Expected local checks for this draft:

- `git diff --check`
- `composer validate` from `php-transformer/`
- `composer test` from `php-transformer/` when validating behavior, fixtures, and parity harnesses

The parity harness validates current transformer output by default. Optional legacy comparisons are local migration evidence only and do not make old repositories part of the package API.

## Next Steps

- Keep the PR in draft until the package is installable and reviewable as a Composer library.
- Replace any draft branch constraints in downstream plans with a tagged package constraint before downstream PRs merge.
- Use downstream wrapper PRs to preserve old public surfaces while moving implementation to tagged transformer APIs.
- Keep product plugins outside this package; they should consume transformer APIs through product-owned adapters rather than become part of this package.
- Track missing primitives upstream in `php-transformer` instead of adding downstream workarounds.

## AI assistance

- **AI assistance:** Yes
- **Tool(s):** OpenCode (GPT-5.5)
- **Used for:** Drafting and editing documentation for the PR body structure and package-review framing. Chris remains responsible for review, verification, and final PR text.
