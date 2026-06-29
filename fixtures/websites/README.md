Block Transform Fixtures
========================

This directory contains reusable source fixtures for block transform testing.

The fixtures are intentionally stored as source inputs rather than only as generated
WordPress artifacts. Transform harnesses can use them to stress-test and compare
different source-to-block methods across a broad range of complexity, content
models, visual density, and interaction patterns.

Layout
------

One flat directory per fixture: `fixtures/websites/<id>/`. The directory basename
is the fixture ID. There is no nesting — class is metadata (in `fixture.json`), not
directory structure.

Each fixture directory contains:

- An `index.html` entry point plus any supporting assets/content files (the source
  tree under test).
- A `fixture.json` manifest describing the fixture's class, tags, and complexity.

`20-switchback-woocommerce-extra-hard.zip` is a deliberate companion archive of the
matching source tree, kept to exercise archive-based transform/intake paths. Leave
it in place.

fixture.json — the single source of truth
-----------------------------------------

Each `fixture.json` is the sole source of truth for a fixture's class, tags, and
complexity. Consumers (the Blocks Engine corpus diagnostics runner and the Static
Site Importer fixture matrix) read it directly; there is no separate manifest or
index to keep in sync, so class metadata cannot drift.

```json
{
  "class": "marketing/static",
  "tags": ["restaurant", "has-form"],
  "complexity": 1
}
```

- `class` (required): the fixture class lane, one of the canonical values verbatim:
  `marketing/static`, `docs/blog`, `ecommerce/catalog`, `app/dashboard`,
  `canvas/webgl/audio/runtime-heavy`. An unrecognized or missing value resolves to
  `unknown` with a loud warning in consuming tools.
- `tags` (optional): free-form lowercase string array for lane/tag querying.
- `complexity` (optional): integer 1–5.

Consuming
---------

Discover fixtures by listing the immediate subdirectories of `fixtures/websites/`
and deriving each fixture ID from the directory basename. Read `class`, `tags`, and
`complexity` from each directory's `fixture.json`.

Treat these as read-only fixtures. If a test needs derived artifacts, transformed
block output, screenshots, or reports, write them to a temporary output directory
rather than back into this tree.

Curated Exclusions
------------------

Excluded local/scratch entries from the initial import:

- `.DS_Store`
- `.claude/`
- `baseplate/`
- `mockup/`
- `saveweb2zip-com-liquidbonsai-com/`
