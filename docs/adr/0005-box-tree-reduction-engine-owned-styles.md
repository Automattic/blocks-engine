# Reduce source structure from the captured box tree; ship only engine-owned styles

Captured sites become block trees shaped by what the browser rendered, not by the
source markup's nesting. Every wrapper that renders nothing of its own collapses, and
the imported theme carries only styles the engine generates: block attributes,
`theme.json` presets, and engine-owned class rules. The source stylesheet is read as
evidence and never shipped.

## Context

Visual builders and AI site builders wrap each leaf in many neutral elements and
style them with framework CSS bound to that nesting
(`._image3 ._wowImage { mask-image: … }`). The transformer shipped that CSS, so it
could only drop a wrapper when every author selector still matched without it
(`WrapperCoalescer::selectorMatchingSurvivesWrapperCoalescing()`). On builder sites
almost none qualify. One captured Wix image sat under 12 `core/group` blocks; the
theme carried 3.7 MB of CSS. More than 30 coalescing fixes traded depth against
parity because they kept the selector constraint. Nesting depth, wrapper weight,
literal styles and theme CSS weight all come from that one constraint.

The capture already runs a real browser at several viewports. That evidence, not
the selectors, is the ground truth for what each element contributes.

## Decision

1. **Box-tree evidence is a capture contract.** The capture producer records each
   element's used styles and border box at every captured viewport, keyed to the
   source node identity the transformer already uses, together with captured
   interaction states. One schema serves the producer and the transformer.
2. **Boxes are classified by what they render.** A box is load-bearing when it:
   - paints: background, border, outline, shadow, mask, filter, opacity, blend mode,
     transform, or clipping overflow;
   - lays out more than one child: flex or grid with gaps, alignment or tracks;
   - is the containing block of a positioned descendant;
   - has a size that differs from its content's at any captured viewport;
   - or carries semantics, an id or anchor target, interaction, state, or motion.

   Every other box is **neutral**: at every captured viewport its single child's
   geometry equals its own.
3. **Neutral chains collapse in one tree pass** into the nearest load-bearing box
   or leaf. Each collapse is backed by geometry evidence. Without evidence, the box
   is kept.
4. **Survivors are restyled from resolved values plus lifted intent.** Each
   surviving block's styles come, in order of preference, from:
   1. core block attributes;
   2. `theme.json` presets: deduplicated colors, font sizes, spacing and layout
      widths;
   3. engine-owned class rules generated for that block.

   Author rules are consulted only to lift intent that a resolved value loses, such
   as percentages, `fr`, `clamp()`, custom properties, media-query variants, and
   `:hover`/`:focus` states. That intent is re-expressed on the surviving block.
   Author selectors, class names and stylesheets are not emitted.
5. **Proof is per box.** Every surviving box matches its captured geometry at every
   captured viewport, and every captured interaction state still resolves. Whole-site
   visual parity remains the acceptance gate.

## Considered options

- **Keep tuning selector-preserving coalescing.** Rejected. More than 30 PRs show it
  oscillates: each removed wrapper breaks a selector somewhere else.
- **Keep author CSS as a fallback for boxes the restyle cannot express.** Rejected.
  It reintroduces the structural selector dependency, and with it the same nesting,
  for every site that hits the fallback. Inexpressible intent becomes an
  engine-owned class rule scoped to one block.
- **Rewrite author selectors onto the collapsed tree.** Rejected. Selector rewriting
  across collapsed boxes is a CSS compiler of its own, it still ships framework
  weight, and it leaves editability tied to source class names.
- **Flatten from resolved styles only, without author intent.** Rejected. Pixel
  values frozen at the captured viewports lose fluid and responsive behavior between
  them.

## Consequences

- `LayoutGeometryProof` grows from per-wrapper reductions (capped at 256 nodes) into
  the box-tree contract. Its producer and transformer schema names unify, and
  consumers pass the evidence through to the artifact.
- `WrapperCoalescer`'s selector-survival eligibility, layout-shell wrapper folding,
  and per-wrapper preservation heuristics retire once the classifier owns those
  decisions.
- Theme CSS becomes proportional to the design, not to the source framework.
  `theme.json` presets carry the shared tokens, and List View depth tracks the
  visible design.
- Imports without box-tree evidence (static, browser-free inputs) keep today's path
  until a producer supplies evidence. The decision applies wherever the evidence
  exists, not per builder.
- The rollout runs in four slices, each measured on the tracked whole-site imports
  for depth, groups per leaf, theme CSS per route and visual parity:
  1. evidence contract;
  2. classifier report;
  3. collapse;
  4. restyle and retirement of shipped author CSS.
