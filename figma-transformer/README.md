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

### Zstandard Support

Zstandard decoding is required for direct imports of modern `.fig` files because Figma stores the main Kiwi message chunk as zstd-compressed data. The Composer package requires `ext-zstd` for normal installs.

Supported decoder paths:

- `ext-zstd` with `zstd_uncompress()` available.
- An explicit `Compression\ZstdCapability` adapter callable for operator/local verification when the host provides a trusted decoder through another boundary.
- A WordPress filter adapter registered on `blocks_engine_figma_transformer_zstd_decoder` for environments that intentionally provide decoding outside the extension.
- The CLI-only `--zstd-command=/path/to/zstd` option for local operator checks. This is not used implicitly by the library or plugin runtime.

Adapter callables receive the compressed payload and context array, and return decoded bytes or an array with `data` and optional `diagnostics`:

```php
$zstd = new Automattic\BlocksEngine\FigmaTransformer\Compression\ZstdCapability(
    static function (string $payload, array $context): string|false {
        return my_zstd_decode($payload);
    }
);
```

```php
add_filter(
    'blocks_engine_figma_transformer_zstd_decoder',
    static fn () => static fn (string $payload, array $context): string|false => my_zstd_decode($payload)
);
```

No pure-PHP Zstandard decoder is bundled today. Unsupported runtimes report `figma_transformer_zstd_extension_missing` or adapter failure diagnostics and continue parsing the rest of the archive metadata.

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

### Local Real-File Checks

Use real `.fig` files only as operator-owned, non-committed inputs. Good manual sources are designs you own, Figma Community files or templates that allow duplication/export, and files accessible through the Figma REST API.

Recommended local layout:

```text
~/Downloads/figma-transformer-fixtures/
  source.fig
  source-api.json
  evidence/
```

Example CLI checks:

```sh
mkdir -p "$HOME/Downloads/figma-transformer-fixtures/evidence"
php figma-transformer/bin/figma-transformer "$HOME/Downloads/figma-transformer-fixtures/source.fig" > "$HOME/Downloads/figma-transformer-fixtures/evidence/source-result.json"
php figma-transformer/bin/figma-transformer "$HOME/Downloads/figma-transformer-fixtures/source-api.json" > "$HOME/Downloads/figma-transformer-fixtures/evidence/source-api-result.json"
```

For large production `.fig` files, keep diagnostics bounded by omitting embedded asset bytes from the JSON envelope:

```sh
php figma-transformer/bin/figma-transformer "$HOME/Downloads/figma-transformer-fixtures/source.fig" \
  --omit-asset-content \
  --zstd-command=/path/to/zstd \
  > "$HOME/Downloads/figma-transformer-fixtures/evidence/source-result.json"
```

The CLI also accepts `--max-kiwi-message-decode-bytes=<bytes>` to control the eager Kiwi message decode guard. Large production messages above the limit are reported with `figma_transformer_kiwi_message_decode_skipped_size` instead of being materialized into memory. Full production scenegraph extraction should use selective Kiwi decoding rather than eager whole-message materialization.

Evidence to keep with the issue or PR:

- The original Figma URL and file key, not the `.fig` file.
- Whether the input came from **File > Save local copy**, a Community duplicate, or `GET /v1/files/:key`.
- The result envelope JSON, diagnostics, generated artifact manifest, and parity screenshots/diffs when a parity runner was used.
- Runtime details that affect diagnostics, especially PHP version, `ZipArchive`, and Zstandard support.

Do not copy real `.fig` exports, downloaded image fills, rendered screenshots, or proprietary customer designs into repository fixtures. If a real file exposes a decoder gap, reduce it to a synthetic fixture or attach non-sensitive evidence through the relevant issue/PR artifact workflow.
