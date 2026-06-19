# PHP Transformer Parity Fixtures

The parity harness uses JSON fixtures to lock the shared contract for PHP transformer outputs while implementation slices continue to evolve independently.

php-transformer is a product-level primitive and downstream repositories are consumers. Migration comparison fixtures are downstream evidence; they do not make downstream repositories part of the canonical package identity and they do not require `php-transformer` to carry permanent compatibility code.

Fixture files live in `php-transformer/tests/fixtures/parity/*.json` and use schema `blocks-engine/php-transformer/parity-fixture/v1`. The JSON Schema is documented in `docs/contracts/php-transformer-parity-fixture.schema.json`.

Each fixture declares:

- `operation`: the public surface exercised by the runner, such as `format_bridge.convert`.
- `input`: operation-specific input data.
- `expect`: path-based assertions over the PHP output.
- `source_reference`: optional migration note for the source fixture that inspired the case.
- `legacy_comparison`: optional local-only migration evidence metadata.

Expectation paths are dot-separated. Use `\\.` for literal dots inside output keys, for example `legacy_mapping.consumer/result/v1.consumer_artifacts\\.block_markup`.

`legacy_comparison.skip` records cases where local migration evidence needs runtime context before a direct comparison is meaningful. The PHP runner still validates the current transformer output for those fixtures; it only skips optional migration comparison with the recorded reason.

Migration comparison is dev/local-only and opt-in. It is evidence for downstream migrations, not a package feature or long-term support promise. The harness never loads downstream repositories unless either `BLOCKS_ENGINE_PARITY_LEGACY=1` is set or the fixture declares `legacy_comparison.enabled: true`. These comparisons are temporary consumer checks for migration branches and should be removed or archived once consumers no longer need them.

When enabled, the runner resolves the migration repo path from fixture metadata first, then from operation-specific environment variables. Downstream repository names, bootstrap files, and public callables are migration evidence; keep those details in `php-transformer/docs/consumer-prs/` rather than in canonical fixture docs.

Fixtures may override `repo`, `path`, `bootstrap`, `callable`, and `paths` inside `legacy_comparison`. `paths` lists normalized snapshot paths that are safe to compare across migration and current envelopes. Normalization sorts associative keys and converts CRLF line endings to LF so local snapshots are stable.

If comparison is not enabled, the repo path is missing, the bootstrap file is unavailable, the callable is not loaded by the explicit bootstrap, or the fixture has no comparison `paths`, the harness skips that migration comparison and prints the reason. These skips do not fail CI.

Run the harness from `php-transformer`:

```sh
composer parity
```

`composer test` includes the parity harness.

## Product Source Fixtures

Product-shaped fixtures are compact contract slices inspired by representative product inputs. They intentionally avoid copying full generated sites or large assets. These cases assert transformer-level behavior only:

- HTML-to-block conversion for representative hero/action and product-card structures.
- Markdown conversion for nested mixed-source documents.
- Artifact compilation for website artifact bundles and manifest files as preserved data assets.
- Generic artifact-local reference reports for internal page links and asset references discovered in anchors, images, scripts, linked stylesheets, and stylesheet `url(...)` values. These reports expose normalized source path, selector, element, attribute, URL, resolved artifact path, and matched file metadata so downstream adapters can decide how to import or rewrite assets without embedding product policy in php-transformer.
- Generic HTML wrapper presentation provenance for useful class, style, and layout signals. Supported blocks keep these signals in block attributes, while `source_reports.html.presentation_signals` records the source selector, tag, captured signals, and source attributes that produced them.
- Generic HTML source provenance for converted native blocks. `source_reports.html.source_provenance` records each final block path with its block name, source selector, tag, safe source attributes, and a bounded source fragment with scripts, styles, event handlers, and `javascript:` URLs removed.

The consuming product remains responsible for source discovery, route/link rewrites, import reports, product manifest validation, generated theme files, navigation entities, and visual/semantic parity gates.
