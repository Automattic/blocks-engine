<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };
$compile = static function (array $files): array {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    WordPressSitePlan::assertValid($plan);
    return $plan;
};
$functions = static fn (array $plan): string => (string) (array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'] ?? '');

// The issue's reduced page: the source trail uses short navigation labels
// ("Support", "Overview") while the pages are titled differently. Core builds
// the rendered trail from page titles, so the theme runtime must filter
// `block_core_breadcrumbs_items` and swap in the source labels, keyed by the
// route each crumb resolves to.
$fixture = dirname(__DIR__) . '/fixtures/breadcrumb-trail-label-site/';
$plan = $compile(array(
    'index.html' => file_get_contents($fixture . 'index.html'),
    'support/index.html' => file_get_contents($fixture . 'support/index.html'),
    'support/overview/index.html' => file_get_contents($fixture . 'support/overview/index.html'),
));
$source = $functions($plan);
$assert(str_contains($source, 'block_core_breadcrumbs_items'), 'The theme runtime filters Core breadcrumb items.');
$assert(str_contains($source, "'/support/' => 'Support'") && str_contains($source, "'/support/overview/' => 'Overview'"), 'Source crumb labels are recorded by resolved route.');
$assert(! str_contains($source, "'/' => 'Home'"), 'A crumb whose label already equals its page title records nothing.');

// Matching labels emit no runtime at all.
$matching = $compile(array(
    'index.html' => '<main><h1>Home</h1></main>',
    'about/index.html' => '<title>About</title><nav aria-label="breadcrumb"><ol><li><a href="/index.html">Home</a></li><li><a href="/about/index.html">About</a></li></ol></nav><main><h1>About</h1></main>',
));
$assert(! str_contains($functions($matching), 'block_core_breadcrumbs_items'), 'Matching labels emit no breadcrumb filter.');

echo "breadcrumb-trail-source-labels: ok\n";
