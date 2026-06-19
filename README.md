# Blocks Engine

Blocks Engine is a collection of tools for generating, transforming, and materializing WordPress blocks.

## Packages

- [`php-transformer`](php-transformer/) - PHP primitives for converting HTML, Markdown, and generated website artifacts into WordPress-native block outputs.

## Package Continuity

Popular existing package names remain downstream continuity surfaces while the canonical implementation moves into `automattic/blocks-engine-php-transformer`. Consumers that already require `chubes4/html-to-blocks-converter`, `chubes4/block-format-bridge`, `chubes4/block-artifact-compiler`, or `chubes4/static-site-importer` should continue using tagged releases of those packages until their maintainers publish migration notes for direct transformer adoption.

Do not archive or redirect those repositories immediately. Their READMEs and issue templates should identify the transformer as the canonical implementation target while still accepting compatibility, packaging, and migration reports for the old package names.

## Release Planning

- [`docs/packaging.md`](docs/packaging.md) - Composer package naming, installation modes, dependency prefixing, draft-exit criteria, and product acceptance gates.

Transitional migration notes for existing consumers live under [`php-transformer/docs/`](php-transformer/docs/). They are local planning material, not part of the package API.
