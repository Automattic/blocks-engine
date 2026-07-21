# Blocks Engine

Blocks Engine is a collection of tools for generating, transforming, and materializing WordPress blocks.

## Packages

- [`@automattic/blocks-engine`](packages/blocks-engine/) - JavaScript primitives for transforming content into WordPress-native block outputs.
- [`php-transformer`](php-transformer/) - PHP primitives for converting HTML, Markdown, and generated website artifacts into WordPress-native block outputs.
- [`figma-transformer`](figma-transformer/) - PHP primitives for converting Figma `.fig` archives and Figma-derived scenegraphs into static HTML website artifacts with parity diagnostics.

Artifact compilation also emits the self-contained [`wordpress-site-plan/v2`](docs/contracts/wordpress-site-plan-v2.md) block-theme materialization contract.

The [Figma-to-WordPress acceptance matrix](docs/contracts/figma-wordpress-acceptance-matrix-v2.md) consumes that handoff through generic, configurable materialization providers and attributes parity independently across Figma-to-HTML, HTML-to-WordPress, and end-to-end boundaries.
