# Nested header navigation inventory

Navigation recognition must conserve every destination before a menu becomes a
shared entity. A landmark may contain a layout wrapper, an independent home link,
a nested list, a social icon, and a separately controlled phone panel. The layout
wrapper is not one menu item just because one anchor can be read outside its list.

## Owning contract

- A rejected child-menu candidate remains content. Its parent can defer to
  recursive native conversion instead of serializing only the primary anchor.
- A deeply wrapped landmark with destinations outside its nested list retains its
  layout. Recursion reaches the list and the independently controlled occurrence.
- Known icon-only social destinations use the social pattern's shared service
  inventory and the existing native navigation icon projection. Unnamed unknown
  destinations remain on the content-preserving path.
- Native buttons, anchor role-buttons, and retained div/span role-buttons can bind
  a menu. Nested item clusters are part of the maximal controlled occurrence;
  independent competing occurrences remain ambiguous.
- An explicitly bound projected navigation occupies its opener's slot and owns
  that opener's provenance. Its source panel's hidden state does not delete it
  during duplicate normalization.
- A responsive document branch owns its own visibility. A projected opener that
  remains visible in that branch uses Core's always-visible overlay control,
   including at widths above Core's mobile breakpoint. Hash-anchor bars keep their
   existing bar presentation; header-bound controls retain the header's containing
   box and source-panel paint/padding projection.
   Always-overlay occurrences explicitly keep their closed panel out of layout at
   every width; the generic desktop list repair must not expose it above 600px.
- URL-inferred current state preserves the invariant base-colour marker when
  shared extraction removes route state. Authored active-state presentation keeps
  its existing projection.

Entity sharing still compares canonical content and presentation. Different
fragment destinations remain distinct. Equivalent occurrences across routes
reference one native `wp_navigation` entity and can be edited once.

An ordinary heading link outside a nested list also owns content: its destination,
text, and heading level survive recursive conversion. Preserving that heading is
not evidence of independent branding. The heading-brand predicate remains bounded
to home destinations/`rel=home` or explicit brand signals, outside list ownership.
The semantic-heading-brand negative therefore checks the non-home RichText link,
absence of a brand carrier and menu claim, and the complete adjacent Work/Contact
inventory. Its previous absence-of-heading assertion described the old landmark
flattening, which converted `/topic` to a submenu label and claimed the adjacent
Work/Contact list as its children rather than preserving the sibling layout.
The list-owned heading negative still requires native navigation labels.

## Regression gates

From `php-transformer/`:

```sh
php tests/unit/navigation-nested-list-inventory.php
composer test
composer lint
PLAYWRIGHT_MODULE="$PWD/tools/visual-parity/node_modules/playwright/index.mjs" \
  bash tests/integration/stylesheet-activation-docker.sh
```

The neutral fixture includes a nested brand/list, an unnamed social SVG, an
offscreen in-flow peer, a role-button opener, a hidden panel, responsive fragment
targets, and two routes. The producer regression also verifies conservation of an
unknown icon destination. Set `BE_TRANSFORMER_ROOT` to a baseline transformer
checkout with installed dependencies to replay the failing inventory gate.

The required WordPress HTTP browser gate uses real Core rendering and
Interactivity at 390, 768, and 1440 pixels. It opens the menu by clicking the
rendered control, verifies the visible ordered item inventory and viewport bounds,
checks the closed panel before opening and after each destination click,
clicks each fragment destination, checks the visible scrolled section, and checks
overflow. Real Gutenberg parses and validates the menu blocks; one menu edit through
the Core entity datastore is saved, reloaded, observed on both frontend routes,
and restored exactly.

This gate accepts navigation inventory and behavior. Whole-site visual parity
remains a separate source-versus-imported runtime gate.

## Source opener presentation

Core navigation's dynamic renderer exposes built-in hamburger variants, not an
arbitrary source SVG. A sole passive shape SVG control therefore keeps its
original sanitized artwork in native block metadata; the generated theme's
`render_block_core/navigation` projection replaces only Core's opener artwork.
The button, accessibility attributes, keyboard and Interactivity events remain
Core-owned. The original canonical block save shape remains valid.

The existing toggle marker carries authored control/child-SVG correspondence:
intrinsic SVG dimensions, margins, transforms and query-scoped box/paint facts.
Transform is on the button leaf, preserving the overlay's containing block.
An observed 24px visual box can be a 20px intrinsic SVG scaled by 1.2; it is not
serialized as a guessed 24px width. Mixed text/icon, multiple icons and SVG that
requires document context do not justify replacing native button content.
Those negative cases retain the existing native menu path; exact presentation
for those cases remains a separate contract.

`NAVIGATION_OPENER_TEST=1` selects the neutral fixture with independently varying
tablet SVG dimensions, margin and transform. The required Docker browser gate
runs this fixture as well as the inventory fixture, verifying native keyboard
open/close, closed state above 600px, routes, bounds, Gutenberg validity and one
entity edit/restoration on two routes.

## Source-list ownership through type projection

A UL/OL type rule projected onto native navigation keeps the identity of its
actual source subjects. The existing semantic-marker facility supplies those
identities on list-owned hosts; it does not create hooks for unrelated control
roots or lists whose wrappers remain the owner. A global `ul` rule therefore
cannot acquire every native navigation as a new subject.

The existing list-host role additionally records whether the source list owns
the native root or has moved into an overlay. In the latter case its projected
selector addresses Core's inner UL, while the control owns the native root.
Query stacks, original type specificity, genuine float/width/offset placement,
item-row layout and the existing single-copy container reset remain intact.

`NAVIGATION_OWNERSHIP_TEST=1` exercises a DIV panel and a separately floated
genuine source list under base/tablet list-margin rules. Add
`NAVIGATION_LIST_PANEL_TEST=1` for a source UL panel. The required browser gate
checks actual source-control coordinates and zero differing crop pixels, the
genuine list's relative placement and conditioned margin, native keyboard and
destination behavior, and a header navigation entity edit/restoration on two
routes. `tests/unit/navigation-list-host-ownership.php` discriminates native
roots, synthetic inner lists and real source-list inner overlays.
