# PHP Transformer

PHP Transformer is the canonical PHP primitive for converting source content and generated website artifacts into WordPress-native block outputs.

This package is intentionally origin-clean: it exposes transformer primitives and result contracts without publishing compatibility wrappers or product adapters for downstream plugins.

## Boundary

PHP Transformer owns reusable transformation primitives:

- HTML to parsed WordPress block arrays.
- Markdown, HTML, and blocks conversion through a block-array pivot.
- Generated website artifact normalization.
- Serializable block output, document output, asset manifests, diagnostics, fallbacks, and provenance.
- WordPress runtime adapters for calls that require WordPress APIs.

PHP Transformer does not own product workflows such as importer admin screens, uploaded ZIP intake, theme activation, Studio-specific orchestration, WordPress.com deployment behavior, or self-improving loop control. Product-specific compatibility wrappers belong in downstream packages or in `examples/compatibility/`, not in the canonical package API.

## Namespace Map

- `HtmlToBlocks` - low-level HTML to core block transforms.
- `FormatBridge` - declared-format normalization and format-to-format conversion.
- `ArtifactCompiler` - generated artifact bundle normalization and compilation.
- `WordPress` - runtime adapters around WordPress functions.
- `Contract` - shared result envelopes and diagnostics.

## Public API Surface

Consumers should treat these classes and interface as the public entrypoints for the current package:

- `Contract\TransformerResult` - stable result envelope. Use `toArray()` when passing results across process, HTTP, fixture, or compatibility boundaries.
- `HtmlToBlocks\HtmlTransformer` - converts supported HTML elements into WordPress block arrays and serialized block markup. Unsupported top-level HTML is reported in `fallbacks`.
- `FormatBridge\FormatBridge` - normalizes and converts declared `html`, `markdown`, and serialized `blocks` content through `convertResult()`.
- `FormatBridge\FormatAdapterInterface` - adapter contract for adding formats to `FormatBridge` when a consumer genuinely needs a package-level extension point.
- `ArtifactCompiler\ArtifactCompiler` - normalizes generated website artifact bundles into the shared result envelope, including block markup, source reports, assets, components, documents, and block type artifacts.
- `WordPress\Runtime` - adapter for WordPress functions used by the transformer when running inside or outside WordPress.

The remaining classes in `src/HtmlToBlocks`, `src/FormatBridge`, and `src/ArtifactCompiler` are implementation details. Concrete bundled adapters, registries, normalizers, and factories may change as the bridge expands.

### Canonical Examples

```php
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$htmlResult = (new HtmlTransformer())->transform('<h1>Hello</h1>')->toArray();

$formatResult = (new FormatBridge())->convertResult('# Hello', 'markdown', 'blocks')->toArray();

$artifactResult = (new ArtifactCompiler())->compile(array(
    'generated_html' => '<main><h1>Hello</h1></main>',
))->toArray();
```

### Diagnostics And Unsupported Paths

Public transformation entrypoints return `TransformerResult` wherever a conversion can partially succeed or needs structured diagnostics. Result diagnostics include a stable `code`, human-readable `message`, and `source` class.

Use `FormatBridge::convertResult()` for format conversions and unsupported source or target format diagnostics:

```php
$result = (new FormatBridge())->convertResult($html, 'html', 'blocks')->toArray();

if ('failed' === $result['status']) {
    $diagnosticCode = $result['diagnostics'][0]['code'] ?? '';
}
```

`FormatBridge::normalize()`, `FormatBridge::toBlocks()`, and `FormatBridge::convert()` remain available for compatibility wrappers that must preserve older string or array return types. New consumers should prefer `convertResult()` and read `documents`, `blocks`, `serialized_blocks`, and `diagnostics` from the result envelope.

## Draft Status

This package is intentionally being introduced as a draft consolidation target. Existing repositories remain valid consumers and compatibility surfaces while implementation migrates into this package.

Transitional migration notes live in [`docs/migration.md`](docs/migration.md). They are local planning evidence for downstream consumers, not package API commitments.

## Artifact Compiler Fallbacks

The artifact compiler accepts loose generated-site bundles and normalizes them into an explicit result envelope. HTML entries are preserved as `core/html` serialized block markup, Markdown falls back to `core/html` when a Markdown adapter is not loaded, and MDX support is partial: source documents are preserved while imports and JSX component references are exposed as inspectable metadata and warnings.

Unsupported or unsafe artifact inputs are reported through diagnostics instead of hidden best-effort behavior. Empty, absolute, or root-escaping paths are rejected; oversized files are ignored according to the source report limits; and a bundle with neither an HTML entry nor source documents fails with `missing_entry_html`.

## Parity Checks

Run the package contract and parity fixtures with `composer test`. The checked-in fixtures assert current transformer behavior. Optional local migration comparisons against existing consumers are disabled by default and documented separately in [`docs/html-transform-coverage.md`](docs/html-transform-coverage.md).
