<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$files = array('menu.css' => '.primary a{text-decoration:none}.primary .active>a{text-underline-offset:7px;text-decoration:underline}');
foreach (array('index' => 'Home', 'nosotros' => 'Nosotros', 'team' => 'Team') as $slug => $title) {
    $files[$slug . '.html'] = '<html><head><title>' . $title . '</title><link rel="stylesheet" href="menu.css"></head><body><header><nav class="primary"><ul>'
        . '<li' . ('index' === $slug ? ' class="active"' : '') . '><a href="index.html">Home</a></li><li' . ('nosotros' === $slug ? ' class="active"' : '') . '><a href="nosotros.html">Nosotros</a><ul><li><a href="team.html">Team</a></li></ul></li>'
        . '</ul></nav></header><main><h1>' . $title . '</h1><p>Content for ' . $title . '</p></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(1 === count($plan['menus']), 'All routes share one native navigation entity.');
$menu = $plan['menus'][0]['block_markup'];
$assert(!str_contains($menu, 'blocks-engine-current-navigation-item'), 'The shared menu freezes no current route.');
preg_match('/<!-- wp:navigation-submenu (\{.*?\}) -->/', $menu, $parentMatch);
preg_match('/<!-- wp:navigation-link (\{[^<>]*?"label":"Team"[^<>]*?\}) \/-->/', $menu, $leafMatch);
$parent = json_decode($parentMatch[1] ?? '', true);
$leaf = json_decode($leafMatch[1] ?? '', true);
$assert(is_array($parent) && is_array($leaf), 'The normal site plan emits a submenu parent and a leaf.');
$bootstrap = '';
foreach ($plan['writes'] as $write) {
    if ('functions.php' === ($write['path'] ?? $write['target_path'] ?? '')) $bootstrap = $write['payload']['data'];
}
// The active leaf on Home keeps the source rule; the submenu-parent route
// must receive that runtime paint too, without freezing or duplicating it.
$isCurrentRule = static fn (string $css): bool => 1 === preg_match('/[^{}]*wp-block-navigation[^{}]*\\[aria-current="page"\\][^{}]*\\{[^}]*text-decoration:\\s*underline/', $css);
$currentAssets = array_values(array_filter($plan['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '') && $isCurrentRule((string) ($asset['content'] ?? ''))));
$assert(array() !== $currentAssets, 'Source current-item underline is projected onto native navigation.');
foreach ($currentAssets as $asset) {
    $assert(str_contains($asset['target_path'], '/shared-chrome-'), 'Current-item paint belongs to shared chrome, not a route-specific stylesheet.');
    $assert(array(array('kind' => 'global')) === $asset['scopes'], 'Current-item paint is independent of the captured route DOM.');
    $owners = array_column($asset['stylesheet_instances'] ?? array(), 'source_path');
    foreach (array('index.html', 'nosotros.html', 'team.html') as $source) {
        $assert(in_array($source, $owners, true), 'Every menu route, including the current submenu parent and an inactive source DOM, enqueues current-item paint: ' . $source);
    }
    preg_match('/\\$blocks_engine_presentation_styles = (array \\(.*?\\n\\));\\n/s', $bootstrap, $editorMatch);
    $editorStyles = isset($editorMatch[1]) ? eval('return ' . $editorMatch[1] . ';') : array();
    $assert(in_array($asset['target_path'], array_column($editorStyles, 'target_path'), true), 'The editor canvas receives shared current-item paint.');
}
// Isolate the generated render callbacks, as in the site-plan runtime contracts.
$filters = array();
foreach (array('navigation-link', 'navigation-submenu') as $name) {
    $hook = strpos($bootstrap, "add_filter( 'render_block_core/" . $name . "',");
    $assert(false !== $hook, 'Generated theme registers the ' . $name . ' current-state filter.');
    $start = strpos($bootstrap, 'static function ( string $content, array $block ): string {', $hook);
    $end = strpos($bootstrap, "\n}, 10, 2 );", $start);
    $filters[$name] = eval('return ' . substr($bootstrap, $start, $end + 2 - $start) . ';');
}
function is_front_page(): bool { global $route; return '/' === $route; }
function is_singular(): bool { return true; }
function get_queried_object_id(): int { return 42; }
function apply_filters($hook, $value) { return $value; }
function get_post_type($id): string { return 'page'; }
function get_page_uri($id): string { global $route; return trim($route, '/'); }
$link = static fn(array $attrs): string => '<a class="wp-block-navigation-item__content" href="' . $attrs['url'] . '">' . $attrs['label'] . '</a>';
$render = static fn(string $name, array $attrs, string $children = ''): string => $filters[$name]('<li class="wp-block-navigation-item">' . $link($attrs) . $children . '</li>', array('attrs' => $attrs));
foreach (array('/nosotros', '/team', '/') as $route) {
    $child = $render('navigation-link', $leaf);
    $output = $render('navigation-submenu', $parent, '<ul>' . $child . '</ul>');
    $dom = new DOMDocument();
    @$dom->loadHTML($output);
    $xpath = new DOMXPath($dom);
    $items = $xpath->query('//li');
    $anchors = $xpath->query('//a');
    $assert(('/nosotros' === $route ? 'page' : '') === $anchors[0]->getAttribute('aria-current'), 'Only the parent route marks the parent anchor current, even when a child is current.');
    $assert(in_array('current-menu-item', explode(' ', $items[0]->getAttribute('class')), true) === ('/nosotros' === $route), 'Only the parent route marks the parent item current.');
    $assert(('page' === $anchors[1]->getAttribute('aria-current')) === ('/team' === $route), 'Leaf route matching is unchanged.');
    $assert($output === $filters['navigation-submenu']($output, array('attrs' => $parent)), 'Applying the parent filter twice is idempotent.');
}
$coreCurrent = '<li class="wp-block-navigation-item current-menu-item"><a class="wp-block-navigation-item__content" aria-current="page">Nosotros</a></li>';
$assert($coreCurrent === $filters['navigation-submenu']($coreCurrent, array('attrs' => $parent)), 'Core-provided current state is preserved without duplication.');
// URL resolution remains exact and internal-only for both native blocks.
$route = '/nosotros';
foreach (array('/nosotros-extra', '/team', '//example.org/nosotros', 'https://example.org/nosotros') as $url) {
    $attrs = array_replace($parent, array('url' => $url));
    $assert(!str_contains($render('navigation-submenu', $attrs), 'aria-current') && !str_contains($render('navigation-submenu', $attrs), 'current-menu-item'), 'Unrelated and external URLs never mark the parent current.');
}
echo "navigation-submenu-current-parent: ok\n";
