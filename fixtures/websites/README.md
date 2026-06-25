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

Consumers should treat these as read-only fixtures. If a test needs derived
artifacts, transformed block output, screenshots, or reports, write them to a
temporary output directory rather than back into this tree.

Curated Exclusions
------------------

Excluded local/scratch entries from the initial import:

- `.DS_Store`
- `.claude/`
- `baseplate/`
- `mockup/`
- `saveweb2zip-com-liquidbonsai-com/`
