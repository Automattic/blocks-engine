<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ClassPublisher;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

// A specificity shim matches nothing and projected selectors repeat it in
// almost every arm, so its published width is paid hundreds of times by every
// route. Publishing names it by the namespace and its kind alone
// (`apex-atelier-c0`), with no word between them.
$css = '*{box-sizing:border-box;margin:0;padding:0}a{color:inherit;text-decoration:none}button{padding:0;border:0}'
    . '.btn{display:inline-block;padding:12px 24px;background:#c00;color:#fff;border-radius:4px}.hero{padding:48px}h1{font-size:48px}';
$page = static fn (string $title): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="site.css"></head><body>'
    . '<main><section class="hero"><h1>' . $title . '</h1><p>Hi</p><a class="btn" href="about.html">Go</a></section></main></body></html>';
$compile = static fn (array $extra): array => (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('site.css' => $css, 'index.html' => $page('Home'), 'about.html' => $page('About')),
) + $extra)->toWordPressSitePlanView()['wordpress_site_plan'];
$stylesheets = static fn (array $plan): string => implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null) ? $asset['content'] : '', $plan['assets']));

$plan = $compile(array('class_namespace' => 'apex-atelier'));
$map = $plan[ClassPublisher::MAP_KEY] ?? array();
$shims = array_filter($map, static fn (string $engine): bool => 1 === preg_match('/^be-[tci]-\d+$/D', $engine), ARRAY_FILTER_USE_KEY);
$assert(array() !== $shims, 'Fixture must exercise specificity shims: ' . json_encode($map));
$css = $stylesheets($plan);
foreach ($shims as $engine => $published) {
    $expected = 'apex-atelier-' . str_replace('-', '', substr($engine, 3));
    $assert($expected === $published, 'Shim ' . $engine . ' publishes as ' . $expected . ', got ' . $published);
    $assert(str_contains($css, $published), 'The published shim ' . $published . ' is what stylesheets name.');
    $assert(!str_contains($css, $engine), 'No stylesheet keeps the internal shim ' . $engine . '.');
}
$assert(!str_contains($css, 'apex-atelier-weight-'), 'No shim ships under the long namespaced name.');

echo "published-specificity-shim-width: ok\n";
