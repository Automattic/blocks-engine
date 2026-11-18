<?php
declare(strict_types=1);

/**
 * A responsive capture mints its projection hook per route, so the same
 * footer element carries a differently named hook on each route that
 * recorded one, and none on a route that did not. When each hook's rule only
 * restates the element's inline style, the hooks are capture evidence, not
 * route vocabulary: every route shares one footer part and every template
 * binds it.
 */

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$css = '.site-footer{padding:24px;background:#f4f4f4}.legal{font-size:14px}';
$page = static function (string $title, string $hook, string $style) use ($css): string {
    $rule = '' === $hook ? '' : '@media(max-width:1023px){.' . $hook . '{text-decoration:underline!important}}';
    $span = '<span style="' . $style . '"' . ('' === $hook ? '' : ' class="' . $hook . '"') . '><a href="/terms/">Terms</a></span>';
    return '<!doctype html><html><head><style>' . $css . $rule . '</style></head><body>'
        . '<header><p class="brand">Brand</p><nav><a href="/">Home</a><a href="/about/">About</a><a href="/terms/">Terms</a></nav></header>'
        . '<main><h1>' . $title . '</h1><p>Body text for ' . $title . '.</p></main>'
        . '<footer class="site-footer"><p class="legal">&copy; 2035 by Example. ' . $span . '</p></footer></body></html>';
};
$routes = array(
    'index.html' => $page('Welcome', 'data-blocks-engine-capture-responsive-ec3fe6baa04f', 'text-decoration: underline'),
    'about/index.html' => $page('About', '', 'text-decoration: underline'),
    'terms/index.html' => $page('Terms', 'data-blocks-engine-capture-responsive-6b118c56b1e9', 'text-decoration:underline'),
);

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $routes))->toArray()['source_reports']['wordpress_site_plan'];
$footers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null)));
$assert(1 === count($footers), 'One footer part serves every route: ' . json_encode(array_column($footers, 'slug')));
$covered = $footers[0]['placement']['source_paths'] ?? array_keys($footers[0]['provenance']['sources'] ?? array());
sort($covered);
$expected = array_keys($routes);
sort($expected);
$assert($expected === $covered, 'The shared footer covers every route: ' . json_encode($covered));
$assert(!str_contains($footers[0]['canonical_block_markup'], 'capture-responsive') && !str_contains($footers[0]['canonical_block_markup'], 'route-class-'), 'The shared footer carries no restating capture hook.');
$assert(str_contains($footers[0]['canonical_block_markup'], 'Terms'), 'The shared footer keeps its copy.');
foreach (ThemeTemplates::rendered($plan) as $slug => $template) {
    $assert(str_contains($template, '"slug":"' . $footers[0]['slug'] . '"'), "Template {$slug} binds the shared footer.");
}
foreach ($plan['pages'] as $row) {
    $assert(!str_contains($row['canonical_block_markup'], '2035 by Example'), 'Route content keeps no footer copy: ' . $row['source_path']);
}
echo "Shared footer route-named restating hooks: passed\n";
