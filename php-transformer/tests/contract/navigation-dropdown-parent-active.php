<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$dir = dirname(__DIR__) . '/fixtures/navigation-dropdown-parent-active';
$files = array();
foreach (array('index.html', 'formaal.html', 'historie.html', 'site.css') as $name) $files[$name] = (string) file_get_contents($dir . '/' . $name);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$bootstrap = '';
foreach ($plan['writes'] as $write) {
    if ('functions.php' === ($write['path'] ?? $write['target_path'] ?? '')) $bootstrap = $write['payload']['data'];
}
$menu = $plan['menus'][0]['block_markup'] ?? '';
preg_match('/<!-- wp:navigation-submenu (\{.*?\}) -->/', $menu, $parentMatch);
preg_match('/<!-- wp:navigation-link (\{[^<>]*?"label":"Formaal"[^<>]*?\}) \/-->/', $menu, $leafMatch);
$parent = json_decode($parentMatch[1] ?? '', true);
$leaf = json_decode($leafMatch[1] ?? '', true);
$assert(is_array($parent) && is_array($leaf), 'The shared menu has a submenu parent and a child link.');
$assert('' === trim((string) ($parent['url'] ?? '')) || '#' === $parent['url'], 'The dropdown toggle has no route URL.');

// The source parent-active colour is projected keyed on Core's ancestor class.
$css = '';
foreach ($plan['assets'] as $asset) if ('css' === ($asset['kind'] ?? '')) $css .= (string) ($asset['content'] ?? '');
$assert(1 === preg_match('/current-menu-ancestor[^{}]*\{[^}]*color:[^}]*rgba\(0,0,0,\.9\)/', $css), 'Parent-active colour is projected on current-menu-ancestor.');

// Runtime: isolate the generated submenu filter.
$hook = strpos($bootstrap, "add_filter( 'render_block_core/navigation-submenu',");
$assert(false !== $hook, 'Generated theme registers the submenu current-state filter.');
$start = strpos($bootstrap, 'static function ( string $content, array $block ): string {', $hook);
$end = strpos($bootstrap, "\n}, 10, 2 );", $start);
$filter = eval('return ' . substr($bootstrap, $start, $end + 2 - $start) . ';');
function is_front_page(): bool { global $route; return '/' === $route; }
function is_singular(): bool { return true; }
function get_queried_object_id(): int { return 42; }
function apply_filters($hook, $value) { return $value; }
function get_post_type($id): string { return 'page'; }
function get_page_uri($id): string { global $route; return trim($route, '/'); }
$toggle = '<button class="wp-block-navigation-item__content">Om os</button>';
$childLink = '<li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content" href="' . $leaf['url'] . '"' . '>Formaal</a></li>';
$input = '<li class="wp-block-navigation-item has-child">' . $toggle . '<ul>' . $childLink . '</ul></li>';
$hasAncestor = static fn (string $html): bool => 1 === preg_match('/^<li\b[^>]*\bclass="[^"]*\bcurrent-menu-ancestor\b/', $html);
foreach (array('/formaal' => true, '/' => false, '/historie' => false) as $route => $expected) {
    $out = $filter($input, array('attrs' => $parent, 'innerBlocks' => array(array('blockName' => 'core/navigation-link', 'attrs' => $leaf))));
    $assert($hasAncestor($out) === $expected, 'Parent ancestor state on ' . $route);
    $assert(!str_contains($out, 'aria-current'), 'A URL-less parent is never itself current.');
    $assert($out === $filter($out, array('attrs' => $parent, 'innerBlocks' => array(array('blockName' => 'core/navigation-link', 'attrs' => $leaf)))), 'Idempotent.');
}
echo "navigation-dropdown-parent-active: ok\n";
