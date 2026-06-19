# PHP Transformer

PHP Transformer is the canonical PHP primitive for converting source content and generated website artifacts into WordPress-native block outputs.

This package starts as the convergence point for the existing importer stack:

- [`chubes4/html-to-blocks-converter`](https://github.com/chubes4/html-to-blocks-converter)
- [`chubes4/block-format-bridge`](https://github.com/chubes4/block-format-bridge)
- [`chubes4/block-artifact-compiler`](https://github.com/chubes4/block-artifact-compiler)
- [`chubes4/static-site-importer`](https://github.com/chubes4/static-site-importer)

## Boundary

PHP Transformer owns reusable transformation primitives:

- HTML to parsed WordPress block arrays.
- Markdown, HTML, and blocks conversion through a block-array pivot.
- Generated website artifact normalization.
- Serializable block output, document output, asset manifests, diagnostics, fallbacks, and provenance.
- WordPress runtime adapters for calls that require WordPress APIs.

PHP Transformer does not own product workflows such as importer admin screens, uploaded ZIP intake, theme activation, Studio-specific orchestration, WordPress.com deployment behavior, or self-improving loop control.

## Initial Namespace Map

- `HtmlToBlocks` - low-level HTML to core block transforms.
- `FormatBridge` - declared-format normalization and format-to-format conversion.
- `ArtifactCompiler` - generated artifact bundle normalization and compilation.
- `Importer` - reusable importer primitives without product UI.
- `WordPress` - runtime adapters around WordPress functions.
- `Contract` - shared result envelopes and diagnostics.

## Draft Status

This package is intentionally being introduced as a draft consolidation target. Existing repositories remain valid consumers and compatibility surfaces while implementation migrates into this package.
