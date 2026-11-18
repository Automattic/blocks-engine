<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};
$fixture = (string) file_get_contents(__DIR__ . '/../fixtures/empty-configured-custom-elements.html');
$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();
$result = $transform($fixture);
$blocks = $result['serialized_blocks'] ?? '';
$assert(array() === $result['fallbacks'], 'empty configured hosts do not produce fallbacks');
$assert(str_contains($blocks, '<!-- wp:heading') && str_contains($blocks, 'Product') && str_contains($blocks, '<!-- wp:paragraph') && str_contains($blocks, 'Description.'), 'main content remains native');
$assert(! str_contains($blocks, 'product-recommendations') && ! str_contains($blocks, 'store-config'), 'empty hosts produce no blocks');
$diagnostics = array_values(array_filter($result['diagnostics'] ?? array(), static fn (array $row): bool => 'html_custom_element_render_neutral' === ($row['code'] ?? '')));
$assert(2 === count($diagnostics), 'each omitted host records an informational diagnostic');
$byTag = array_column($diagnostics, null, 'tag');
$assert('info' === ($byTag['product-recommendations']['severity'] ?? '') && '/recommendations/products?limit=4' === ($byTag['product-recommendations']['attributes']['data-url'] ?? ''), 'diagnostic retains tag and data configuration');
$assert('https://example.com' === ($byTag['store-config']['attributes']['store-domain'] ?? ''), 'diagnostic retains custom configuration attributes');

// Pseudo-element rules that cannot reach the host, or generate no box on an
// empty host, do not keep it (#6261).
foreach ( array(
    'input::-webkit-search-decoration{-webkit-appearance:none}',
    'input::placeholder{color:red}',
    'button::-moz-focus-inner{border:0}',
    '.x::after{content:""}',
    '*::selection{background:red}',
) as $css ) {
    $pseudo = $transform(str_replace('</head>', '<style>' . $css . '</style></head>', $fixture));
    $assert(array() === $pseudo['fallbacks'], 'unrelated pseudo-element rule does not produce fallbacks: ' . $css);
}
// Host-matching generated content still gives the host a box.
foreach ( array(
    '.related-products::after{content:"";display:block;height:10px}',
    'product-recommendations::before{content:"Related"}',
) as $css ) {
    $generated = $transform(str_replace('</head>', '<style>' . $css . '</style></head>', $fixture));
    $assert(in_array('product-recommendations', array_column($generated['fallbacks'], 'tag'), true), 'host generated content retains fallback: ' . $css);
}

// Universal resets do not give empty configured hosts a rendered box (#6261).
foreach ( array(
    '*, *::before, *::after { box-sizing: inherit; }',
    '*{margin:0}',
    ':where(*){padding:0}',
    'main *{box-sizing:border-box;margin:0 0 0 0;padding:0px !important}',
) as $css ) {
    $reset = $transform(str_replace('</head>', '<style>' . $css . '</style></head>', $fixture));
    $assert(array() === $reset['fallbacks'], 'universal reset does not produce fallbacks: ' . $css);
    $assert(2 === count(array_filter($reset['diagnostics'] ?? array(), static fn (array $row): bool => 'html_custom_element_render_neutral' === ($row['code'] ?? ''))), 'both reset hosts record neutral diagnostics: ' . $css);
    $assert(! str_contains($reset['serialized_blocks'], 'product-recommendations') && ! str_contains($reset['serialized_blocks'], 'store-config'), 'reset hosts produce no blocks: ' . $css);
}

// Host paint, generated content, and child content retain the old path.
foreach ( array(
    '*{background:red}',
    '.related-products{background:red}',
    '.related-products{border:1px solid}',
    'product-recommendations::before{content:"Related"}',
    '.related-products{box-shadow:0 0 4px red}',
    '.related-products{outline:1px solid}',
    '@media(min-width:900px){.related-products{background:red}}',
) as $css ) {
    $styled = $transform(str_replace('</head>', '<style>' . $css . '</style></head>', $fixture));
    $assert(in_array('product-recommendations', array_column($styled['fallbacks'], 'tag'), true), 'styled host retains fallback: ' . $css);
}
// Paint-free geometry is retained as an empty native group, never dropped.
foreach ( array(
    '.related-products{height:20px}',
    '@media(min-width:900px){.related-products{width:100px}}',
    '.related-products{margin:20px;padding:10px;min-height:5px;gap:4px}',
) as $css ) {
    $spaced = $transform(str_replace('</head>', '<style>' . $css . '</style></head>', $fixture));
    $assert(array() === $spaced['fallbacks'], 'paint-free geometry has no fallback: ' . $css);
    $assert(str_contains($spaced['serialized_blocks'], 'related-products'), 'paint-free geometry retains its group: ' . $css);
}
$inline = $transform('<x-config data-config="value" style="width:10px"></x-config>');
$assert(array() === $inline['fallbacks'] && str_contains($inline['serialized_blocks'], '<!-- wp:group') && str_contains(implode("\n", array_column($inline['assets'], 'content')), 'width:10px'), 'inline geometry survives in a native group');
$nonempty = $transform(str_replace('  </product-recommendations>', '<span></span></product-recommendations>', $fixture));
$assert(in_array('product-recommendations', array_column($nonempty['fallbacks'], 'tag'), true), 'a host with an element child retains fallback');

// Empty does not mean nonfunctional: retain semantic output, controls,
// runtime-media losses and referenced targets even without authored paint.
foreach ( array(
    '<x-status-widget status="offline"></x-status-widget>',
    '<x-field label="Email" placeholder="Email" required></x-field>',
    '<x-field data-config="email" aria-label="Email"></x-field>',
    '<x-player data-config="video"></x-player>',
    '<x-checkout data-config="value"></x-checkout>',
    '<x-cart data-config="value"></x-cart>',
    '<a href="#target">Jump</a><x-target id="target" data-config="value"></x-target>',
    '<x-config data-config="value">Text</x-config>',
    '<x-config data-config="value" role="status"></x-config>',
    '<x-config data-config="value" tabindex="0"></x-config>',
    '<x-config data-config="value" data-wp-bind--hidden="state.hidden"></x-config>',
) as $host ) {
    $retained = $transform('<!doctype html><html><body><main><h1>Product</h1>' . $host . '</main></body></html>');
    $assert(! in_array('html_custom_element_render_neutral', array_column($retained['diagnostics'] ?? array(), 'code'), true), 'functional or visible host is not dropped as neutral: ' . $host);
    $assert(array() !== $retained['fallbacks'] || str_contains($retained['serialized_blocks'], 'Text'), 'functional or visible output keeps its existing disposition: ' . $host);
}
$proven = $transform(str_replace('</body>', '<script>customElements.define("store-config", class extends HTMLElement {});</script></body>', $fixture));
$assert(str_contains($proven['serialized_blocks'], 'store-config'), 'a proven runtime host is preserved rather than dropped');

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Empty configured custom element tests passed\n";
