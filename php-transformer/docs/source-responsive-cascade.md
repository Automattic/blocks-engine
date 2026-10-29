# Source-owned responsive geometry

Refs #3689 (second slice; the issue remains open).

Author stylesheet projection owns translated declarations. Button layout and
native-control declarations retain their original priority; wrapper fill rules
are normal-priority structural adaptations. Direct-navigation display is
projected alongside its source rule, not replayed after author CSS from separate
static and conditional inventories. At-rule nesting and source order therefore
remain intact, including layers, supports and container queries.

Image free axes and constraints project onto the painted image at the source
rule's position. They are not copied from the reference viewport into important
box carriers. Any conditional image-axis declaration keeps that axis out of
native pixel attributes, even when desktop and mobile samples happen to agree.
Inline image constraints retain source importance; structural fallbacks use
normal priority.

CSS-sized provider frames use the existing editable visual-iframe companion.
This preserves their source selector surface and exact aspect-ratio/padding
wrapper behavior without an oEmbed round-trip replacing their box. Captured
width/height attributes are omitted on these blocks. Unsized and attribute-only
provider embeds retain core/embed behavior. Generated markup and assets must be
consumed together; no public result schema changes.

`tests/contract/source-responsive-cascade.php` exercises the public HTML
transformer with reduced utility/navigation, YouTube aspect and padding wrappers,
and fractional grid shapes. A required browser compares source and emitted
computed-style fingerprints at 390, 768, 1440 and source breakpoint edges. It also
checks active container queries, source ordering, important paint and absence of
invented important geometry. Superseded fixed-wrapper CSS sizing tests were
replaced, not retained as a second path.

Remaining slices: complete site-wide stylesheet ownership across other class
families, route-state/template-part variants, frontend/Site Editor template
parity, importer-only selector scoping, and live-rule/dead-class pruning. This
slice does not redesign all structural engine/editor repairs.
