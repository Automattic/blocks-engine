# PHP Transformer Parity Fixtures

The parity harness uses JSON fixtures to lock the shared contract for PHP transformer outputs while implementation slices continue to evolve independently.

Fixture files live in `php-transformer/tests/fixtures/parity/*.json` and use schema `blocks-engine/php-transformer/parity-fixture/v1`. The JSON Schema is documented in `docs/contracts/php-transformer-parity-fixture.schema.json`.

Each fixture declares:

- `operation`: the public surface exercised by the runner.
- `input`: operation-specific input data.
- `expect`: path-based assertions over the PHP output.
- `source_reference`: optional pointer to the legacy repo/path that inspired the fixture.
- `legacy_comparison`: optional metadata for legacy parity checks.

Expectation paths are dot-separated. Use `\\.` for literal dots inside output keys, for example `legacy_mapping.block-artifact-compiler/result/v1.wordpress_artifacts\\.block_markup`.

`legacy_comparison.skip` records cases that need a WordPress runtime, Gutenberg parser, REST callback, bundled dependency, or other legacy environment before a direct comparison is meaningful. The PHP runner still validates the current transformer output for those fixtures; it only skips direct legacy comparison.

Run the harness from `php-transformer`:

```sh
composer parity
```

`composer test` includes the parity harness.
