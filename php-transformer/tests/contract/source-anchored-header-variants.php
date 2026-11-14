<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$files = array();
foreach (array('index', 'about', 'contact') as $route) {
    $paint = 'about' === $route ? ' style="padding:37px;background:#123456"' : '';
    $files[$route . '.html'] = '<!doctype html><html><body><header id="site-header"' . $paint . '><p>Our site</p><nav><a href="index.html">Home</a><a href="about.html">About</a></nav></header><main><h1>' . $route . '</h1><p>Route content.</p></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$parts = array_column($plan['template_parts'], null, 'slug');
foreach ($plan['pages'] as $page) {
    if (str_contains($page['canonical_block_markup'], '<header')) throw new RuntimeException('Route-local presentation must not leave site chrome in page content.');
    if (!str_contains($page['canonical_block_markup'], 'Route content.')) throw new RuntimeException('Page content must remain owned by the page.');
    if ('about.html' !== $page['source_path']) continue;
    $templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
    $rendered = $page['canonical_block_markup'] . ($templates[$page['route_template'] ?? 'page-about'] ?? '');
    preg_match('/wp:template-part \{"slug":"(header-[^"]+)"/', $rendered, $match);
    $part = $parts[$match[1] ?? ''] ?? null;
    if (null === $part || !str_contains($part['canonical_block_markup'], 'padding-top:')) throw new RuntimeException('The route-specific header retains its exact presentation at its authored position.');
    if (array('about.html') !== $part['placement']['source_paths']) throw new RuntimeException('The variant applies only to its source route.');
}
echo "source-anchored header variants passed\n";
