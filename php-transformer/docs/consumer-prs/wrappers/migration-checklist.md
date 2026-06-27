# Wrapper Migration Checklist

Use this checklist for each compatibility repository before changing its default implementation.

## Contract

- Public functions/classes still exist with the same names.
- Existing argument names and required keys are accepted.
- Existing return shapes are preserved or deliberately versioned.
- Old hooks fire from the wrapper when consumers may depend on them.
- WordPress-only calls remain guarded or isolated behind runtime adapters.

## Transformer Adoption

- Canonical conversion logic lives in `php-transformer`.
- The compatibility package only translates arguments, options, hooks, and result envelopes.
- Shared fixtures cover the old package's most important smoke cases.
- Diagnostics, fallbacks, coverage, assets, and provenance are mapped into `TransformerResult`.

## Static Site Importer

- Static Site Importer calls one local adapter instead of scattered conversion-package functions.
- Import report fields remain stable for CLI, abilities, and quality gates.
- Fixture comparison confirms no new unsupported HTML fallbacks, invalid blocks, or content-loss aborts.
- Theme/page creation and activation paths stay outside `php-transformer`.

## Release Order

1. Ship transformer implementation and shared fixtures.
2. Ship the compatibility wrapper in `html-to-blocks-converter`.
3. Switch Static Site Importer to its local transformer adapter.
4. Deprecate old internals only after known consumers have migrated.
