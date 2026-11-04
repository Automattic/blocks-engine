<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$template = (string) file_get_contents(dirname(__DIR__) . '/fixtures/navbar-context-marker-current-item/page.template.html');
// The navigation's context marker is named from its source subtree. A current
// item no author selector names must not rename it, so routes that differ only
// in which item is current project the same marker and one shared header.
$states = array('none' => array('', ''), 'home' => array(' active', ''), 'about' => array('', ' active'));
$classNames = array();
foreach ($states as $state => [$home, $about]) {
    $files = array('index.html' => strtr($template, array('{{title}}' => 'Home', '{{home}}' => $home, '{{about}}' => $about)));
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
    $headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
    $assert(1 === count($headers), $state . ': one header part.');
    $assert(1 === preg_match('/wp:navigation \{"className":"([^"]*)"/', $headers[0]['canonical_block_markup'], $match), $state . ': header holds a navigation block.');
    $assert(str_contains($match[1], 'blocks-engine-semantic-'), $state . ': navigation carries a context marker.');
    $classNames[$state] = $match[1];
}
$assert(1 === count(array_unique($classNames)), 'Current-item state must not rename the navigation context marker: ' . implode(' | ', $classNames));

$routes = array('index.html' => array('Home', ' active', ''), 'om-os/index.html' => array('About', '', ' active'), 'contact/index.html' => array('Contact', '', ''), 'other/index.html' => array('Other', '', ''));
$files = array();
foreach ($routes as $path => [$title, $home, $about]) {
    $files[$path] = strtr($template, array('{{title}}' => $title, '{{home}}' => $home, '{{about}}' => $about));
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']);
$assert(1 === count($headers), 'Routes marking a current item share one header part.');
echo "ok\n";
