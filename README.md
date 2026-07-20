# Blocks Engine

Blocks Engine is a collection of tools for generating, transforming, and materializing WordPress blocks.

## Packages

- [`@automattic/blocks-engine`](packages/blocks-engine/) - JavaScript primitives for transforming content into WordPress-native block outputs.
- [`php-transformer`](php-transformer/) - PHP primitives for converting HTML, Markdown, and generated website artifacts into WordPress-native block outputs.
- [`figma-transformer`](figma-transformer/) - PHP primitives for converting Figma `.fig` archives and Figma-derived scenegraphs into static HTML website artifacts with parity diagnostics.

Artifact compilation also emits the additive [`wordpress-site-plan/v1`](docs/contracts/wordpress-site-plan-v1.md) downstream materialization contract.
