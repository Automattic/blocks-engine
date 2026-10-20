# Linked block containers (#3150)

Links containing flow elements are not RichText button labels, even when only one heading bears text. They use the existing generated `layout-shell` block: a real `<a>` wrapper around editable inner blocks. This is simpler than introducing another linked-container block, preserves the full clickable area and ancestor selectors, and keeps href, target and rel on the original link. Native image-only links/lightboxes keep their existing handling; phrasing-only styled links still become `core/button`.

Headings retain their source level/classes and responsive author typography. Bare div boundaries inside the link are retained where coalescing would change inline media line boxes. An empty, explicitly sized SVG also retains its box. Query-loop lowering replaces static card wrappers with divs when native per-post slots take ownership of the links.

## Verification

- `composer install --no-interaction && composer test:canonical`
- `node tools/visual-parity/tests/block-link-cards.mjs` (Chromium)

The neutral three-column fixture is `tests/fixtures/block-link-cards.php`. The browser test checks three h4s inside their original links, target/rel, 16px titles at 1440 and 14px at 390, and source/import card and grid heights within 1px.

Before changing runtime code, the new browser test failed on the base checkout (`0 !== 3`: no imported `.card` containers). It passes with this change at both widths. Both documents are measured in standards mode.

PR: Closes #3150.
