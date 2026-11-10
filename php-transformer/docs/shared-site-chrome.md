# Shared site chrome

Refs #3689 (shared-structure slice).

Header/footer extraction runs before template selection. A footer beneath an
application root is still a document landmark: reducing a one-paragraph footer
to its paragraph loses the evidence needed to extract it. Sectioning containers
continue to delimit content-local footers.

Generated shell references mark Core's transport element with
`blocks-engine-shell-transport`. Frontend support CSS gives that transport
`display: contents`, with zero added specificity. The source header/footer
inside the part remains unchanged, including its tag and sticky/fixed rules.
The editor retains Core's selection element. Generated PHP no longer strips
outer HTML using a slug allowlist.

A headingless, footer-only page can also move its wrapper chain to the
template when every captured route proves the same frame. This requires a
sole-child Group chain ending in content siblings followed by the footer
reference; differing chains, ambiguous references and runtime-bound frames keep
in-place transport. The existing frame-content marker removes Core's extra
post-content element at render time, so the sections remain direct flex/grid
items rather than acquiring another layout box. This addresses #5213 without
reviving slug-based template-part unwrapping. The public compiler contract is
`route-invariant-footer-frame.php`.

When the source has no area landmark, extraction adds a semantic header/footer
Group marked `blocks-engine-shell-landmark`. The same frontend-only transport
stylesheet makes this synthetic landmark boxless too: source children remain
items of their original flex/grid parent, and percentage widths resolve against
that parent. Authored landmarks keep their boxes and styling; the editor keeps
the synthetic Group's selection box. This avoids copying only selected sizing
properties and preserves landmark markup without adding layout geometry.

The seven-route public compiler regression covers identical chrome beneath an
app root, shared stylesheet scope, lifecycle templates, route-template collapse,
and sticky positioning after a real browser scroll. The shared-shell tests
previously required slug-based unwrapping; they now require explicit transport
styling and the absence of the slug allowlist instead. Their landmark and
reference-cardinality assertions remain intact.

No topology baseline counts or labels are changed. The corpus comparison has
one existing mismatch in the malformed-markup fixture (score 4 to 1), reproduced
with the original HTML compilation code; this slice does not introduce it.

Remaining #3689 slices include full site-wide stylesheet consolidation, source
cascade preservation across all conditions, Site Editor route isolation,
responsive-media geometry coverage, and dead-rule/class pruning and payload
reduction. Shared structure does not complete the parent architecture issue.
