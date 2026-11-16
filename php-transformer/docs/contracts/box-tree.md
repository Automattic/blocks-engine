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
1–8 viewports, viewport dimensions of 1–32,768 CSS pixels, geometry bounded
by 100,000,000 CSS pixels, and 4,096 bytes per used style string.

Every element, including `html`, `head` and non-rendering elements, must carry
`data-blocks-engine-node-id`. IDs are route-local, unique, 1–80 ASCII letters,
digits, dots, underscores, colons or hyphens. They identify a source element,
not a viewport position: preserve the same ID in desktop/mobile documents and
all observations. Do not regenerate IDs after resizing; a genuinely different
DOM needs its own document/hash. Include zero-sized boxes for non-rendering
elements. No IDs are synthesized by the transformer.

The sidecar has `viewports` keyed by the names served by that document, each
with integer `width` and `height`, and `nodes` keyed by node ID. Each node has:

- `children`: immediate element child IDs in DOM order (no text/comment IDs);
- `viewports`: exactly one observation for every declared viewport;
- each observation: `border_box: {x, y, width, height}` in viewport-relative CSS
  pixels, and `used_styles`, containing every property in the published schema.

Use computed/used values, not authored declarations; explicit strings such as
`none`, `normal`, `auto` and `0px` are significant. Physical longhands cover
padding, margin, border and overflow; the schema lists flex/grid container and
item properties, paint/compositing properties, and sizing min/max values.
Background and mask evidence uses `background-color`, `background-image`,
`mask-image`, and `mask-mode`, not the `background` or `mask` shorthands; there
are no shorthand aliases. A desktop document served separately from
`html-mobile/…` needs only its desktop observation. Every declared viewport
still requires an observation on every node. Producers must update in lockstep.

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

## Report-only classification (ADR 0005 slice 2)

`source_reports.box_tree[route].classification` contains `counts` keyed by
`load-bearing` and `neutral`, and `nodes` keyed by source ID with `disposition`
and the deciding `criterion`. Criteria are `paints`, `clips`, `multi-child-layout`,
`containing-block`, `semantics/id/anchor`, `interaction/state`, `motion`, and
`own-sizing`; neutral nodes have `equal-child-geometry`. Precedence follows that
order. Neutrality requires one element child, no direct non-whitespace text, and
matching x/y/width/height at **every** viewport (0.01 CSS pixel tolerance).
Containing-block ownership is resolved separately at each viewport, including
transformed ancestors of fixed descendants. These are reports, not collapse
instructions; converted blocks and author styles are unchanged.
Transparent background colors (including zero-alpha rgba and color() values)
and image-free masks do not paint. Identity transforms do not paint, but still
establish containing blocks. Overflow is `clips` only when a descendant extends
past the observed box on a clipped axis. IDs alone do not preserve wrappers:
fragment hrefs, ARIA ID references, and label `for` references do. Captured
interaction targets are preserved through their node's `interaction_states`.

The v1 evidence shape additionally accepts optional used-style strings
`animation-name`, `transition-property`, and `transition-duration`. Producers
should populate them from CSSOM, including stylesheet-only motion. A node may
also supply `interaction_states`, up to 32 captured state names (same identifier
syntax as viewport names), e.g. `["hover", "focus"]`. A nonempty list preserves
the node independently of its resting appearance. These names indicate captured
states; they do not supply state geometry or authorize restyling. Older sidecars
remain readable; omitted motion/state fields cannot prove absence of behavior.

`projection` estimates block-producing boxes below body after neutral chains
collapse (`basis: estimated-block-producing-boxes`): maximum `depth`, surviving
non-leaf `groups`, estimated block `leaves`, and `groups_per_leaf`. Inline content,
non-rendering metadata and navigation internals are excluded; a nav counts once.
Body and head are excluded. Actual converted block depth belongs to slice 3. It is not an emitted-block measurement: pattern expansion and
non-rendering source leaves can differ from WordPress blocks. Missing/rejected
evidence has empty classifications and a null projection, never an optimistic
zero-depth estimate. Live whole-site/sdnunca measurement is deferred until the
capture producer lands; checked-in v1-shaped fixtures gate this slice.

Run `php tests/contract/box-tree-classification.php` and
`php tests/contract/box-tree.php` from `php-transformer` for criterion coverage,
projection budgets, output invariance and staged/multi-route report parity.

## Layout reduction proof

Reduction proof remains separate because it describes a wrapper-free simulation,
not merely the captured tree. Its single producer-neutral schema name is now
`blocks-engine/layout-geometry-proof/v1`. The former capture-specific and
PHP-transformer-specific names are not accepted aliases. See
[layout-geometry-proof.md](layout-geometry-proof.md).
