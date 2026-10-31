<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$svg = '<svg role="img" aria-label="Fern" viewBox="0 0 200 200"><path d="M20 180L100 20L180 180Z" fill="green"/></svg>';
$icon = '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24"><path d="M0 0L24 24"/></svg>';
foreach (array(
    'inline SVG' => array($svg, 'Fern', true),
    'img priority' => array($svg . '<img src="fern.jpg" alt="Photo">', 'Photo', false),
    'title name' => array(str_replace(' aria-label="Fern"', '', str_replace('<path', '<title>Fern title</title><path', $svg)), 'Fern title', true),
    'icons only' => array($icon, null, false),
) as $case => [$media, $alt, $materialized]) {
    $cards = '';
    foreach (array('Fern', 'Palm', 'Moss', 'Ivy') as $index => $name) {
        $cards .= '<li><span class="card-cat">' . ($index % 2 ? 'Outdoor' : 'Indoor') . '</span>'
            . '<div class="media">' . $icon . $media . '</div><h3>' . $name . '</h3><span>$20</span>'
            . '<button>Add to cart' . $icon . '</button></li>';
    }
    $result = (new HtmlTransformer())->transform('<main><ul>' . $cards . '</ul></main>')->toArray();
    $findings = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $f): bool => 'html_product_grid_fallback' === ($f['diagnostic_code'] ?? null)));
    $products = $findings[0]['products'] ?? array();
    $assert(4 === count($products), $case . ': four products');
    foreach ($products as $index => $product) {
        $assert(($index % 2 ? 'Outdoor' : 'Indoor') === $product['category'], $case . ': category');
        if (null === $alt) {
            $assert(null === $product['image'], $case . ': decorative icons rejected');
            continue;
        }
        $image = $product['image'];
        $assert($alt === ($image['alt'] ?? null), $case . ': accessible name');
        if (!$materialized) {
            $assert('fern.jpg' === $image['src'], $case . ': img wins');
            continue;
        }
        $assert(1 === preg_match('~^assets/materialized-svg/inline-svg-[a-f0-9]{16}\.svg$~', $image['src']), $case . ': content-addressed path');
        $assets = array_column($result['assets'] ?? array(), null, 'path');
        $assert(isset($assets[$image['src']]), $case . ': generated asset present');
        $assert(str_contains($result['serialized_blocks'], 'src="' . $image['src'] . '"'), $case . ': block and evidence share the asset path');
        $assert(!str_contains($assets[$image['src']]['content'], 'onload'), $case . ': sanitized');
    }
}
echo "product-grid-inline-svg: ok\n";
