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

## Public API Surface

Consumers should treat these classes as the public entrypoints for the current package:

- `Contract\TransformerResult` - stable result envelope. Use `toArray()` when passing results across process, HTTP, fixture, or compatibility boundaries.
- `HtmlToBlocks\HtmlTransformer` - converts supported HTML elements into WordPress block arrays and serialized block markup. Unsupported top-level HTML is reported in `fallbacks`.
- `FormatBridge\FormatBridge` - normalizes and converts declared `html`, `markdown`, and serialized `blocks` content. `convertResult()` is the preferred public entrypoint when callers need diagnostics instead of exceptions.
- `ArtifactCompiler\ArtifactCompiler` - normalizes generated website artifact bundles into the shared result envelope, including block markup, source reports, assets, components, documents, and block type artifacts.
- `WordPress\Runtime` - adapter for WordPress functions used by the transformer when running inside or outside WordPress.

The remaining classes in `src/HtmlToBlocks` and `src/FormatBridge` are implementation details unless they are explicitly injected through a public constructor or `FormatBridge::registerAdapter()`. `FormatBridge\FormatAdapterInterface` is public for adapter authors; concrete bundled adapters may change as the bridge expands.

### Diagnostics And Unsupported Paths

Public entrypoints return `TransformerResult` wherever a conversion can partially succeed or needs structured diagnostics. Result diagnostics include a stable `code`, human-readable `message`, and `source` class. Convenience methods such as `FormatBridge::normalize()`, `FormatBridge::toBlocks()`, and `FormatBridge::convert()` keep throwing `InvalidArgumentException` for invalid declared formats or malformed inputs.

Use `FormatBridge::convertResult()` when unsupported source or target formats should be reported as envelope diagnostics:

```php
$result = (new FormatBridge())->convertResult($html, 'html', 'blocks')->toArray();

if ('failed' === $result['status']) {
    $diagnosticCode = $result['diagnostics'][0]['code'] ?? '';
}
```

## Draft Status

This package is intentionally being introduced as a draft consolidation target. Existing repositories remain valid consumers and compatibility surfaces while implementation migrates into this package.

## Artifact Compiler Fallbacks

The artifact compiler accepts loose generated-site bundles and normalizes them into an explicit result envelope. HTML entries are preserved as `core/html` serialized block markup, Markdown falls back to `core/html` when a Markdown adapter is not loaded, and MDX support is partial: source documents are preserved while imports and JSX component references are exposed as inspectable metadata and warnings.

Unsupported or unsafe artifact inputs are reported through diagnostics instead of hidden best-effort behavior. Empty, absolute, or root-escaping paths are rejected; oversized files are ignored according to the source report limits; and a bundle with neither an HTML entry nor source documents fails with `missing_entry_html`.
