<?php
declare(strict_types=1);

/**
 * schema.org Product JSON-LD in compiled HTML is provider-neutral evidence for
 * the `shop` entity collection. See blocks-engine#2863.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures): void {
    if ($ok) return;
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$ld = static fn (array $data, string $type = 'application/ld+json'): string => '<script type="' . $type . '">' . json_encode($data, JSON_UNESCAPED_SLASHES) . '</script>';
$page = static fn (string $head, string $body = ''): string => '<!doctype html><html><head><title>Shop</title>' . $head . '</head><body><main><h1>Shop</h1>' . $body . '</main></body></html>';
$product = static fn (string $name, string $path, string $price, string $image): array => array('@type' => 'Product', 'name' => $name, 'url' => 'https://shop.example' . $path, 'image' => $image, 'offers' => array('@type' => 'Offer', 'price' => $price, 'priceCurrency' => 'EUR'));

$files = array(
    'index.html' => $page($ld(array('@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => array(
        array('@type' => 'ListItem', 'position' => 1, 'item' => $product('Intro Course', '/product-page/intro-course', '50', 'https://cdn.example/a.jpg')),
        array('@type' => 'ListItem', 'position' => 2, 'item' => $product('Advanced Course', '/product-page/advanced-course', '90', 'https://cdn.example/b.jpg')),
        array('@type' => 'ListItem', 'position' => 3, 'item' => $product('Workbook &amp; Notes', '/product-page/workbook', '12.5', 'https://cdn.example/c.jpg')),
    )))),
    'product-page/intro-course.html' => $page($ld(array('@context' => 'https://schema.org/', '@type' => 'Product', 'name' => 'Intro Course', 'description' => 'The <b>first</b> course.', 'image' => array(array('@type' => 'ImageObject', 'contentUrl' => 'https://cdn.example/a.jpg')), 'offers' => array('@type' => 'Offer', 'url' => 'https://shop.example/product-page/intro-course', 'price' => '50', 'priceCurrency' => 'EUR')), 'application/ld+json; charset=utf-8')),
    'product-page/advanced-course.html' => $page($ld(array('@context' => 'https://schema.org', '@graph' => array(array('@type' => 'WebPage', 'name' => 'Advanced'), $product('Advanced Course', '/product-page/advanced-course', '90', 'https://cdn.example/b.jpg'))))),
    'broken.html' => $page('<script type="application/ld+json">{"@type":"Product","name":"Broken",</script>'),
);

$shop = static function (array $files): array {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
        if ('entity_collection' === ($declaration['kind'] ?? null) && 'products' === ($declaration['type'] ?? null)) return $declaration['payload']['entities'] ?? array();
    }
    return array();
};
$fields = static fn (array $entities): array => array_map(static fn (array $row): array => array_intersect_key($row, array_flip(array('name', 'slug', 'regular_price', 'image'))), $entities);

$entities = $shop($files);
$assert(3 === count($entities), 'Exactly three shop entities are declared.', json_encode($entities));
$assert(array(
    array('name' => 'Advanced Course', 'slug' => 'advanced-course', 'regular_price' => '90', 'image' => 'https://cdn.example/b.jpg'),
    array('name' => 'Intro Course', 'slug' => 'intro-course', 'regular_price' => '50', 'image' => 'https://cdn.example/a.jpg'),
    array('name' => 'Workbook & Notes', 'slug' => 'workbook', 'regular_price' => '12.50', 'image' => 'https://cdn.example/c.jpg'),
) == $fields($entities), 'Names, slugs, prices and images are exact.', json_encode($fields($entities)));
$intro = array_values(array_filter($entities, static fn (array $row): bool => 'intro-course' === $row['slug']))[0] ?? array();
$assert('The first course.' === ($intro['description'] ?? null) && 'product-page/intro-course.html' === ($intro['source_path'] ?? null), 'The product page that owns the URL supplies the row.', json_encode($intro));
$assert(!in_array('Broken', array_column($entities, 'name'), true), 'Malformed JSON-LD is ignored.');
$assert($entities === $shop(array_reverse($files, true)) && $entities === $shop(array_reverse(array_values(array_map(static fn (string $path, string $content): array => array('path' => $path, 'content' => $content), array_keys($files), $files)))), 'File order does not change the output.');

// A grid-detected product sharing a slug keeps its bindings and selector.
$card = static fn (string $name, string $price): string => '<div class="card"><img src="https://cdn.example/g.jpg" alt="' . $name . '"><h3>' . $name . '</h3><span class="price">$' . $price . '</span></div>';
$gridFiles = $files;
$gridFiles['index.html'] = $page('', '<div class="grid">' . $card('Intro Course', '40.00') . $card('Gadget', '20.00') . $card('Gizmo', '30.00') . $card('Widget', '31.00') . '</div>');
$gridEntities = $shop($gridFiles);
$gridIntro = array_values(array_filter($gridEntities, static fn (array $row): bool => 'intro-course' === $row['slug']));
$assert(1 === count($gridIntro), 'A shared slug merges into one entity.', json_encode($gridEntities));
$gridIntro = $gridIntro[0] ?? array();
$assert('40.00' === ($gridIntro['regular_price'] ?? null) && 'index.html' === ($gridIntro['source_path'] ?? null) && isset($gridIntro['selector']) && !empty($gridIntro['bindings']), 'The grid row keeps its bindings, selector and source path.', json_encode($gridIntro));
$assert('https://cdn.example/a.jpg' === ($gridIntro['image'] ?? null) && 'The first course.' === ($gridIntro['description'] ?? null), 'Detail JSON-LD owns description and image; grid bindings stay.', json_encode($gridIntro));
$assert($gridEntities === $shop(array_reverse($gridFiles, true)), 'Merged output is order independent.');

if ($failures > 0) exit(1);
echo "artifact-compiler-schema-product-evidence: ok\n";
