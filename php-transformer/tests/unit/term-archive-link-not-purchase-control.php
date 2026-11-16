<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A blog post filed under a "Buy" category or tag links to that term's
// archive. The link names a taxonomy term, so the post stays an article:
// no product, no product grid, no cart-runtime island around it.
$entry = static fn (string $terms): string => '<main><div class="blog-item-wrapper blog-grid-masonry-item-wrapper"><article class="h-entry entry">'
    . '<h1 class="entry-title">Golden Visa: Latest updates</h1><img src="https://example.com/visa.jpg" alt="">'
    . '<div class="blog-meta">' . $terms . '</div><p>The minimum investment rose to</p><p><strong>€800,000</strong></p>'
    . '</article></div></main>';
$cases = array(
    'category path' => '<a href="/blog/category/Buy" class="blog-item-category blog-item-category--Buy">Buy</a>',
    'tag path' => '<a href="/blog/tag/buy" class="blog-item-tag blog-item-tag--buy">buy</a>',
    'rel tag' => '<a href="/topics/purchase" rel="tag">Purchase</a>',
);
$failures = array();
foreach ( $cases as $name => $terms ) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $entry($terms))))->toArray();
    $commerce = array_values(array_filter(
        $result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(),
        static fn (array $d): bool => in_array($d['type'] ?? $d['capability'] ?? '', array('products', 'product_grids', 'shop'), true)
    ));
    $islands = array_filter($result['fallbacks'] ?? array(), static fn (array $f): bool => 'materialize_commerce_runtime' === ($f['repair_bucket'] ?? null));
    if ( array() !== $commerce || array() !== $islands ) $failures[] = $name . ': a term archive link made the post a commerce product';
}
foreach ( $failures as $failure ) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
exit(array() === $failures ? 0 : 1);
