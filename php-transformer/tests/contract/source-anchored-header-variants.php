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
// Route-specific descendant paint is still site chrome. Extract it without
// substituting the dominant header or changing its authored presentation.
foreach ($files as $source => &$html) {
    $html = str_replace(' style="padding:37px;background:#123456"', '', $html);
    $html = str_replace('<p>Our site</p>', '<section class="bar' . ('about.html' === $source ? ' is-pinned' : '') . '"><p>Our site</p></section>', $html);
    $html = str_replace('<body>', '<head><style>.bar{background:#123}.bar.is-pinned{background:#900}</style></head><body>', $html);
}
unset($html);
$descendant = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($descendant);
$headers = array_filter($descendant['template_parts'], static fn(array $part): bool => 'header' === $part['area']);
if (2 !== count($headers)) throw new RuntimeException('Descendant paint needs a route-local header part.');
$about = array_column($descendant['pages'], 'canonical_block_markup', 'source_path')['about.html'];
if (str_contains($about, 'Our site') || str_contains($about, '<header')) throw new RuntimeException('Route-local site chrome must not remain page-owned.');
$variants = array_values(array_filter($headers, static fn(array $part): bool => array('about.html') === ($part['placement']['source_paths'] ?? array())));
if (1 !== count($variants) || !str_contains($variants[0]['canonical_block_markup'], 'is-pinned')) throw new RuntimeException('The route-local descendant class must survive extraction.');
foreach ($headers as $header) {
    if ($header['slug'] !== $variants[0]['slug'] && str_contains($header['canonical_block_markup'], 'is-pinned')) throw new RuntimeException('Route-local paint must not leak into the dominant header.');
}
echo "source-anchored header variants passed\n";
