<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$template = (string) file_get_contents(dirname(__DIR__) . '/fixtures/shared-navigation-context-marker/page.template.html');
// A navigation's context markers are named from what the author selectors that
// project onto it can observe. Which item is current, an empty style attribute
// and the sections around the header are not observable by them, so routes that
// differ only in those project the same markers.
$crumbs = '<section class="crumbs"><p>Crumbs</p></section>';
$routes = array(
    'index.html' => array('Home', '', '', ''),
    'about/index.html' => array('About', ' active', ' style=""', $crumbs),
    'team/index.html' => array('Team', '', '', $crumbs),
);
$page = static fn(array $route, string $css = ''): string => str_replace('</style>', $css . '</style>', strtr($template, array('{{title}}' => $route[0], '{{home}}' => '', '{{about}}' => $route[1], '{{style}}' => $route[2], '{{crumbs}}' => $route[3])));
$markers = static function (string $html): string {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toWordPressSitePlanView()['wordpress_site_plan'];
    $header = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
    preg_match_all('/blocks-engine-navigation-(?:list-box|instance)-[0-9a-f]{12}-\d+/', json_encode($header) ?: '', $found);
    return implode(' ', array_unique($found[0]));
};

// The author CSS also colours the current item, so the route-derived
// per-item colour markers differ between routes too.
$current = '.site-header .link{color:#123}.site-header .item.active .link{color:#900}';
$names = array();
foreach ($routes as $path => $route) $names[$path] = $markers($page($route, $current));
$assert(str_contains($names['index.html'], 'list-box') && str_contains($names['index.html'], 'instance'), 'The navigation carries its context markers: ' . json_encode($names));
$assert(1 === count(array_unique($names)), 'Route state must not rename the navigation markers: ' . json_encode($names));

// Together the routes share one header part.
$files = array();
foreach ($routes as $path => $route) $files[$path] = $page($route);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
$assert(1 === count($headers) && count($routes) === count($headers[0]['provenance']['sources'] ?? array()), 'Routes with the same header share one header part.');

echo "ok\n";
