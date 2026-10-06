# Captured image galleries

The existing captured-interactions report and capture receipt carry bounded
gallery evidence. The portable authoring tree is the input: its viewport-scoped
gallery root contains one materialized inline cycle and, when observed, one
image-opened dialog panel. Capture helpers are retired through the existing
native-runtime replacement evidence; the replacement uses the authored-carousel
and captured-dialog blocks with editable core/image children.

## Evidence

Each `kind: gallery` state has `gallery.inline`, optional `gallery.lightbox`,
`gallery.closed`, and an observed `gallery.selection` array. Cycles declare
`coverage`, `restoration`, `initial`, `viewport`, and ordered `frames` with image
identities. The producer verifies every successor edge, its inverse, and the
wrap before declaring a complete cycle. A source's lazy image slots can have
different inline and expanded order; selection maps each inline frame to its
observed expanded frame by image identity, rather than assuming equal indices.

The consumer admits 2–24 frames and a complete, identity-checked selection
bijection. The root's `data-dla-gallery-source` and
`data-dla-gallery-capture-width` bind evidence to the emitted responsive document.
A collapsed responsive document can retain one captured viewport. Each emitted
native carousel keeps its own initial state. Complete inline-only evidence still
produces native navigation; an unobserved lightbox remains unproven.

Autoplay timing is not inferred from the capture's current index. The captured
cycle's unmeasured timing produces a held native carousel (`autoplayInterval: 0`).

## Native selection

The dialog's `gallerySelection` block attribute binds a surviving trigger ID to
its image-selection indices. Opening is restricted to an actual image click in
the bound inline carousel. The existing native carousel accepts the scoped
`blocks-engine-carousel-select` event with an in-range integer `detail.index`.
Its own Interactivity API state updates the visible image and counter; native
previous/next controls, close behavior, and editable children remain the same
contracts used by independently authored carousels and dialogs.

## Verification

`php tests/unit/captured-gallery-contract.php` compiles a rotated expanded cycle,
checks trigger identity and native-image roundtrip, and rejects an identity-wrong
selection that has the correct array shape.

For the actual producer-to-WordPress gate, provide a genuine portable DLA capture
at `tests/fixtures/captured-gallery/`, including `website/`, the matching capture
receipt, and interaction report. Then run:

```sh
BE_EDITOR_EVIDENCE_DIR=/tmp/captured-gallery-evidence composer test:captured-gallery-browser
```

The existing disposable Docker runner owns WordPress and cleanup. The gate uses
the complete portable artifact compiler and site-plan resolver, materializes its
asset writes, registers the generated blocks, and verifies offline decoded
20-image cycles, selected-image opening, both directions, close at
390/768/1440 px, and Gutenberg edit/save/reload validation. Source visual parity
and destination import acceptance are separate gates.
