# Data-scoped control surface (issue #3278)

## Reduction

Started with the captured header and its linked stylesheet, in a standalone
HTML document. Every probe compiled `index.html` with `ArtifactCompiler`, not
an import or browser. The failure signature was a `core/button` link without
an inline background, with the author's background rule matching its generated
wrapper instead of the link.

Markup reduction removed the mobile layout, navigation, language switcher,
logo, header/layout ancestors, and finally the anchor's unrelated attributes.
One anchor still reproduced with the full stylesheet. In particular,
`disabled="false"`, `aria-hidden`, target/rel and sibling controls were not
necessary.

Chunk deletion of the stylesheet (1,898 top-level chunks, keeping each at-rule
whole) reduced it to the scoped primary-control rule and one whole responsive
at-rule. Deleting that responsive at-rule lets static style extraction put an
inline background on the saved link, masking the selector-routing bug. This
also explains the supplied working minimal button: its emitted link has an
inline author background, even though the data-scoped rule targets wrappers.

The neutral HTML fixture retains that responsive paint dependency. After the
whole-chunk reduction, unrelated declarations and selectors were removed from
the surviving rules and the names/content were neutralized. The essential
shape is a button-like anchor with a static data predicate, box padding and
background, plus a media-conditioned background declaration. The color/radius
checks additionally protect the other painted properties affected by the same
routing decision.

## Ownership and evidence

`projectSourceAttributeSelector()` returned an attribute-identity marker before
the normal control-selector branch could run. Both generated wrappers carry
that marker. The fix uses the existing control-selector projection and its
existing link-owned/layout-wrapper policy instead; it does not change button
recognition or introduce source-specific rules.

Full artifact/site-plan compilation of the neutral fixture on origin/trunk:

- Background rules matching the link: **0**; matching wrapper ancestors: **4**.
- Radius/color rules matching the link: **0 / 0**.
- Link inline background: **absent**, so Core's default paint remains exposed.

After the fix (both standalone and direct flex-item variants):

- Background rules matching the link: **2**; matching wrapper ancestors: **0**.
- Radius/color rules matching the link: **2 / 2**.
- Link inline background: still **absent**; the author stylesheet owns paint.

These are selector-match measurements, not browser geometry. The contract
checks frontend assets and canonical page/template-part markup from the full
compiler path. Recompiling the original captured header and full stylesheet
also changes the wrapper-only background signature from present to absent.

## Moved, not duplicated

A real-site import still rendered the control as a padded, painted link inside
a padded, painted `.wp-block-button` box. The data-scoped selector also matched
source elements that do not become controls; the fixture keeps one as the
inline `details` link. `projectSourceAttributeSelector()` projected those
through the selector's attribute marker, and that marker is also written on
the control's generated `core/buttons` and `core/button` wrappers. The bare
`:where(.marker)` arm therefore repainted both wrappers with the anchor's
padding, background, border and radius, duplicating the control arm.

When any subject of the selector is control-owned (the same control-marker
decision that routes its surface to the link), the attribute arm now targets
`:where(.marker:not(.wp-block-buttons):not(.wp-block-button))`. The wrappers
keep only the layout/flex-item declarations from the existing geometry split.
The contract asserts that no frontend rule gives either wrapper a non-neutral
background, padding, border or radius declaration (and no inline one), while
the link and the plain inline link each receive all of them.

Surface declarations matching the real header control after full compilation
(hover/focus and editor arms excluded):

| Box | Before | After |
|---|---|---|
| `a.wp-block-button__link` | padding, background-color, border, border-radius | padding, background-color, border, border-radius |
| `.wp-block-button` | padding, background-color, border, border-radius | none (only a generic `padding:0`) |
| `.wp-block-buttons` | padding, background-color, border, border-radius | none (only a generic `padding:0`) |
