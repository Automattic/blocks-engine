<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// A frame fallback lends the generic frame's stylesheets to routes that own
// none. A route with stylesheets of its own must not load them as well: a
// before-author fallback sits ahead of the route sequences in the cascade, so
// it must read the request's route flag before those sequences enqueue.
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$pages = array(
    array('source_path' => 'index.html', 'post_type' => 'page', 'route' => array('path' => '/'), 'reconciliation_identity' => 'home', 'canonical_block_markup' => '', 'metadata' => array()),
    array('source_path' => 'about.html', 'post_type' => 'page', 'route' => array('path' => '/about'), 'reconciliation_identity' => 'about', 'canonical_block_markup' => '', 'metadata' => array()),
);
$instances = static fn(int $order): array => array_map(static fn(array $page): array => array('source_path' => $page['source_path'], 'order' => $order, 'media' => ''), $pages);
$assets = array(
    // The frame page's support sheet, lent before author CSS.
    array('kind' => 'css', 'source_path' => 'frame-support.css', 'target_path' => 'assets/frame-support.css', 'content_hash' => hash('sha256', 'frame-support'), 'scopes' => array(array('kind' => 'page', 'source_path' => 'about.html', 'route_path' => 'about', 'reconciliation_identity' => 'about', 'front_page' => false)), 'stylesheet_placement' => 'before-author', 'frame_fallback' => true),
    // A site-wide support sheet that is also the frame's: it already loads everywhere.
    array('kind' => 'css', 'source_path' => 'site-support.css', 'target_path' => 'assets/site-support.css', 'content_hash' => hash('sha256', 'site-support'), 'scopes' => array(array('kind' => 'global'), array('kind' => 'page', 'source_path' => 'about.html', 'route_path' => 'about', 'reconciliation_identity' => 'about', 'front_page' => false)), 'stylesheet_placement' => 'before-author', 'frame_fallback' => true),
    // Each document's own author sheet.
    array('kind' => 'css', 'source_path' => 'author.css', 'target_path' => 'assets/author.css', 'content_hash' => hash('sha256', 'author'), 'scopes' => array(array('kind' => 'global')), 'stylesheet_instances' => $instances(0)),
);
$bootstrap = ThemeBootstrap::functionsPhp($assets, array(), array(), array(), array(), $pages);
$assert(1 === preg_match("/add_action\( 'wp_enqueue_scripts', static function \(\): void \{(.*?)\n\}, 1 \);/s", $bootstrap, $match), 'The bootstrap enqueues stylesheets.');
$enqueue = eval('return static function (): void {' . $match[1] . '};');

function is_singular(): bool { return 0 !== $GLOBALS['route_id']; }
function is_front_page(): bool { return 1 === $GLOBALS['route_id']; }
function get_queried_object_id(): int { return $GLOBALS['route_id']; }
function apply_filters(string $name, mixed $value): mixed { return $value; }
function get_post_meta(int $id, string $key, bool $single): string { return array(1 => 'home', 2 => 'about')[$id] ?? ''; }
function get_post_type(int $id): string { return 'page'; }
function get_page_uri(int $id): string { return ''; }
function get_theme_file_uri(string $path): string { return $path; }
function wp_enqueue_style(string $handle, string $uri, array $deps, mixed $version, string $media = 'all'): void { $GLOBALS['enqueued'][] = $uri; }

$load = static function (int $route) use ($enqueue): array {
    $GLOBALS['route_id'] = $route;
    $GLOBALS['enqueued'] = array();
    $enqueue();
    return $GLOBALS['enqueued'];
};

foreach (array(1 => 'front page', 2 => 'about page') as $route => $label) {
    $loaded = $load($route);
    // The about page authored the frame, so its support sheet is its own.
    $assert((2 === $route ? 1 : 0) === count(array_keys($loaded, 'assets/frame-support.css', true)), "The {$label} loads the frame support sheet only as its own, never as a borrowed fallback: " . json_encode($loaded));
    $assert(1 === count(array_keys($loaded, 'assets/site-support.css', true)), "The {$label} loads the site-wide support sheet once: " . json_encode($loaded));
    $assert(in_array('assets/author.css', $loaded, true), "The {$label} loads its own author sheet.");
}
// A native view no source document owns borrows the frame's stylesheets.
$native = $load(0);
$assert(array('assets/frame-support.css', 'assets/site-support.css', 'assets/author.css') === $native, 'A native view loads the frame fallback, then the site-wide sheets, each once: ' . json_encode($native));

echo "frame-fallback-route-flag contract passed\n";
