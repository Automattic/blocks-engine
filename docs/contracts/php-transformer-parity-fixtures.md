# PHP Transformer Parity Fixtures

The parity harness uses JSON fixtures to lock the shared contract for PHP transformer outputs while implementation slices continue to evolve independently.

Fixture files live in `php-transformer/tests/fixtures/parity/*.json` and use schema `blocks-engine/php-transformer/parity-fixture/v1`. The JSON Schema is documented in `docs/contracts/php-transformer-parity-fixture.schema.json`.

Each fixture declares:

- `operation`: the public surface exercised by the runner, such as `format_bridge.convert`.
- `input`: operation-specific input data.
- `expect`: path-based assertions over the PHP output.
- `source_reference`: optional pointer to the legacy repo/path that inspired the fixture.
- `legacy_comparison`: optional metadata for legacy parity checks.

Expectation paths are dot-separated. Use `\\.` for literal dots inside output keys, for example `legacy_mapping.block-artifact-compiler/result/v1.wordpress_artifacts\\.block_markup`.

`legacy_comparison.skip` records cases that need a WordPress runtime, Gutenberg parser, REST callback, bundled dependency, or other legacy environment before a direct comparison is meaningful. The PHP runner still validates the current transformer output for those fixtures; it only skips direct legacy comparison with the recorded reason.

Legacy comparison is dev/local-only and opt-in. The harness never loads old repos unless either `BLOCKS_ENGINE_PARITY_LEGACY=1` is set or the fixture declares `legacy_comparison.enabled: true`.

When enabled, the runner resolves the legacy repo path from fixture metadata first, then from repo-specific environment variables:

- `BLOCKS_ENGINE_PARITY_LEGACY_HTML_TO_BLOCKS_CONVERTER_PATH`
- `BLOCKS_ENGINE_PARITY_LEGACY_BLOCK_FORMAT_BRIDGE_PATH`
- `BLOCKS_ENGINE_PARITY_LEGACY_BLOCK_ARTIFACT_COMPILER_PATH`

By default, operations map to the legacy repo `library.php` bootstrap and public callable that matches the source reference:

- `html_transformer.transform`: `html-to-blocks-converter` / `html_to_blocks_convert`
- `format_bridge.normalize`: `block-format-bridge` / `bfb_normalize`
- `artifact_compiler.compile`: `block-artifact-compiler` / `bac_compile_website_artifact`

Fixtures may override `repo`, `path`, `bootstrap`, `callable`, and `paths` inside `legacy_comparison`. `paths` lists normalized snapshot paths that are safe to compare across legacy and current envelopes. Normalization sorts associative keys and converts CRLF line endings to LF so local snapshots are stable.

If comparison is not enabled, the repo path is missing, the bootstrap file is unavailable, the callable is not loaded by the explicit bootstrap, or the fixture has no comparison `paths`, the harness skips that legacy comparison and prints the reason. These skips do not fail CI.

Run the harness from `php-transformer`:

```sh
composer parity
```

To run local legacy checks against adjacent read-only source checkouts:

```sh
BLOCKS_ENGINE_PARITY_LEGACY=1 \
BLOCKS_ENGINE_PARITY_LEGACY_HTML_TO_BLOCKS_CONVERTER_PATH=/path/to/html-to-blocks-converter \
BLOCKS_ENGINE_PARITY_LEGACY_BLOCK_FORMAT_BRIDGE_PATH=/path/to/block-format-bridge \
BLOCKS_ENGINE_PARITY_LEGACY_BLOCK_ARTIFACT_COMPILER_PATH=/path/to/block-artifact-compiler \
composer parity
```

`composer test` includes the parity harness.
