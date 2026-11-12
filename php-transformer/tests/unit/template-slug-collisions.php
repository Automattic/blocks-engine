<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\TemplateSlugs;

// Pages whose terminal slugs collide take a readable route-qualified template.
// When that readable name is the shared key itself, a short route hash makes
// it distinct. The front page and pages with a unique slug keep their key.
$page = static fn(string $path, string $slug, array $extra = array()): array => array('source_path' => ltrim($path, '/') . '.html', 'post_type' => 'page', 'slug' => $slug, 'route' => array('path' => $path)) + $extra;
$pages = TemplateSlugs::qualifyColliding(array(
    $page('/', 'home', array('entrypoint' => true)),
    $page('/about', 'about'),
    $page('/north/about', 'about'),
    $page('/work', 'work'),
));
$expected = array(
    '.html' => null,
    'about.html' => 'page-about-' . substr(hash('sha256', '/about'), 0, 8),
    'north/about.html' => 'page-north-about',
    'work.html' => null,
);
foreach ($pages as $row) {
    $template = $expected[$row['source_path']];
    if (($row['route_template'] ?? null) !== $template || ($row['metadata']['post_meta']['_wp_page_template'] ?? null) !== $template) {
        throw new RuntimeException('Unexpected template for ' . $row['source_path'] . ': ' . var_export($row['route_template'] ?? null, true));
    }
}
echo "template-slug-collisions: passed\n";
