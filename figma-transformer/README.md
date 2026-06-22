# Figma Transformer

Figma Transformer is a PHP primitive for converting Figma designs into static HTML website artifacts.

This package is intentionally WordPress-native and product-neutral. It owns Figma intake, scenegraph normalization, static HTML artifact generation, and visual-parity report contracts. It does not own WordPress page creation, block conversion, theme activation, Studio orchestration, or Static Site Importer UI.

## Boundary

Figma Transformer owns reusable transformation primitives:

- `.fig` archive intake and safety diagnostics.
- Figma `fig-kiwi` archive metadata parsing.
- Figma scenegraph normalization.
- Static HTML, CSS, and asset artifact generation.
- Figma-vs-HTML visual parity report contracts.
- Generic provenance metadata that lets callers trace HTML back to Figma nodes.

Figma Transformer does not convert HTML into WordPress blocks. Use `php-transformer` for HTML, Markdown, and website artifact conversion into WordPress-native block outputs.

## Public API Surface

Consumers should treat these classes and helper functions as the public entrypoints for the current package:

- `FigmaTransformer` - transforms `.fig` files or normalized scenegraph arrays into static HTML result envelopes.
- `Contract\FigmaTransformResult` - stable result envelope for process, HTTP, fixture, and compatibility boundaries.
- `FigFile\FigArchiveReader` - reads safe `.fig` archive metadata and embedded asset manifests.
- `Html\StaticHtmlEmitter` - emits deterministic static HTML artifact files from a normalized scenegraph.
- `Parity\ParityReportBuilder` - builds the parity report envelope from source/generated screenshot evidence and metrics supplied by the caller.

## WordPress Plugin Usage

Install or symlink the `figma-transformer/` directory into `wp-content/plugins/blocks-engine-figma-transformer/`, run Composer when available, and activate **Blocks Engine Figma Transformer**.

```php
$result = blocks_engine_figma_transformer_transform_file('/path/to/design.fig', array(
    'source' => 'upload:design.fig',
));
```

Available plugin helpers:

- `blocks_engine_figma_transformer_version()`
- `blocks_engine_figma_transformer_path()`
- `blocks_engine_figma_transformer_transform_file()`
- `blocks_engine_figma_transformer_transform_scenegraph()`

## Current Draft Status

This package currently scaffolds the public API and `.fig` intake contract. Full pure-PHP Kiwi message decoding, source screenshot rendering, and browser-backed visual parity scoring are planned behind the contracts introduced here.

The first target fixture is a local `.fig_.zip` export containing `Fisiostetic.fig`, whose inner `canvas.fig` starts with `fig-kiwi` and uses a raw-deflate schema chunk plus a Zstandard-compressed message chunk.

## Output Contract

Successful transforms produce a static website artifact:

```text
artifact/
  index.html
  style.css
  assets/
  parity-report.json
```

The result envelope includes:

- `schema: "blocks-engine/figma-transformer/result/v1"`
- `status`
- `diagnostics[]`
- `files[]`
- `assets[]`
- `source_reports.figma`
- `parity`
- `metrics`

## Parity Contract

Visual parity is measured outside the WordPress import path. The package records source and generated screenshot evidence, side-by-side comparisons, diff images, and metrics supplied by the parity runner. Homeboy is the expected runner for browser-heavy parity workflows; WordPress-only consumers can still read and display the parity report.
