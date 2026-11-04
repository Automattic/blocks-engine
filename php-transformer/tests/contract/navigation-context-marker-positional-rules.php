<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$template = (string) file_get_contents(dirname(__DIR__) . '/fixtures/navigation-context-marker-positional-rules/page.template.html');
// The stylesheet carries positional rules (sibling combinators, structural
// pseudo-classes) for parts of the page unrelated to the header. They cannot
// observe the header's surroundings, so the sections around it, which item is
// current and an empty style attribute must not rename the navigation's markers.
$crumbs = '<section class="crumbs"><ol><li><a href="/">Home</a></li><li>Page</li></ol></section>';
$routes = array(
    'about/index.html' => array('{{title}}' => 'About', '{{home}}' => '', '{{about}}' => ' active', '{{style}}' => '', '{{crumbs}}' => ''),
    'team/index.html' => array('{{title}}' => 'Team', '{{home}}' => '', '{{about}}' => '', '{{style}}' => ' style=""', '{{crumbs}}' => $crumbs),
    'index.html' => array('{{title}}' => 'Home', '{{home}}' => '', '{{about}}' => '', '{{style}}' => '', '{{crumbs}}' => ''),
);
$page = static fn(array $route, string $css = ''): string => strtr($template, $route + array('{{css}}' => $css));
$headers = static function (array $files): array {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
    return array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
};
$navigation = static function (string $html) use ($headers, $assert): string {
    $header = $headers(array('index.html' => $html));
    $assert(1 === count($header) && 1 === preg_match('/wp:navigation \{"className":"([^"]*)"/', $header[0]['canonical_block_markup'], $match), 'The page has a header holding a navigation block.');
    return $match[1];
};

$classNames = array_map(static fn(array $route): string => $navigation($page($route)), $routes);
$assert(str_contains($classNames['index.html'], 'blocks-engine-'), 'The navigation carries engine markers: ' . json_encode($classNames));
$assert(1 === count(array_unique($classNames)), 'Unrelated positional rules must not let route context rename the navigation: ' . json_encode($classNames));

$files = array_map(static fn(array $route): string => $page($route), $routes);
$shared = $headers($files);
$assert(1 === count($shared) && count($routes) === count($shared[0]['provenance']['sources'] ?? array()), 'All routes share one header part: ' . count($shared));

// A sibling combinator that can match the header's neighbours does observe
// them, so the sections around the header stay part of the marker identity.
$sibling = '.site-header+.crumbs{margin-top:0}';
$assert($navigation($page($routes['index.html'], $sibling)) !== $navigation($page($routes['team/index.html'], $sibling)), 'A positional rule matching the header level keeps its siblings in the marker name.');
echo "ok\n";
