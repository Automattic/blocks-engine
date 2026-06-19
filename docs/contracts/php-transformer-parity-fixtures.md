# PHP Transformer Parity Fixtures

The parity harness uses JSON fixtures to lock the shared contract for PHP transformer outputs while implementation slices continue to evolve independently.

Fixture files live in `php-transformer/tests/fixtures/parity/*.json` and use schema `blocks-engine/php-transformer/parity-fixture/v1`. The JSON Schema is documented in `docs/contracts/php-transformer-parity-fixture.schema.json`.

Each fixture declares:

- `operation`: the public surface exercised by the runner, such as `format_bridge.convert`.
- `input`: operation-specific input data.
- `expect`: path-based assertions over the PHP output.
- `source_reference`: optional migration note for the source fixture that inspired the case.
- `legacy_comparison`: optional local-only migration evidence metadata.

Expectation paths are dot-separated. Use `\\.` for literal dots inside output keys, for example `legacy_mapping.consumer/result/v1.consumer_artifacts\\.block_markup`.

`legacy_comparison.skip` records cases where local migration evidence needs runtime context before a direct comparison is meaningful. The PHP runner still validates the current transformer output for those fixtures; it only skips optional migration comparison with the recorded reason.

Migration comparison is dev/local-only and opt-in. It is evidence for downstream migrations, not a package feature or long-term support promise. See `php-transformer/docs/html-transform-coverage.md` for the local commands and environment variables.

Run the harness from `php-transformer`:

```sh
composer parity
```

`composer test` includes the parity harness.

## Product Source Fixtures

Fixtures prefixed with `ssi-` are compact contract slices inspired by representative product fixtures. They intentionally avoid copying full generated sites or large assets. These cases assert transformer-level behavior only:

- HTML-to-block conversion for representative hero/action and product-card structures.
- Markdown conversion for nested mixed-source documents.
- Artifact compilation for website artifact bundles and manifest files as preserved data assets.

The consuming product remains responsible for source discovery, route/link rewrites, import reports, product manifest validation, generated theme files, navigation entities, and visual/semantic parity gates.
