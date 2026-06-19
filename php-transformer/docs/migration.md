# Migration Plan

## Phase 1: Draft Consolidation

- Establish the Composer package and namespace boundaries.
- Map existing repositories to target namespaces.
- Add contract fixtures and result-envelope documentation.
- Import the lowest-level HTML-to-blocks behavior with minimal changes.

## Phase 2: Compatibility Wrappers

- Update existing packages to consume `php-transformer` through Composer or path repositories.
- Keep existing function names available in wrappers where external callers rely on them.
- Move tests toward shared contract fixtures.

## Phase 3: Product Adoption

- Point Static Site Importer at `php-transformer` for transformation work.
- Keep Static Site Importer focused on WordPress product workflows: intake, page creation, theme generation, activation, and CLI/admin UX.
- Point wp-site-generator improvement loops at `php-transformer` contract fixtures.

## Phase 4: Deprecation Decisions

- Decide which compatibility repositories remain useful as public packages.
- Archive only after known consumers have a stable replacement path.
