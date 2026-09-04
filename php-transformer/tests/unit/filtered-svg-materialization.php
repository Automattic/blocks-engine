<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$filter = '<filter id="portrait-tone" filterUnits="objectBoundingBox"><feComponentTransfer result="srcRGB"></feComponentTransfer><feColorMatrix in="srcRGB" type="saturate" values="0"></feColorMatrix><feComponentTransfer><feFuncR type="linear" slope="1.1" intercept="-0.05"></feFuncR><feFuncG type="linear" slope="1.1" intercept="-0.05"></feFuncG><feFuncB type="linear" slope="1.1" intercept="-0.05"></feFuncB></feComponentTransfer></filter>';
$artwork = static fn(string $label, bool $includeFilter = true): string => '<svg class="portrait-art" role="img" aria-label="' . $label . '" viewBox="0 0 100 80"><defs>' . ($includeFilter ? $filter : '') . '</defs><rect width="100" height="80" filter="url(#portrait-tone)"></rect></svg>';

$filtered = (new HtmlTransformer())->transform('<style>.portrait-grid{display:grid;grid-template-columns:repeat(2,1fr)}.portrait-art{width:100%;height:auto}@media(max-width:600px){.portrait-grid{grid-template-columns:1fr}}</style><main><div class="portrait-grid">' . $artwork('First portrait') . $artwork('Second portrait') . '</div></main>')->toArray();
$filteredMarkup = (string) ($filtered['serialized_blocks'] ?? '');
$filteredAssets = array_values(array_filter($filtered['assets'] ?? array(), static fn(array $asset): bool => 'inline-svg' === ($asset['source'] ?? null)));
$assert(!str_contains($filteredMarkup, '<!-- wp:html'), 'Repeated filtered artwork uses typed blocks without raw HTML.');
$assert(2 === substr_count($filteredMarkup, '<!-- wp:image'), 'Each filtered artwork instance remains an editable image block.');
$assert(1 === count($filteredAssets), 'Equivalent filtered artwork shares one portable SVG asset.');
$assert(str_contains((string) ($filteredAssets[0]['content'] ?? ''), '<feComponentTransfer') && str_contains((string) ($filteredAssets[0]['content'] ?? ''), 'filterUnits="objectBoundingBox"'), 'Filter primitives and canonical casing survive asset materialization.');
$assert(str_contains($filteredMarkup, 'portrait-art') && str_contains($filteredMarkup, 'First portrait') && str_contains($filteredMarkup, 'Second portrait'), 'Selectors and per-instance accessibility labels survive typed conversion.');
$assert('pass' === ($filtered['source_reports']['wp_block_validity']['status'] ?? null), 'Filtered image blocks are Gutenberg-valid.');

$documentFilter = (new HtmlTransformer())->transform('<main><svg data-dom-store style="display:none"><defs id="document-definitions">' . $filter . '</defs></svg>' . $artwork('Document portrait', false) . '</main>')->toArray();
$documentMarkup = (string) ($documentFilter['serialized_blocks'] ?? '');
$documentAsset = (string) ($documentFilter['assets'][0]['content'] ?? '');
$assert(!str_contains($documentMarkup, '<!-- wp:html') && 1 === substr_count($documentMarkup, '<!-- wp:image'), 'An SVG-only document definition store is replaced by its typed image consumer.');
$assert(str_contains($documentAsset, '<defs id="document-definitions">') && str_contains($documentAsset, '<feFuncR'), 'Document-level filter definitions are hydrated into the standalone SVG document.');
$assert(!str_contains($documentAsset, 'data-dom-store'), 'The hidden document store is not retained in the portable asset.');

$cssConsumer = (new HtmlTransformer())->transform('<style>.filtered-panel{filter:url(#portrait-tone)}</style><svg data-dom-store style="display:none"><defs>' . $filter . '</defs></svg><div class="filtered-panel">Panel</div>')->toArray();
$assert(str_contains((string) ($cssConsumer['serialized_blocks'] ?? ''), '<!-- wp:html'), 'A definition store with a non-SVG CSS consumer remains in document context.');

$external = (new HtmlTransformer())->transform('<svg viewBox="0 0 10 10"><use href="sprite.svg#mark"></use></svg>')->toArray();
$assert(!str_contains((string) ($external['serialized_blocks'] ?? ''), '<!-- wp:image') && 'html_inline_svg_fallback' === ($external['fallbacks'][0]['diagnostic_code'] ?? null), 'External-document SVG references still fail closed with an explicit diagnostic.');

$scriptOnly = (new HtmlTransformer())->transform('<main><svg><script>alert(1)</script></svg></main>')->toArray();
$assert('html_unsafe_inline_svg' === ($scriptOnly['fallbacks'][0]['diagnostic_code'] ?? null) && !str_contains((string) ($scriptOnly['serialized_blocks'] ?? ''), '<!-- wp:image'), 'Script-only SVG fails closed without a typed image.');

$javascriptHref = (new HtmlTransformer())->transform('<main><svg><use href="javascript:alert(1)"></use></svg></main>')->toArray();
$assert('html_unsafe_inline_svg' === ($javascriptHref['fallbacks'][0]['diagnostic_code'] ?? null) && !str_contains((string) ($javascriptHref['serialized_blocks'] ?? ''), 'javascript:'), 'javascript: SVG references fail closed and do not leak into markup.');

$eventHandler = (new HtmlTransformer())->transform('<main><svg onload="alert(1)"></svg></main>')->toArray();
$assert('html_unsafe_inline_svg' === ($eventHandler['fallbacks'][0]['diagnostic_code'] ?? null) && !str_contains((string) ($eventHandler['serialized_blocks'] ?? ''), 'onload='), 'Event-handler SVG fails closed without leaking handlers.');

// SVG rendering hints are presentation-only: they tune rasterization quality and
// carry no scripting or external reference. Artwork that sets them is still
// passive, self-contained artwork and must reach the native image path rather
// than fall back to core/html (#1243).
$renderingHints = (new HtmlTransformer())->transform(
    '<main><svg viewBox="0 0 32 32" role="presentation" aria-hidden="true"'
    . ' shape-rendering="geometricPrecision" text-rendering="optimizeLegibility"'
    . ' image-rendering="optimizeQuality" color-rendering="optimizeSpeed"'
    . ' color-interpolation="sRGB" paint-order="stroke"><path d="M4 4h24v24H4z" fill="#123456"></path></svg></main>'
)->toArray();
$renderingHintsMarkup = (string) ($renderingHints['serialized_blocks'] ?? '');
$assert(
    str_contains($renderingHintsMarkup, '<!-- wp:image') && !str_contains($renderingHintsMarkup, '<!-- wp:html'),
    'Artwork carrying SVG rendering hints materializes as an editable image instead of a core/html island.'
);
$renderingHintAssets = array_values(array_filter($renderingHints['assets'] ?? array(), static fn(array $asset): bool => 'inline-svg' === ($asset['source'] ?? null)));
$assert(
    1 === count($renderingHintAssets) && str_contains((string) ($renderingHintAssets[0]['content'] ?? ''), 'shape-rendering="geometricPrecision"'),
    'Rendering hints survive into the materialized SVG asset so rasterization intent is preserved.'
);

fwrite(STDOUT, 'Filtered SVG materialization tests: ' . $assertions . " passed\n");
