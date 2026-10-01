# Verified editable collection filters

The collection filter companion consumes Data Liberation Agent's bounded
`typed-search` evidence. It is a local collection predicate, with independently
observed category membership, rather than a site-wide WordPress search.

## Projection and authoring

- The captured interaction report and route receipt must use their canonical v1
  schemas. A filter must have captured status, verified replay and restoration,
  the `normalized-text-includes` predicate, and blocked data-request evidence.
- The projector matches the portable field, target, category, item and empty-state
  annotations in the already localized HTML. It checks canonical item keys and
  memberships against evidence before promoting their shared authoring scope.
  The association uses item keys, independent of the evidence array's order.
- Matching selectable-set snapshots are superseded by the canonical collection;
  category activation never inserts additional copies of item content.
- Search controls and category labels use a generated companion block. Collection
  content and the source no-match copy remain ordinary editable inner blocks.
  Native accordion collections keep one `core/accordion` with native answer
paragraphs. Ordinary native group/card collections use the same filter runtime.
- Each item carries an opaque saved `className` marker, with category memberships
  keyed by that marker in the collection's configuration. Category association
  follows the native item through owner reordering and save/reopen, including
  items with duplicate headings and different answers.
- Source disclosure controls, including observed SVG state annotations, pass
  unchanged into the normal accordion converter.

## Runtime

The companion declares its script module through the WordPress Interactivity API.
Input and category events are scoped to the owning collection. The runtime reads
the current native item's complete text tree, including closed answers, normalizes
whitespace, and composes case-insensitive inclusion with captured category
membership. Owner-edited answers therefore become searchable after save/reopen
without a separately maintained search index.

Owner-added items without captured membership remain searchable in the initially
verified full-collection category. Their addition or removal does not disable the
filter or change other items' category associations. The editor explains this
default. Deleting a captured item also leaves the remaining filter usable.

Category paint comes from separately observed active/inactive attributes; labels
remain owner-editable. The observed empty-state content stays in its original
inside/after placement and is shown only when no item matches.

## Bounds and unsupported mappings

Projection is bounded to 128 report pages, 100 items, 32 categories, and 512 KiB
of evidence per filter. Unknown predicates, unverified restoration, mismatched
portable annotations, and incomplete evidence are not promoted.

Native mapping currently supports direct group/card items and the core accordion
family. A conversion that changes canonical item cardinality records
`html_collection_item_mapping_unproven` rather than silently claiming a proven
working filter. After materialization, runtime membership lookup uses saved item
identity and supports changed authoring cardinality.

## Verification

`php tests/unit/collection-filter-block.php` verifies canonical native authoring,
duplicate headings with distinct answers, ordinary card collections, declaration
transport, and rejection of unsupported evidence. The companion browser fixture
runs actual DOM filtering for answer-only terms, uppercase queries, category/query
composition, external no-match content, and edited answers:

```sh
php tests/unit/collection-filter-block.php
node tests/unit/collection-filter-browser.mjs
```

The browser fixture resolves `playwright`, or accepts `PLAYWRIGHT_MODULE` for an
installed browser harness. Fresh-import acceptance additionally requires real
editor paragraph/image save-and-reopen and source/capture/WordPress behavior
comparisons at 390, 768 and 1440 pixels.
