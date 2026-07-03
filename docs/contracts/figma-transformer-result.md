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

## Transform Diagnostics

`source_reports.figma.html.transform_diagnostics` uses schema `blocks-engine/figma-transformer/transform-diagnostics/v1`. It is a development/parity diagnostics envelope, not a rendering contract. It explains source nodes that were decoded but not materially emitted so visual gaps can be triaged without papering over output.

`source_reports.figma.html.visual_node_map` is the aggregate source-to-artifact evidence map. Each emitted visual node entry includes `id`, `rect`, `page_path`, optional `emitted_class`/`emitted_tag`, and multi-page provenance fields `source_page_index` and `source_page_frame_id`. The same traced entries are preserved in each `source_reports.figma.html.pages[].visual_node_map` page report so arbitrary `.fig` scale output can be traced from aggregate JSON to the generated page HTML and shared CSS selector.

Text coverage lives at `transform_diagnostics.text` with schema `blocks-engine/figma-transformer/text-coverage/v1`:

- `decoded_text_node_count`: non-empty decoded text nodes considered for emission.
- `emitted_text_node_count`: decoded text nodes whose `data-figma-node-id` appears in generated HTML.
- `missing_emitted_text_node_count`: decoded non-empty text nodes not found in generated HTML.
- `missing_emitted_text_reason_categories`: stable counts by reason.
- `missing_emitted_text_nodes[]`: sample nodes with `node_id`, `name`, `type`, `class`, `page_id`, `page_name`, `character_count`, and `reason`.

Asset coverage lives at `transform_diagnostics.images`:

- `node_refs`: raw nodes carrying an explicit asset reference or image paint.
- `asset_nodes[]`: sample nodes with `node_id`, `name`, `type`, `class`, `emitted`, `reason`, optional `path`, and `refs`.
- `asset_node_reason_categories`: stable counts by reason.
- `missing_assets[]`: asset-bearing node samples with no resolved archive asset path.

Initial omission reasons include `hidden`, `zero_area`, `parent_omitted`, `decorative`, `no_archive_asset`, `no_archive_asset_hash`, `clipped_masked`, `converted_to_background`, `converted_to_form_control`, and `not_emitted`.

Positional parity coverage lives at `transform_diagnostics.layout.positional_parity` with schema `blocks-engine/figma-transformer/positional-parity/v1`. It summarizes emitted CSS and layout evidence that can affect arbitrary `.fig` visual parity without changing runtime behavior:

- `full_bleed_viewport_width_count`: emitted `width:100vw` declarations.
- `full_bleed_breakout_count`: emitted viewport breakout declarations using `left:50%` plus `margin-left:±50vw`.
- `mirrored_transform_count`: emitted CSS matrix transforms with a negative horizontal or vertical scale component.
- `reflected_full_bleed_count`: emitted full-bleed reflected nodes using `margin-left:50vw` plus a mirrored matrix.
- `fixed_over_root_width_underlay_count`: decorative underlay samples whose fixed source width exceeds parent/root width.
- `fixed_over_root_width_underlays[]`: bounded samples with page, node, parent, geometry, and class evidence.
- `chrome_overflow_count`: off-canvas visual nodes associated with header/footer chrome.
- `chrome_overflow_nodes[]`: bounded samples with page, node, parent, geometry, and class evidence.
- `root_stacking_trace_count`: count of recorded stacking-context decision traces.
- `root_stacking_reason_counts`: stable stacking/z-index/overlap decision reason counts when present.
- `decision_trace_samples[]`: bounded positional decision trace samples derived from `decision_traces.samples` for effective geometry, stacking context, transform viewport, and responsive decisions. Samples include stable node/page/class identity plus compact source geometry, emitted CSS geometry, full-bleed/canvas-shell, stacking, transform, or responsive declaration evidence when present.

Status meanings:

- `not_run`: no parity runner has executed for this transform.
- `pending`: parity work is queued, external, or otherwise incomplete.
- `compared`: caller-supplied evidence describes a completed source-vs-generated comparison.

## `.fig` Decoder Limits

Arbitrary `.fig` files are not fully decoded by the PHP-native package yet. Current `.fig` support safely opens `.fig` files or wrapper ZIPs, identifies nested `.fig` entries, reports `fig-kiwi` prelude/version/chunk metadata, inventories embedded files/assets, and records compression diagnostics.

Next decoder milestones are schema chunk parsing, Zstandard message decoding when supported by the runtime, mapping decoded Kiwi messages into normalized IR, and expanding layout/paint/text/component/asset coverage against external real-file evidence.

## Fixture Strategy

Repository tests should use small synthetic fixtures for contract shape, deterministic output, and decoder safety. Large real `.fig` files should stay out of git. Real-design parity evidence should be generated externally, usually through Homeboy, and attached to the relevant issue or PR as reviewable artifacts.
