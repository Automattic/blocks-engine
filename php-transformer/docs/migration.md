# Migration Plan

## Phase 1: Draft Consolidation

- Establish the Composer package and namespace boundaries.
- Map existing repositories to target namespaces in transitional docs only.
- Add contract fixtures and result-envelope documentation.
- Import the lowest-level HTML-to-blocks behavior with minimal changes.

## Phase 2: Compatibility Wrappers

- Update existing packages to consume `php-transformer` through Composer or path repositories.
- Keep existing function names available in wrappers where external callers rely on them.
- Move tests toward shared contract fixtures.

## Phase 3: Product Adoption

- Point Static Site Importer at `php-transformer` for transformation work.
- Keep Static Site Importer focused on WordPress product workflows: intake, page creation, theme generation, activation, and CLI/admin UX.
- Point wp-site-generator improvement loops at `php-transformer` contract fixtures.

## Phase 4: Deprecation Decisions

- Decide which compatibility repositories remain useful as public packages.
- Archive only after known consumers have a stable replacement path.

## Transitional Consumer Context

The first migration wave treats the existing open source repositories as source inputs and future consumers of `php-transformer`. These names are local migration context, not canonical package API:

| Repository | Current role | Target role |
| --- | --- | --- |
| `chubes4/html-to-blocks-converter` | Converts raw HTML to Gutenberg block arrays. | Compatibility package or plugin wrapper around `PhpTransformer\\HtmlToBlocks`. |
| `chubes4/static-site-importer` | WordPress plugin product for importing static sites into pages/themes. | Product integration that consumes `php-transformer`; remains independently useful. |

Move canonical library behavior into `php-transformer`; keep product behavior and existing public integration surfaces outside the package until callers migrate deliberately.

Detailed repository fate, issue-routing, and discoverability notes live in [`consumer-prs/current-repo-map.md`](consumer-prs/current-repo-map.md).

## Optional Local Evidence Checks

Downstream example wrappers are intentionally not installed package examples. They live under [`consumer-prs/examples/`](consumer-prs/examples/) as copy/adapt sketches for consumer PRs. Lint and smoke-call them only when reviewing those migration plans:

```sh
composer test:migration:examples
```

Local migration comparisons are opt-in and are not required by default CI. They are evidence for downstream migration PRs, not a package feature or long-term legacy support promise. Set `BLOCKS_ENGINE_PARITY_LEGACY=1` plus the repo-specific path for the existing package you want to compare:

```sh
BLOCKS_ENGINE_PARITY_LEGACY=1 \
BLOCKS_ENGINE_PARITY_LEGACY_HTML_TO_BLOCKS_CONVERTER_PATH=/Users/chubes/Developer/html-to-blocks-converter \
composer test:migration:legacy-parity
```

Supported local path variables are:

| Repository | Environment variable |
| --- | --- |
| `html-to-blocks-converter` | `BLOCKS_ENGINE_PARITY_LEGACY_HTML_TO_BLOCKS_CONVERTER_PATH` |

Migration comparison code runs in an isolated PHP subprocess so old global functions, classes, constants, and bundled dependencies do not leak into the current transformer test process. Fixtures must opt in with `legacy_comparison.safe=true`; fixtures that require WordPress/Gutenberg runtime behavior should keep an explicit `skip` reason. The runner prints skipped comparisons with the reason, and any loaded safe comparison fails the parity run when the normalized migration snapshot differs from the current transformer snapshot.

## Wrapper Release Order

Release downstream wrappers after the transformer package has a tag that contains the result-envelope and namespace contracts they consume.

1. Tag `automattic/blocks-engine-php-transformer` with stable package metadata, autoloading, result envelopes, and fixture coverage.
2. Release `chubes4/html-to-blocks-converter` as a compatibility wrapper over transformer HTML conversion while preserving current public helpers and plugin behavior.
3. Update `chubes4/static-site-importer` to move product-owned adapter internals to direct transformer calls when migration evidence is available.

Static Site Importer should not require unpublished wrapper branches on merge. If it still needs unpublished wrapper behavior, the transformer PR remains draft or the affected Static Site Importer scope stays out of the merge path.
