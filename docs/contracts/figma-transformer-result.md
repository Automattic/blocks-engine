# Figma Transformer Result Contract

`figma-transformer` returns `blocks-engine/figma-transformer/result/v1` envelopes.

The contract is intentionally static-HTML-first. Downstream products can pass generated HTML to `php-transformer`, Static Site Importer, Studio, or any other materialization layer.

Required top-level fields:

- `schema`: `blocks-engine/figma-transformer/result/v1`
- `status`: `success`, `success_with_warnings`, or `failed`
- `diagnostics`: stable diagnostic records
- `files`: generated artifact files such as `index.html` and `style.css`
- `assets`: generated or extracted assets
- `source_reports.figma`: Figma intake, scenegraph, and provenance reports
- `parity`: `blocks-engine/figma-transformer/parity-report/v1`
- `metrics`: package-level transform metrics

The parity report records source screenshot evidence, generated HTML screenshot evidence, side-by-side output, diff output, and runner-supplied metrics. Browser-heavy parity runners should persist artifacts through Homeboy or another reviewable artifact surface.
