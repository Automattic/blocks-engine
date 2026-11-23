# Collapse wrappers on per-node geometry evidence while author CSS keeps shipping

**Status: Proposed.** Accepted once the slice 1 prototype passes the corpus gate below.

A wrapper is removed when the captured browser evidence shows it contributes nothing
at any captured viewport. Selector survival no longer decides it. Author stylesheets
keep shipping. A descendant rule whose match changes when a wrapper is removed transfers
to that descendant as an engine-owned rule. Inset-only wrappers merge into the nearest
surviving box. Every decision is per node and falls back locally.

## Context

Builder imports stay deep and wrapper-heavy. On trunk, measured from published blocks:

| Site | Single-child groups / groups | Max depth |
|---|---|---|
| Wix demo | 225 / 369 | 13 |
| Squarespace demo | 576 / 906 | 16 |

Two approaches have failed:

- **Selector-survival coalescing** (`WrapperCoalescer`) removes a wrapper only when every
  author selector still matches without it. Builder CSS is ancestor-scoped, so almost no
  wrapper qualifies: 4 of 352 Wix wrappers and 57 of 833 Squarespace wrappers. #7420 swapped
  this for a resolved-style proof read from CSS alone. After four rounds it moved the
  Squarespace demo from 576 to 571 single-child groups and the Wix demo not at all. It
  refused wrappers on `position`, `width` and `float`, because CSS alone cannot tell whether
  a wrapper using them changes any box.
- **ADR 0005** (box-tree reduction with engine-owned styles only) collapsed every corpus
  site to zero passing cells (#7370). Dropping author stylesheets caused that collapse on
  its own. It also pinned per-viewport samples page-wide, so box-tree evidence acted as a
  page switch (#7345). #7375 removed box-tree consumption.

The capture still records each element's border box and 117 used styles at 1440, 768 and
402 px (`box-tree/v2`), keyed by `data-blocks-engine-node-id`. Classifying every visible
single-child source wrapper from that evidence, as described in the pull request that introduced this ADR:

| Site | Visible single-child wrappers | Collapsible | Max source depth if removed |
|---|---|---|---|
| Wix demo | 445 | 235 (53%) | 27–28 → 22–23 |
| Squarespace demo | 1071 | 210 neutral or constant inset; 106 more varying inset | 17–21 → 13–15 |

On these sites, `position: relative` and `width` are mostly inert: 121 Wix wrappers are
`relative`, contain no absolutely positioned descendant, and keep their child's box at every
viewport. The CSS-only proof cannot see this; the evidence can.

## Decision

1. **Classify each wrapper by its own contribution, at every captured viewport.** A
   single-child wrapper is **neutral** when, at every captured viewport:
   - it is visible;
   - it paints nothing: no background, border, shadow, outline, opacity, transform,
     filter, mask, clip-path or clipping overflow;
   - it does not position, float or stack: `position` is `static`, or `relative` with no
     offsets and no absolutely or fixed positioned descendant using it as containing block;
     `float: none`; `z-index: auto`;
   - it does not animate;
   - its child's border box equals its own.

   A wrapper that meets every condition except the last, whose child's box is inset by the
   same amounts at every viewport, is **inset-only**. Any other wrapper is load-bearing and
   stays.
2. **Author CSS keeps shipping.** Removing a wrapper must not change what any surviving
   node resolves to. A rule whose match on a descendant changes is restated on that
   descendant as an engine-owned rule scoped to it, using the identity-transfer contract
   from #7379/#7420: id, consumed data attributes, repeat consistency, and shell and landmark
   boundaries. A wrapper whose effect cannot be restated stays.
3. **Neutral chains collapse with no new styles.** Removing a neutral wrapper needs no pin,
   because the evidence shows the box did nothing. This is slice 1.
4. **Inset-only wrappers merge into the nearest surviving box** as padding, or as a
   constrained width with auto margins, using the author's declared value when it
   reproduces the inset at every viewport (`%`, `rem`, `clamp()`). A constant pixel inset
   uses that value. A varying inset with no declared value that reproduces it stays. This
   is slice 2.
5. **Monotonic and local (#7345).** Evidence affects only these decisions, per node. A node
   that fails any check, or has no evidence, keeps today's behaviour. Imports without
   box-tree evidence produce today's output byte for byte.

## Considered options

- **Keep extending the CSS-only proof (#7420).** Rejected: it reached 1% on Squarespace
  and 0% on Wix, and no amount of extra guards turns ancestor-scoped selectors into
  geometry evidence.
- **Full restyle from resolved values (ADR 0005).** Rejected, as measured: dropping
  author CSS zeroed every corpus site.
- **Regenerate flex or grid layout from boxes for every subtree.** Deferred. Multi-child
  re-synthesis is the next step if slices 1 and 2 leave the depth blockers standing; it
  needs its own evidence of fidelity between captured widths.

## Consequences

- php-transformer reads `box-tree/` evidence again, for wrapper classification only,
  through one reader that answers "neutral, inset-only or load-bearing" per node.
  Capture identity attributes stay out of compiled output.
- `WrapperCoalescer`'s selector-survival eligibility becomes the fallback for nodes with
  no evidence.
- **Corpus gate per slice.** On the tracked corpus the candidate passes at least as many
  cells per site as trunk, published single-child groups fall, max depth does not rise,
  and visual and editor parity findings are unchanged.
- Slice 1 targets: Squarespace demo single-child groups at or below 556, Wix demo below
  225.
