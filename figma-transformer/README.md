# Figma Transformer

Figma Transformer is a PHP primitive for converting Figma designs into static HTML website artifacts.

This package is intentionally WordPress-native and product-neutral. It owns Figma intake, scenegraph normalization, static HTML artifact generation, and visual-parity report contracts. It does not own WordPress page creation, block conversion, theme activation, Studio orchestration, or Static Site Importer UI.

## Package Contract

The package contract is:

```text
.fig file or decoded scenegraph
  -> normalized Figma IR
  -> static HTML/CSS/assets artifact
  -> php-transformer converts static artifacts to WordPress blocks later
```

`figma-transformer` stops at static website artifacts. WordPress block materialization remains a downstream `php-transformer` responsibility so Figma intake, HTML parity, and WordPress block conversion can evolve independently.

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

### Current `.fig` Support Limits

Arbitrary `.fig` files are not fully decoded today. The current file path is an intake and diagnostics layer that safely opens `.fig` or wrapper ZIP files, identifies nested `.fig` entries, reports `fig-kiwi` metadata, inventories embedded files/assets, and records compression capabilities. It does not yet reconstruct a complete Figma scenegraph from production Kiwi payloads.

Next decoder milestones:

- Parse the raw-deflate schema chunk into a useful schema model.
- Decode Zstandard message chunks when the PHP runtime has a supported Zstandard capability.
- Map decoded Kiwi messages into the normalized IR already accepted by `transformScenegraph()`.
- Expand layout, paint, text, component, and asset coverage against external real-file evidence.

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

Visual parity is measured outside the WordPress import path. The package records source and generated screenshot evidence, side-by-side comparisons, diff images, diff summaries, artifact paths, and metrics supplied by the parity runner. Homeboy or another external browser-backed runner is expected to produce screenshots and image diffs, then pass artifact metadata into this package. WordPress-only consumers can read and display the parity report without running a browser.

Parity report statuses:

- `not_run`: no parity runner has executed for this transform.
- `pending`: parity work is queued, external, or otherwise incomplete.
- `compared`: caller-supplied source/generated evidence and diff data describe a completed comparison.
- `pass`: caller-supplied diff data is within the supplied threshold.
- `fail`: caller-supplied diff data exceeds the supplied threshold.

Parity runners can attach evidence through the `parity` transform option or the CLI metadata flags. The contract accepts source screenshot URL/path metadata, generated screenshot artifact metadata, diff image artifact metadata, pixel mismatch count/ratio, threshold, viewport, and frame id. The transformer stores those references; it does not fetch, render, compare, or commit screenshot files.

```php
$result = blocks_engine_figma_transformer_transform_scenegraph($scenegraph, array(
    'frame_id' => '1:1',
    'parity' => array(
        'status' => 'pass',
        'frame_id' => '1:1',
        'source_screenshot_url' => 'https://artifacts.example.test/source.png',
        'generated_screenshot_artifact' => 'homeboy://runs/123/generated.png',
        'diff_image_artifact' => 'homeboy://runs/123/diff.png',
        'pixel_mismatch_count' => 10,
        'pixel_mismatch_ratio' => 0.005,
        'threshold' => 0.01,
        'viewport' => array(
            'width' => 1200,
            'height' => 800,
        ),
    ),
));
```

```sh
figma-transformer scenegraph.json \
  --frame-id=1:1 \
  --parity-status=pass \
  --parity-source-screenshot-url=https://artifacts.example.test/source.png \
  --parity-generated-screenshot-artifact=homeboy://runs/123/generated.png \
  --parity-diff-image-artifact=homeboy://runs/123/diff.png \
  --parity-pixel-mismatch-count=10 \
  --parity-pixel-mismatch-ratio=0.005 \
  --parity-threshold=0.01 \
  --parity-viewport=1200x800
```

Homeboy/external runner workflow:

1. Run the Figma transform and persist the static HTML/CSS/assets output.
2. Render the original design and generated artifact in an external browser-backed runner.
3. Upload screenshots, diff images, and any machine-readable diff report to a reviewable artifact surface attached to the issue, PR, or runner record.
4. Re-run or wrap the transform with the parity metadata above so `parity-report.json` contains stable artifact references and numeric comparison results.
5. Link the PR or issue to the external artifact record rather than local paths or localhost URLs.

## Fixture Strategy

Contract tests use small synthetic fixtures that exercise the public envelope, archive safety, deterministic HTML/CSS output, and parity report shape. Large real `.fig` exports are not committed to the repository. When real-file parity evidence is needed, generate it externally through Homeboy or another reviewable artifact surface and attach the resulting reports/screenshots to the relevant issue or PR.
