<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$header = '<header class="site-header"><nav class="menu"><button class="menu-toggle" aria-label="Open Menu" aria-expanded="false" aria-controls="links">Menu</button><ul id="links" class="menu-list"><li class="menu-item"><a class="menu-link" aria-current="page" href="index.html">Home</a></li><li class="menu-item"><a class="menu-link" href="about.html">About</a></li></ul></nav></header>';
$document = static fn(string $name): string => '<html><head><link rel="stylesheet" href="' . $name . '.css"></head><body>' . $header . '<main><div class="site-header"><h1>' . $name . '</h1></div></main><footer class="site-footer"><a href="index.html">Home</a></footer></body></html>';
$css = '.site-header{display:flex;padding:16px}.menu-toggle{display:none}.menu-list{display:flex;list-style:none}.menu .menu-link{display:flex;padding:20px}.site-footer{padding:20px}@media(max-width:600px){.menu-toggle{display:block}.menu-list{display:none}}';
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $document('index'),
        'about.html' => $document('about'),
        'index.css' => array('path' => 'index.css', 'kind' => 'css', 'content' => $css),
        'about.css' => array('path' => 'about.css', 'kind' => 'css', 'content' => $css),
    ),
))->toArray()['source_reports']['wordpress_site_plan'];
$bootstrap = (string) array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'];
$frontend = (string) strstr($bootstrap, "add_action( 'wp_enqueue_scripts'");
$frontend = substr($frontend, 0, (int) strpos($frontend, '}, 1 );') + strlen('}, 1 );'));

// Execute the emitted callback, not a second implementation of its scope rules.
// Every route predicate is false on a WordPress-native 404/search request.
function add_action(string $hook, callable $callback, int $priority): void { $callback(); }
function apply_filters(string $hook, mixed $value): mixed { return $value; }
function is_page(): bool { return false; }
function is_front_page(): bool { return false; }
function is_singular(): bool { return false; }
function is_home(): bool { return false; }
function get_queried_object_id(): int { return 0; }
function get_theme_file_uri(string $path): string { return $path; }
function wp_enqueue_style(string $handle, string $uri, array $dependencies, mixed $version, string $media = 'all'): void { $GLOBALS['native_styles'][] = $uri; }
$GLOBALS['native_styles'] = array();
eval($frontend);
$native = $GLOBALS['native_styles'];
$assert(array() !== $plan['template_parts'], 'The two pages emit shared chrome parts.');
$required = array();
foreach ($plan['pages'] as $page) {
    foreach (Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::documentStylesheets($plan, $page['source_path']) as $style) $required[$style['target_path']] = true;
}
// This fixture has only chrome CSS (including a hook also used in the body).
// Thus every captured resource is part of the chrome closure.
foreach (array_keys($required) as $target) $assert(in_array($target, $native, true), 'Native views load the captured chrome dependency: ' . $target);
foreach (array('engine-support-before-author-', 'engine-support-after-author-', 'wordpress-compat-') as $family) {
    $assert(array() !== array_filter($native, static fn(string $target): bool => str_contains($target, $family)), 'The fixture exercises ' . $family);
}
$assert(array() !== array_filter($native, static fn(string $target): bool => str_ends_with($target, '/index.css') || str_ends_with($target, '/about.css')), 'Route-kept projected author CSS follows chrome onto native views.');
fwrite(STDOUT, "native-view-chrome-stylesheet-closure contract passed\n");
