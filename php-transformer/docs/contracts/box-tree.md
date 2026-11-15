# Box-tree evidence v1

The producer-neutral schema is `blocks-engine/box-tree/v1`, published in
[`resources/box-tree.schema.json`](../../resources/box-tree.schema.json).
`Contract\BoxTree` owns the property vocabulary, identity and bounds;
`ArtifactCompiler\BoxTreeReport` performs structural and source-binding validation.

## Producer requirements

Write one `box-tree/<route>.json` artifact file for each captured route. Set
`source_path` to the exact artifact-relative HTML path and `source_hash` to the
SHA-256 of its final UTF-8 bytes (including node attributes). Declare large
sidecars in the artifact `reports` list to use capture-report byte budgets.
The reader also applies a hard 32 MiB decoded sidecar limit, 100,000 nodes,
2–8 viewports, viewport dimensions of 1–32,768 CSS pixels, geometry bounded
by 100,000,000 CSS pixels, and 4,096 bytes per used style string.

Every element, including `html`, `head` and non-rendering elements, must carry
`data-blocks-engine-node-id`. IDs are route-local, unique, 1–80 ASCII letters,
digits, dots, underscores, colons or hyphens. They identify a source element,
not a viewport position: preserve the same ID in desktop/mobile documents and
all observations. Do not regenerate IDs after resizing; a genuinely different
DOM needs its own document/hash. Include zero-sized boxes for non-rendering
elements. No IDs are synthesized by the transformer.

The sidecar has `viewports` keyed by name (required: `desktop`, `mobile`), each
with integer `width` and `height`, and `nodes` keyed by node ID. Each node has:

- `children`: immediate element child IDs in DOM order (no text/comment IDs);
- `viewports`: exactly one observation for every declared viewport;
- each observation: `border_box: {x, y, width, height}` in viewport-relative CSS
  pixels, and `used_styles`, containing every property in the published schema.

Use computed/used values, not authored declarations; explicit strings such as
`none`, `normal`, `auto` and `0px` are significant. Physical longhands cover
padding, margin, border and overflow; the schema lists flex/grid container and
item properties, paint/compositing properties, and sizing min/max values.

## Transformer behavior

The artifact normalizer reads sidecars directly from `files`, validates the
schema, bounds, source hash, unique node IDs, complete viewport observations
and exact DOM child topology. A route's evidence is accepted atomically.
Invalid evidence produces a warning diagnostic (`box_tree_schema_invalid`,
`box_tree_bounds_invalid`, `box_tree_identity_stale`,
`box_tree_viewport_invalid`, `box_tree_geometry_invalid`,
`box_tree_styles_invalid`, or `box_tree_duplicate_route`). Conversion proceeds
without rejected evidence; this evidence never authorizes layout reductions.

Normalized HTML files carry `box_tree`. The compiler passes it into each HTML
session; `HtmlTransformerSession::boxTreeEvidence(DOMElement)` exposes the
viewport observations for that exact source element. This is independent of
conversion decisions and emitted attributes. `source_reports.box_tree` reports
per-source-path `nodes_with_evidence`, `source_nodes` (all source DOM elements,
not emitted blocks), and `coverage`. Missing/rejected evidence has zero coverage.

The checked-in `tests/fixtures/box-tree` fixture and
`tests/contract/box-tree.php` exercise public artifact intake, full desktop/mobile
coverage, conservative rejection and byte-identical serialized blocks.
Browser capture production belongs to Data Liberation's follow-up; this fixture
is not a claim that a live capture pipeline has been implemented here.

## Layout reduction proof

Reduction proof remains separate because it describes a wrapper-free simulation,
not merely the captured tree. Its single producer-neutral schema name is now
`blocks-engine/layout-geometry-proof/v1`. The former capture-specific and
PHP-transformer-specific names are not accepted aliases. See
[layout-geometry-proof.md](layout-geometry-proof.md).
