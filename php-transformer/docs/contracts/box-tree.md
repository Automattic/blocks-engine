# Box-tree evidence v2

The producer-neutral schema is `blocks-engine/box-tree/v2`, published in
[`resources/box-tree.schema.json`](../../resources/box-tree.schema.json).
`Contract\BoxTree` owns the property vocabulary, identity and bounds;
`ArtifactCompiler\BoxTreeReport` performs structural and source-binding validation.

## Producer requirements

Write one `box-tree/<route>.json` artifact file for each captured route. Set
`source_path` to the exact artifact-relative HTML path and `source_hash` to the
SHA-256 of its final UTF-8 page bytes (including node attributes, before include
expansion). For pages using `<!--#include virtual="/parts/…" -->`, supply
`includes: { "<artifact-relative part path>": "<sha256 of raw part bytes>" }`
for every transitively included part. The set must be exact; missing, extra or
changed hashes reject the sidecar. Paths include the artifact's virtual root
(e.g. `website/parts/header.html`). Without includes this field may be omitted.
Topology is checked on the include-resolved document, using the compiler's
canonical include expansion, not on the page bytes alone. Sidecars under
`box-tree/**/*.json`, including `website/box-tree/`, automatically use
capture-report byte budgets and are never delivered as theme assets or copied
files, whether accepted or rejected.
The reader also applies a hard 32 MiB decoded sidecar limit, 100,000 nodes,
1–8 viewports, viewport dimensions of 1–32,768 CSS pixels, geometry bounded
by 100,000,000 CSS pixels, and 4,096 bytes per used style string.

An element is observed only when its `data-blocks-engine-node-id` occurs exactly
once in the resolved document and is a key in `nodes`. All other elements are
unobserved: missing, empty or foreign IDs, and every occurrence of a duplicated
ID. They receive no evidence-based restyling and cannot be classified neutral
or collapsed using evidence. Every recorded node must be observed; a record
for a missing or duplicated ID rejects the whole sidecar. Producers must project
nodes from the resolved document, dropping duplicated IDs and IDs absent from
that route's capture. Recorded IDs are route-local, 1–80 ASCII letters,
digits, dots, underscores, colons or hyphens. They identify a source element,
not a viewport position: preserve the same ID in desktop/mobile documents and
all observations. Do not regenerate IDs after resizing; a genuinely different
DOM needs its own document/hash. Include zero-sized boxes for non-rendering
elements. No IDs are synthesized by the transformer.

The sidecar has `viewports` keyed by the names served by that document, each
with integer `width` and `height`, and `nodes` keyed by node ID. Each node has:

- `children`: nearest observed descendant IDs in DOM order, traversing through
  unobserved wrappers but stopping at observed elements (no text/comment IDs);
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

## v2 rendered styles and pseudo-elements

The required vocabulary also covers typography, text color/decorations, placement
(including physical offsets), object fitting, compositing and background geometry.
Use the exact longhand names in the schema; all are required on every observation.

An observation may include `pseudo: { before?, after? }`. Include a pseudo only
when its computed `content` is neither `none` nor `normal` (the CSS string `""`
is valid). Each entry has `content`, the full `used_styles` vocabulary, and its
own `border_box` from the pseudo's computed geometry, with the same bounds as
an element observation. Other pseudo names and malformed entries reject the
whole sidecar (`box_tree_pseudo_invalid`, or the geometry/style diagnostic).
Rendering pseudos classify their originating node as `paints`; typography alone
does not affect wrapper neutrality.

The style projector emits pseudo selectors and viewport resets. Inherited CSS
properties (typography, color, visibility, cursor and list style) are emitted only
where used values differ from the source parent's observation. Pseudos compare
against their originating element. Non-inherited text properties, such as
`text-decoration-*` and `vertical-align`, remain local. This limits repeated
route CSS toward the 90 KB target; live capture size measurement still requires
the producer pipeline.

## Transformer behavior

The artifact normalizer reads sidecars directly from `files`, validates the
schema, bounds, page and included-part hashes, unique observed node IDs,
complete viewport observations and exact include-resolved observed DOM child topology. A route's evidence is accepted atomically.
Rejection diagnostics retain the sidecar `path` and, when available, its declared
`source_path`, so import reports can attribute the rejection code to a route.
Invalid evidence produces a warning diagnostic (`box_tree_schema_invalid`,
`box_tree_bounds_invalid`, `box_tree_identity_stale`,
`box_tree_viewport_invalid`, `box_tree_geometry_invalid`,
`box_tree_styles_invalid`, or `box_tree_duplicate_route`). Conversion proceeds
without rejected evidence; this evidence never authorizes layout reductions.

Routes may declare different viewport sets (a producer can sample mobile HTML
per template cluster). After acceptance, routes whose header or footer shares a
source identity and agrees on every viewport they have in common merge their
chrome observations as a union of viewports, and record each other as chrome
peers of that landmark root. A route that samples an extra viewport therefore
joins the shared chrome, which gains that viewport's geometry instead of
forking. The route's other nodes, its page content, keep route-local evidence. Classification treats an
observation missing at one viewport conservatively: equal child geometry and
containment inside a clip are unproven there. The contract is
`tests/unit/shared-header-extra-viewport-box-tree.php`.

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
`load-bearing`, `neutral` and `folded`, and `nodes` keyed by source ID with `disposition`
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
Autonomous custom elements (hyphenated tag names such as `<interact-element>`,
excluding the spec-reserved names) have no implicit role, so the semantics check
treats them like `div`/`span`. A custom element is kept as `interaction/state`
when a document `<style>` rule that applies only in a non-resting state
(`:hover`, `:focus`, `:focus-visible`, `:focus-within`, `:active`, `:checked`,
`:target`, or a replayed `data-interact-*` state attribute) matches it in any
compound of its selector, so the box that rule hangs on survives.

The v2 evidence shape additionally accepts optional used-style strings
`animation-name`, `transition-property`, and `transition-duration`. Producers
should populate them from CSSOM, including stylesheet-only motion. A node may
also supply `interaction_states`, up to 32 captured state names (same identifier
syntax as viewport names), e.g. `["hover", "focus"]`. A nonempty list preserves
the node independently of its resting appearance. These names indicate captured
states; they do not supply state geometry or authorize restyling. Omitted motion/state fields cannot prove absence of behavior. v1 sidecars are
not accepted evidence: they receive `box_tree_schema_invalid`, and the route
retains the author-CSS conversion path. No compatibility layer is provided.

`projection` estimates block-producing boxes below body after neutral chains
collapse (`basis: estimated-block-producing-boxes`): maximum `depth`, surviving
non-leaf `groups`, estimated block `leaves`, and `groups_per_leaf`. Inline content,
non-rendering metadata and navigation internals are excluded; a nav counts once.
Body and head are excluded. Actual converted block depth belongs to slice 3. It is not an emitted-block measurement: pattern expansion and
non-rendering source leaves can differ from WordPress blocks. Missing/rejected
evidence has empty classifications and a null projection, never an optimistic
zero-depth estimate. Live whole-site/sdnunca measurement is deferred until the
capture producer lands; checked-in v2-shaped fixtures gate this slice.

Run `php tests/contract/box-tree-classification.php` and
`php tests/contract/box-tree.php` from `php-transformer` for criterion coverage,
projection budgets, output invariance and staged/multi-route report parity.

## Folding style-only boxes (ADR 0005 slice 5)

On an evidence route, a single-child box whose criterion is `paints`,
`containing-block`, `own-sizing` or `own-placement` is classified `folded` when
its declarations can move onto its sole child, proved by geometry at **every**
captured viewport. Like a neutral box, a folded box emits no block; its child
takes its place and carries the moved declarations in its own engine rule. The
node keeps its deciding `criterion` and lists the applied rules in `fold`;
`counts.folded` counts folded boxes and `fold_counts` counts folds per rule
(one box can apply several rules).

- `own-sizing`: each padding side becomes the child's margin on that side when
  the child has no margin there and its border box sits exactly inside the
  box's padding edge (0.01px). A vertical side needs a flex or grid parent, where
  the new margin cannot collapse. Sizing constraints (`min-*`, `max-*`) move only
  when the child's border box equals the box's and neither has padding or
  borders; otherwise non-initial constraints refuse the fold.
- `paint`: background longhands, `border-radius`, `box-shadow` and `outline`
  move when the child's border box equals the box's and the child paints none
  of them. A painting border has a width, so it never folds.
- `containing-block`: `position: relative` and its offsets move to a static
  child whose border box equals the box's, so positioned descendants resolve
  against the same padding box.
- `own-placement`: grid lines, `justify-self`/`align-self`, `order`, flex item
  factors, `float`/`clear` and margins move when the child's own values are
  initial and its margin box then equals the box's margin box.

Inherited values move with every fold. When the box's inherited value
(`color`, `font-*`, `line-height`, `letter-spacing`, `word-spacing`, `text-*`,
`white-space`, word breaking, list style, `visibility`, `cursor`) differs from
its block parent's at any capture, and the child repeats the box's value at every
capture, the value moves to the child with the box's author bands. This keeps
the subtree from re-basing on the new parent. If the child's value differs
anywhere, the fold is refused when the property can be derived from the box's
value (`font-size`, `line-height`, `text-indent`: an `em` or `%` of it). It is
also refused when the child inherits the box's value at some captures but not
others. Otherwise the child's own value stands.

Never folded: rendering pseudo-elements, clipping overflow, transforms (even
identity), filters, backdrop filters, opacity, blending, isolation, clip paths,
masks, z-index, non-static/relative boxes or children, inline or `contents`
children, a box that is a flex/grid/flow-root formatting context inside a block
parent (unless the child establishes one itself), and every behavior criterion
(semantics, references, interaction or state rules of any tag, capture markers,
motion, unobserved children, direct text). Boxes are classified in document
order on effective observations, so a chain of folds composes.

Moved declarations keep the box's author bands: the box's own fluid lift (in
the box's context) replaces the child's for every moved property, renamed from
padding to margin where needed (an `em` band moves only when the font sizes
agree). The child's `width`/`height` keep its captured pixels unless the box's
intent is an intrinsic keyword that also holds for the child, or the child's
border box equals the box's at every capture and the child's size measures its
border box (border-box sizing, or no padding or border on that axis): then the
box's lifted size of any kind (a `%` of the containing block the child now
shares, a viewport length) sizes the child. Lifting treats
every collapsed box as `display: contents`, so percentages resolve against the
containing block the output actually has. A projected style group the block
cannot serialize (a paragraph's shadow) stays in the engine rule.

Run `php tests/contract/box-tree-fold.php` for one fold and one refusal per rule.

## Layout reduction proof

Reduction proof remains separate because it describes a wrapper-free simulation,
not merely the captured tree. Its single producer-neutral schema name is now
`blocks-engine/layout-geometry-proof/v1`. The former capture-specific and
PHP-transformer-specific names are not accepted aliases. See
[layout-geometry-proof.md](layout-geometry-proof.md).
