# Figma Transformer Result Contract

`figma-transformer` returns `blocks-engine/figma-transformer/result/v1` envelopes.

The contract is intentionally static-HTML-first. Downstream products can pass generated HTML to `php-transformer`, Static Site Importer, Studio, or any other materialization layer.

The package boundary is:

```text
.fig file or decoded scenegraph
  -> normalized Figma IR
  -> static HTML/CSS/assets artifact
  -> php-transformer handles WordPress block conversion later
```

Required top-level fields:

- `schema`: `blocks-engine/figma-transformer/result/v1`
- `status`: `success`, `success_with_warnings`, or `failed`
- `diagnostics`: stable diagnostic records
- `files`: generated artifact files such as `index.html` and `style.css`
- `assets`: generated or extracted assets
- `source_reports.figma`: Figma intake, scenegraph, and provenance reports
- `parity`: `blocks-engine/figma-transformer/parity-report/v1`
- `metrics`: package-level transform metrics

## Parity Report

The parity report records source screenshot evidence, generated HTML screenshot evidence, side-by-side output, diff output, diff summaries, artifact paths, and runner-supplied metrics. Browser-heavy parity runners should persist artifacts through Homeboy or another reviewable artifact surface.

Required parity fields:

- `schema`: `blocks-engine/figma-transformer/parity-report/v1`
- `status`: `not_run`, `pending`, or `compared`
- `reason`: stable runner-readable reason string when useful
- `artifacts`: paths or URLs for report-level artifacts supplied by the caller
- `source`: source-design evidence such as screenshot path, viewport, frame ID, or capture metadata
- `generated`: generated HTML evidence such as screenshot path, viewport, URL, or capture metadata
- `side_by_side`: optional side-by-side artifact metadata
- `diff`: optional visual diff artifact metadata
- `diff_summary`: optional compact diff summary such as changed pixels, threshold, or ratio
- `metrics`: optional runner metrics

Status meanings:

- `not_run`: no parity runner has executed for this transform.
- `pending`: parity work is queued, external, or otherwise incomplete.
- `compared`: caller-supplied evidence describes a completed source-vs-generated comparison.

## `.fig` Decoder Limits

Arbitrary `.fig` files are not fully decoded by the PHP-native package yet. Current `.fig` support safely opens `.fig` files or wrapper ZIPs, identifies nested `.fig` entries, reports `fig-kiwi` prelude/version/chunk metadata, inventories embedded files/assets, and records compression diagnostics.

Next decoder milestones are schema chunk parsing, Zstandard message decoding when supported by the runtime, mapping decoded Kiwi messages into normalized IR, and expanding layout/paint/text/component/asset coverage against external real-file evidence.

## Fixture Strategy

Repository tests should use small synthetic fixtures for contract shape, deterministic output, and decoder safety. Large real `.fig` files should stay out of git. Real-design parity evidence should be generated externally, usually through Homeboy, and attached to the relevant issue or PR as reviewable artifacts.
