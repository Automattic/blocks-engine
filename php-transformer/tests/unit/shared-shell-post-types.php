<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$document = static function (string $title, string $type, string $chrome = 'Store'): string {
    $head = match ($type) {
        'product' => '<script type="application/ld+json">' . json_encode(array('@context' => 'https://schema.org', '@type' => 'Product', 'name' => $title, 'offers' => array('@type' => 'Offer', 'price' => '20.00', 'priceCurrency' => 'USD'))) . '</script>',
        'post' => '<meta property="og:type" content="article"><meta property="article:published_time" content="2024-03-01T00:00:00Z">',
        default => '',
    };
    $purchase = 'product' === $type ? '<img src="shirt.png" alt="' . $title . '"><p>$20.00</p><button type="button">Add to Cart</button>' : '';
    return '<html><head><title>' . $title . '</title>' . $head . '</head><body><div class="site">'
        . '<header><p>' . $chrome . ' header</p></header><main class="' . ('product' === $type ? 'product-detail' : 'content') . '"><article><h1>' . $title . '</h1>' . $purchase . '<p>' . str_repeat($title . ' description. ', 20) . '</p></article></main>'
        . '<footer><p>' . $chrome . ' footer</p></footer></div></body></html>';
};

// The distinct post chrome also exercises the type-partitioned shared-shell path.
foreach (array(
    'shared-shell' => array('index' => 'page', 'about' => 'page', 'shirt' => 'product', 'news' => 'post'),
    'product-shell' => array('index' => 'page', 'about' => 'page', 'shirt' => 'product', 'hat' => 'product'),
    'mixed' => array('index' => 'page', 'about' => 'page', 'shirt' => 'product'),
    'partitioned' => array('index' => 'page', 'about' => 'page', 'shirt' => 'product', 'news' => 'post'),
    'shared-products' => array('index' => 'page', 'about' => 'page', 'shirt' => 'product', 'hat' => 'product'),
    'posts' => array('first' => 'post', 'second' => 'post'),
    'override-products' => array('index' => 'page', 'a' => 'page', 'b' => 'page', 'c' => 'product', 'd' => 'product', 'e' => 'page', 'news' => 'post'),
) as $case => $types) {
    $files = array();
    foreach ($types as $slug => $type) $files[$slug . '.html'] = array('content' => $document($slug, $type, 'post' === $type && 'posts' !== $case ? 'Blog' : 'Store'), 'metadata' => array('post_type' => $type));
    if (in_array($case, array('product-shell', 'shared-shell'), true)) {
        foreach ($types as $slug => $type) $files[$slug . '.html']['content'] = str_replace(array('<div class="site">', '</div></body>'), array('<p>Before chrome</p>', '<p>After chrome</p></body>'), $document($slug, $type, 'product-shell' === $case ? ('product' === $type ? 'Shop' : 'Store') : ('post' === $type ? 'Blog' : 'Store')));
    }
    if ('override-products' === $case) {
        foreach ($types as $slug => $type) if ('news' !== $slug) $files[$slug . '.html']['content'] = file_get_contents(dirname(__DIR__) . '/fixtures/shared-override-templates/' . $slug . '.html');
    }
    $view = (new ArtifactCompiler())->compile(array('entrypoint' => array_key_first($files), 'files' => $files))->toWordPressSitePlanView();
    $plan = $view['wordpress_site_plan'];
    $templates = array_column($plan['templates'], null, 'slug');
    foreach ($plan['pages'] as $page) {
        $slug = $page['slug'];
        $assert($types[pathinfo($page['source_path'], PATHINFO_FILENAME)] === $page['post_type'], "$case: $slug retains its post type");
        if (!empty($page['entrypoint']) || ('override-products' === $case && 'post' === $page['post_type'])) continue;
        $templateSlug = $page['metadata']['post_meta']['_wp_page_template'] ?? $page['route_template'] ?? (isset($templates['single-' . $page['post_type'] . '-' . $slug]) ? 'single-' . $page['post_type'] . '-' . $slug : ('page' === $page['post_type'] ? 'page' : 'single'));
        if ('product' === $page['post_type'] && !in_array($case, array('product-shell', 'shared-shell'), true)) {
            $assert(str_starts_with($templateSlug, 'single-product-'), "$case: product uses a route or shared product override");
            if ('shared-products' === $case) $assert(str_starts_with($templateSlug, 'single-product-shared-'), 'equivalent product frames share a product override');
        }
        $markup = $templates[$templateSlug]['canonical_block_markup'] ?? '';
        foreach (array('header', 'footer') as $area) {
            $parts = array_filter($plan['template_parts'], static fn(array $part): bool => $area === $part['area']);
            $assert((bool) array_filter($parts, static fn(array $part): bool => str_contains($markup, '"slug":"' . $part['slug'] . '"')), "$case: $templateSlug receives $area");
        }
    }
    if ('posts' === $case) $assert('single' === $templateSlug, 'post-only chrome keeps single placement');
    if ('product-shell' === $case) {
        foreach ($plan['template_parts'] as $part) {
            if (str_contains($part['canonical_block_markup'], 'Shop') && 'shared_shell' === $part['placement']['kind']) $assert(in_array('single', $part['placement']['template_slugs'] ?? array(), true), 'product-only shell uses the single slot: ' . json_encode($part['placement']));
        }
    }
}
echo "shared-shell-post-types: ok\n";
