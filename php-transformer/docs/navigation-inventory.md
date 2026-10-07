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
- URL-inferred current state preserves the invariant base-colour marker when
  shared extraction removes route state. Authored active-state presentation keeps
  its existing projection.

Entity sharing still compares canonical content and presentation. Different
fragment destinations remain distinct. Equivalent occurrences across routes
reference one native `wp_navigation` entity and can be edited once.

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
clicks each fragment destination, checks the visible scrolled section, and checks
overflow. Real Gutenberg parses and validates the menu blocks; one menu edit through
the Core entity datastore is saved, reloaded, observed on both frontend routes,
and restored exactly.

This gate accepts navigation inventory and behavior. Whole-site visual parity
remains a separate source-versus-imported runtime gate.
