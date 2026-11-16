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

// Host paint, geometry, generated content, and child content retain the old path.
foreach ( array(
    '.related-products{height:20px}',
    '.related-products{background:red}',
    '.related-products{border:1px solid}',
    'product-recommendations::before{content:"Related"}',
    '@media(min-width:900px){.related-products{width:100px}}',
) as $css ) {
    $styled = $transform(str_replace('</head>', '<style>' . $css . '</style></head>', $fixture));
    $assert(in_array('product-recommendations', array_column($styled['fallbacks'], 'tag'), true), 'styled host retains fallback: ' . $css);
}
$nonempty = $transform(str_replace('  </product-recommendations>', '<span></span></product-recommendations>', $fixture));
$assert(in_array('product-recommendations', array_column($nonempty['fallbacks'], 'tag'), true), 'a host with an element child retains fallback');

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Empty configured custom element tests passed\n";
