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
