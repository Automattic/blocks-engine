# PR Review Guide

This guide frames the draft `php-transformer` PR as a standalone Blocks Engine product primitive. It is intended to help reviewers evaluate the package boundary, public contract, and draft readiness without treating the old plugin migrations as the product itself.

## What PHP Transformer Is

`php-transformer` is the canonical PHP library layer for turning source content and generated website artifacts into WordPress-native block outputs.

It owns reusable transformation primitives that can be shared by importers, format bridges, generated-site compilers, Studio workflows, WordPress.com workflows, and future block-generation tools.

The package should be judged as a reusable primitive with stable contracts, not as a one-off extraction from any single predecessor repository.

## What PHP Transformer Is Not

`php-transformer` is not a WordPress product plugin.

It does not own importer admin screens, uploaded ZIP intake, theme activation, page creation workflows, Studio orchestration, WordPress.com deployment behavior, hosting concerns, or self-improving generation loops.

Those product workflows remain in downstream consumers. The transformer should expose the conversion contract they need while avoiding product-specific policy.

## Canonical API

Reviewers should treat these as the current public entrypoints:

- `Contract\TransformerResult` - stable result envelope for successful, warning, and failed transformations.
- `HtmlToBlocks\HtmlTransformer` - converts supported HTML into parsed block arrays and serialized block markup.
- `FormatBridge\FormatBridge` - normalizes and converts declared `html`, `markdown`, and serialized `blocks` content.
- `ArtifactCompiler\ArtifactCompiler` - normalizes generated website artifact bundles into the shared result envelope.
- `WordPress\Runtime` - adapter boundary for WordPress functions used when running inside or outside WordPress.

The canonical cross-process contract is `TransformerResult::toArray()`. Callers should pass result envelopes across fixtures, HTTP boundaries, process boundaries, and compatibility wrappers instead of relying on product-specific arrays.

Bundled helpers outside those entrypoints can change while the package is still draft unless the README explicitly marks them public or they are part of an injected adapter contract.

## Current Draft Limits

This PR is a draft consolidation target, not a final parity claim for every previous repository behavior.

Current limits reviewers should expect:

- HTML support is intentionally bounded to the covered core block shapes and fixture-backed cases.
- Unsupported input paths should surface diagnostics, fallbacks, or failed envelopes instead of silent best-effort behavior.
- Markdown and blocks conversion paths are present as shared primitives, but deeper product policy belongs to consumers.
- Generated artifact compilation is focused on normalization, source reporting, assets, components, documents, block type artifacts, diagnostics, and provenance.
- WordPress runtime calls are behind an adapter boundary so package behavior can be tested with and without WordPress loaded.
- Legacy parity fixtures and compatibility examples are acceptance scaffolding for migration, not permanent product architecture.

## Review Focus Areas

Useful review should focus on the standalone primitive:

- Does the package boundary keep reusable transformation logic inside `php-transformer` and product workflow policy outside it?
- Are the public entrypoints clear enough for downstream consumers to adopt without reaching into implementation classes?
- Is the result envelope stable, serializable, and expressive enough for diagnostics, fallbacks, provenance, assets, documents, and block output?
- Do unsupported and partial-success paths produce reviewable diagnostics instead of hidden behavior?
- Are contract fixtures and parity fixtures aligned with the package API rather than with one old plugin's private shape?
- Are compatibility wrappers thin enough to prove adoption paths without making old repositories the long-term architecture?
- Are draft limitations documented honestly so follow-up work can be scoped without blocking review of the primitive itself?

Review should avoid asking this package to absorb downstream product responsibilities. Static Site Importer, Studio, WordPress.com, and generation loops can consume this primitive while keeping their own UX, orchestration, persistence, and deployment decisions.

## Why Migration Details Are Transitional

The old repositories are downstream entrypoints and early consumers. Their wrapper plans, legacy mappings, and parity comparisons exist to make adoption safe and reviewable.

They are transitional because the long-term product surface is the Blocks Engine package contract:

- Existing public integrations may need compatibility wrappers while callers migrate deliberately.
- Legacy result mappings help reviewers compare behavior, but new consumers should target the shared result envelope.
- Consumer PR notes document rollout order, rollback plans, and acceptance commands; they are not part of the transformer API.
- Once consumers depend on the package directly, migration scaffolding can shrink or move out of the core review path.

They are not transitional because they are dependencies of `php-transformer` or because their names define package identity. The transformer must remain installable and understandable without old repository code, and old repositories should delegate downstream to tagged transformer APIs when they need to preserve public entrypoints.

Reviewers should use [`current-repo-map.md`](current-repo-map.md) for the consolidation policy: popular old repositories stay open while they provide discoverability, issue routing, compatibility releases, or migration guidance; archiving is safe only after active callers, package metadata, issue trackers, and replacement instructions have moved to the new contract.

The draft PR is ready for human review when reviewers can evaluate `php-transformer` as the reusable transformation primitive, with migration material serving only as evidence that predecessor behavior has a path into the new contract.
