# Transitional Repository Map

The first migration wave treats the existing open source repositories as downstream entrypoints and early consumers of `php-transformer`. This document is migration context only; the package API does not depend on these repository names.

`php-transformer` owns the canonical implementation and package identity. Older repositories may remain open for discoverability, deprecation, issue routing, and compatibility releases while their reusable transformation logic moves into `php-transformer`.

## Repository Fate

| Repository | Current role | Target role | Initial fate |
| --- | --- | --- | --- |
| `chubes4/html-to-blocks-converter` | Converts raw HTML to Gutenberg block arrays. | Compatibility package or plugin wrapper around `PhpTransformer\\HtmlToBlocks`. | Keep open until active callers can install `automattic/blocks-engine-php-transformer` directly or through a tagged shim. |
| `chubes4/static-site-importer` | WordPress plugin product for importing static sites into pages/themes. | Product integration that consumes `php-transformer`; remains independently useful. | Keep open as an active product repository. It is not a legacy library exit candidate. |

## Migration Rule

Move canonical library behavior into `php-transformer`; keep product behavior and existing public integration surfaces outside the package until callers migrate deliberately.

Old repositories are not dependencies of `php-transformer`, are not package identity anchors, and must not force repo-specific branches inside the transformer. If an old repository needs behavior that the transformer lacks, the missing primitive is fixed in `php-transformer` first, then the downstream entrypoint delegates to it.

## Keep-Open Criteria

Keep an old repository open when any of these are true:

- It still receives meaningful installs, stars, forks, issues, or search traffic under the old package or plugin name.
- Public callers still require its Composer package name, plugin slug, functions, classes, hooks, CLI commands, abilities, or result shapes.
- It needs a tagged compatibility release that delegates to `automattic/blocks-engine-php-transformer` while preserving old entrypoints.
- Its issue tracker is the practical place for users to report migration problems with old entrypoints.
- Package discovery would get worse if users following existing documentation, Packagist links, GitHub search results, or plugin references landed on an archived repository with no supported path forward.

A kept-open repository should be a downstream consumer. It may contain Composer metadata, autoloading, compatibility adapters, deprecation notices, migration docs, tests for its preserved public surface, and direct calls into tagged `php-transformer` APIs. It should not contain new canonical transformation logic.

## Archive Criteria

Archive an old repository only after all of these are true:

- No active product, package, or public caller depends on its package name, plugin slug, or public entrypoints.
- A tagged `php-transformer` release provides the reusable behavior that repo previously owned.
- Replacement installation instructions are published in the old repository README, package metadata, and release notes.
- Open issues have been closed, migrated, or redirected to the correct downstream product or `php-transformer` tracker.
- Packagist, GitHub topic/search, and documentation links clearly point new users to `automattic/blocks-engine-php-transformer` or the active product repository.
- Maintainers agree that keeping the repository open would create more confusion than discoverability value.

Archive is a final cleanup step, not the first consolidation action. Popular old repositories should remain open as signposts and compatibility surfaces until the ecosystem has had time to move.

## Issue Routing

Route issues by ownership, not by where the symptom was first seen:

- Bugs in shared HTML, Markdown, blocks, artifact normalization, diagnostics, result envelopes, or WordPress runtime adapters belong in `php-transformer`.
- Bugs in old public entrypoints, deprecation behavior, legacy return-shape preservation, or package installation for a compatibility repository belong in that old repository while it remains open.
- Bugs in Static Site Importer UI, import orchestration, uploaded ZIP handling, page/theme creation, quality gates, or product reports belong in Static Site Importer.
- Requests for a missing reusable primitive should block downstream migration until `php-transformer` exposes the primitive cleanly; they should not be handled by copying new logic back into old repositories.

## Package Discoverability

During consolidation, old repositories should make the new package easier to find:

- README badges or top-level notices should point to `automattic/blocks-engine-php-transformer` as the canonical implementation.
- Composer metadata should mark old packages as compatibility or replacement entrypoints when a tagged shim exists.
- Release notes should name the first `php-transformer` version each shim requires.
- Search terms and examples should describe old repositories as downstream entrypoints or consumers, not as dependencies of `php-transformer`.

The desired outcome is that users can still arrive through the popular old names, but new implementation work and new direct integrations converge on `php-transformer`.
