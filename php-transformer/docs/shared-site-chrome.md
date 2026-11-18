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

A shared header and footer nested in a site wrapper beside `main` (Squarespace's
`#siteWrapper`) move with that wrapper to the templates. Chrome that precedes
the header in the wrapper and carries a runtime binding (a floating cart link)
would otherwise sit in the template frame, where no binding can anchor, and
keep both references in page content. When every route repeats that leading run
(compared without route-local box-tree node ids), it joins the header part and
its bindings re-anchor there, mirroring how trailing frame chrome folds into the
footer part. Route coverage counts only template references. The compiler
contract is `tests/unit/shell-wrapper-shared-header-template.php`.

Routes whose header or footer agrees on the box-tree viewports they share stay
one part even when one route's sidecar samples an extra viewport (`mobile`):
intake aligns that chrome subtree to the common viewports, while the route's
page content keeps its full evidence (`docs/contracts/box-tree.md`).

When the source has no area landmark, extraction adds a semantic header/footer
Group marked `blocks-engine-shell-landmark`. The same frontend-only transport
stylesheet makes this synthetic landmark boxless too: source children remain
items of their original flex/grid parent, and percentage widths resolve against
that parent. Authored landmarks keep their boxes and styling; the editor keeps
the synthetic Group's selection box. This avoids copying only selected sizing
properties and preserves landmark markup without adding layout geometry.

A `<header>` nested in a body-level view that comes after a body-level `<nav>`
sibling (an SPA page view, a panel) is view content, not the site header
candidate. Only a sibling whose own element is the nav landmark counts, so
capture-variant documents and site wrappers never qualify, and a header that
opens its own wrapper ahead of `main` stays the site header. The leading
navigation cluster, including a text-only announcement bar directly above it at
document level, becomes the header part instead
(`tests/unit/shell-hidden-view-header.php`, #6549).

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
