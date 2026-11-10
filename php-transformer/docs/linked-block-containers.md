# Linked block containers (#3150)

Links containing flow elements normally remain linked containers. The exception is a control whose anchor owns a painted button surface (the existing resolved-style button signal: padding with background/border/rounding) and whose content is a single direct text-bearing label with no nested block content. That label may be a heading; empty decorative inline siblings do not make it a card. Class names, heading tags, or paint on a descendant alone do not establish this exception. A block wrapper around a heading, a second content block, media, or an icon wrapper keeps the linked-container path even when the anchor is painted. The classifier reuses its significant-child, visible-text, flow-content, and button-surface facts rather than deciding from a heading tag alone. They use the existing generated `layout-shell` block: a real `<a>` wrapper around editable inner blocks. This is simpler than introducing another linked-container block, preserves the full clickable area and ancestor selectors, and keeps href, target and rel on the original link. Native image-only links/lightboxes keep their existing handling; phrasing-only styled links still become `core/button`. CSS `display:block` on a span does not change that classification. Generic div wrappers around phrasing-only logo/label content retain their existing lowering; headings, paragraphs and semantic flow content nested inside divs still select the linked-container path.

Headings retain their source level/classes and responsive author typography. Bare div boundaries inside the link are retained where coalescing would change inline media line boxes. An empty, explicitly sized SVG also retains its box. Query-loop lowering replaces static card wrappers with divs when native per-post slots take ownership of the links.

## Heading-labelled buttons

The same flattening applied to native `<button>` controls: a disclosure toggle shaped `<button><div><h3>Question?</h3></div><div><svg/></div></button>` read as one phrasing label, and core/button erased the heading to plain button text — the level, authored typography and color were lost and the destination theme's `wp-element-button` paint rendered white label text over the source's transparent background. A `<button>` with visible text and a block-level flow descendant (a heading or paragraph; generic div label wrappers do not count) now keeps one real `<button>` as a layout-shell wrapper, with its `type`/ARIA attributes and authored classes intact, around the editable inner blocks its children convert to. Bare div boundaries inside the control are retained exactly as for anchors. Plain-text, div-wrapped-label, and icon-only buttons still lower to `core/button`; buttons with branching flow content (a second text block) keep their existing group lowering. Regression: `tests/unit/button-heading-label.php`.

## Verification

- `composer install --no-interaction && composer test:scripts && composer test:parity`
- `composer topology-diff -- --baseline tools/topology-diff/baseline.json`
- `node tools/visual-parity/tests/block-link-cards.mjs` (Chromium)

The neutral three-column fixture is `tests/fixtures/block-link-cards.php`. The browser test checks three h4s inside their original links, target/rel, 16px titles at 1440 and 14px at 390, and source/import card and grid heights within 1px.

Before changing runtime code, the new browser test failed on the base checkout (`0 !== 3`: no imported `.card` containers). It passes with this change at both widths. Both documents are measured in standards mode.

PR: Closes #3150.
