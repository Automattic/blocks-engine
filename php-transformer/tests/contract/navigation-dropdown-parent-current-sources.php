<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// A navbar whose URL-less dropdown parent the server marks `.active` on the
// parent's own landing page (not one of its links) and on a child page.
$template = (string) file_get_contents(dirname(__DIR__) . '/fixtures/navigation-dropdown-parent-current-sources/page.template.html');
$rest = array('{{home}}' => '', '{{about}}' => '', '{{purpose}}' => '', '{{contact}}' => '');
$routes = array(
    'index.html' => array('{{title}}' => 'Home', '{{home}}' => ' active') + $rest,
    'about/index.html' => array('{{title}}' => 'About', '{{about}}' => ' active') + $rest,
    'about/purpose/index.html' => array('{{title}}' => 'Purpose', '{{about}}' => ' active', '{{purpose}}' => ' active') + $rest,
    'contact/index.html' => array('{{title}}' => 'Contact', '{{contact}}' => ' active') + $rest,
);
$files = array_map(static fn(array $route): string => strtr($template, $route), $routes);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];

$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
$assert(1 === count($headers) && count($routes) === count($headers[0]['provenance']['sources'] ?? array()), 'All routes share one header part.');
$assert(1 === count($plan['menus']), 'All routes share one menu.');
$menu = (string) $plan['menus'][0]['block_markup'];
preg_match('/<!-- wp:navigation-submenu (\{.*?\}) -->/', $menu, $parentMatch);
$parent = json_decode($parentMatch[1] ?? '', true);
$assert(is_array($parent) && 'About' === ($parent['label'] ?? null), 'The shared menu has the dropdown parent.');
$assert(in_array(trim((string) ($parent['url'] ?? '')), array('', '#'), true), 'The dropdown toggle has no route URL.');
$links = array();
preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/', $menu, $linkMatches);
foreach ($linkMatches[1] as $json) {
    $attrs = json_decode($json, true);
    if (is_array($attrs)) $links[(string) $attrs['label']] = $attrs;
}

// The source's parent-active rule, keyed on the runtime current item.
$css = '';
foreach ($plan['assets'] as $asset) if ('css' === ($asset['kind'] ?? '')) $css .= (string) ($asset['content'] ?? '');
$assert(1 === preg_match('/\.wp-block-navigation__container>\.wp-block-navigation-item\.current-menu-item>\.wp-block-navigation-item__content[^{}]*\{[^}]*color:[^};]*rgba\(0,0,0,\.9\)/', $css), 'The source active colour is projected on the runtime current item.');

// The generated runtime marks items from the served route.
$bootstrap = '';
foreach ($plan['writes'] as $write) if ('functions.php' === ($write['path'] ?? $write['target_path'] ?? '')) $bootstrap = (string) $write['payload']['data'];
$filter = static function (string $block) use ($bootstrap, $assert): Closure {
    $hook = strpos($bootstrap, "add_filter( 'render_block_core/{$block}', static function");
    $assert(false !== $hook, 'Generated theme registers the ' . $block . ' current-state filter.');
    $start = strpos($bootstrap, 'static function', $hook);
    $end = strpos($bootstrap, "\n}, 10, 2 );", $start);
    return eval('return ' . substr($bootstrap, $start, $end + 2 - $start) . ';');
};
$submenu = $filter('navigation-submenu');
$link = $filter('navigation-link');
$served = '/';
function is_front_page(): bool { global $served; return '/' === $served; }
function is_singular(): bool { return true; }
function get_queried_object_id(): int { return 42; }
function apply_filters(string $hook, mixed $value): mixed { return $value; }
function get_post_type(int $id): string { return 'page'; }
function get_page_uri(int $id): string { global $served; return trim($served, '/'); }

$toggle = '<li class="wp-block-navigation-item has-child wp-block-navigation-submenu"><a class="wp-block-navigation-item__content" href="#">About</a><ul class="wp-block-navigation__submenu-container">'
    . '<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="/about/purpose/">Purpose</a></li></ul></li>';
$anchor = static fn(string $url, string $label): string => '<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="' . $url . '">' . $label . '</a></li>';
$isCurrentItem = static fn(string $html): bool => 1 === preg_match('/^<li\b[^>]*\bclass="[^"]*\bcurrent-menu-item\b/', $html);
foreach (array(
    // served route => parent current, Purpose current, Home current
    '/' => array(false, false, true),
    '/about' => array(true, false, false),
    '/about/purpose' => array(true, true, false),
    '/contact' => array(false, false, false),
) as $route => [$parentCurrent, $purposeCurrent, $homeCurrent]) {
    $GLOBALS['served'] = $route;
    $out = $submenu($toggle, array('attrs' => $parent));
    $assert($isCurrentItem($out) === $parentCurrent, 'Dropdown parent current state on ' . $route . ': ' . $out);
    $assert(!str_contains(substr($out, 0, (int) strpos($out, '<ul')), 'aria-current'), 'A URL-less parent is a current section, not a link to the page, on ' . $route);
    $assert($out === $submenu($out, array('attrs' => $parent)), 'Marking the parent is idempotent on ' . $route);
    $purpose = $link($anchor('/about/purpose/', 'Purpose'), array('attrs' => $links['Purpose']));
    $assert(str_contains($purpose, 'aria-current="page"') === $purposeCurrent, 'Child link current state on ' . $route);
    $home = $link($anchor('/', 'Home'), array('attrs' => $links['Home']));
    $assert(str_contains($home, 'aria-current="page"') === $homeCurrent, 'Home link current state on ' . $route);
}
echo "navigation-dropdown-parent-current-sources: ok\n";
