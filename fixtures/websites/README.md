Block Transform Fixtures
========================

This directory contains reusable source fixtures for block transform testing.

The fixtures are intentionally stored as source inputs rather than only as generated
WordPress artifacts. Transform harnesses can use them to stress-test and compare
different source-to-block methods across a broad range of complexity, content
models, visual density, and interaction patterns.

Contents
--------

- Numbered fixture directories: source trees with an `index.html` entry point and
  any supporting assets/content files.
- `20-switchback-woocommerce-extra-hard.zip`: ZIP form of the matching source tree,
  kept to exercise archive-based transform paths.
- `../website-product-class-manifest.json`: machine-readable migration plan for
  moving fixtures into Homeboy Rigs product class directories.

Consumers should treat these as read-only fixtures. If a test needs derived
artifacts, transformed block output, screenshots, or reports, write them to a
temporary output directory rather than back into this tree.

Product Class Migration
-----------------------

Website fixtures are currently flat under `fixtures/websites/`. The migration
manifest records the intended nested target path for each fixture without moving
the source trees yet. Consumers that discover fixtures should recurse under
`fixtures/websites/` and derive fixture IDs from the fixture directory basename,
not from a fixed one-level path assumption.

Before and after any fixture move batch, validate the manifest from the repository
root:

```sh
php fixtures/validate-website-product-class-manifest.php
```

The validator accepts either the current flat path or the intended nested path for
each fixture entrypoint, so it can be used incrementally during the directory
migration.

Curated Exclusions
------------------

Excluded local/scratch entries from the initial import:

- `.DS_Store`
- `.claude/`
- `baseplate/`
- `mockup/`
- `saveweb2zip-com-liquidbonsai-com/`
