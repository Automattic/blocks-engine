<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// A shared navbar whose author CSS observes the current-state class: routes
// mark different items current (or none), yet render the same resting menu.
$template = (string) file_get_contents(dirname(__DIR__) . '/fixtures/navigation-current-marker/page.html');
$routes = array('index' => 'home', 'about' => 'about', 'work' => 'work', 'plain' => '', 'other' => '');
$files = array();
foreach ($routes as $slug => $current) {
    $files[$slug . '.html'] = strtr($template, array(
        '{{home}}' => 'home' === $current ? ' active' : '',
        '{{about}}' => 'about' === $current ? ' active' : '',
        '{{work}}' => 'work' === $current ? ' active' : '',
        '{{title}}' => ucfirst($slug),
    ));
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

// Each route compiled alone: the markers on the navigation name the resting
// menu, so they match whichever item (if any) the route marks current.
$markers = array();
foreach ($files as $path => $html) {
    $single = (new ArtifactCompiler())->compile(array('entrypoint' => $path, 'files' => array($path => $html)))->toArray()['source_reports']['wordpress_site_plan'];
    preg_match_all('/blocks-engine-navigation-(?:list-box|instance)-[a-f0-9]{12}-\d+/', json_encode($single), $found);
    foreach (array_unique($found[0]) as $marker) $markers[preg_replace('/-[a-f0-9]{12}-\d+$/', '', $marker)][$marker][] = $path;
}
$assert(1 === count($markers['blocks-engine-navigation-list-box'] ?? array()), 'The list context marker is the same on every route: ' . json_encode($markers));
$assert(1 === count($markers['blocks-engine-navigation-instance'] ?? array()), 'The navigation instance marker is the same on every route: ' . json_encode($markers));

$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'Routes differing only in the current item share one header part, got ' . count($headers));
$menus = array_map(static fn(array $menu): string => (string) $menu['block_markup'], $plan['menus']);
$assert(1 === count($menus), 'One navigation entity serves every route, got ' . count($menus));
echo "ok\n";
