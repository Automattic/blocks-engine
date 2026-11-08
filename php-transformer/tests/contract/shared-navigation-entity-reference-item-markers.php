<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// #4903: a page-level menu repeats the header's links, so both reference one
// navigation entity, but only the page menu's links are padded. That item rule
// binds to the page menu's own anchor marker, which the shared entity must
// carry for the page reference to render its padding.
$menu = static fn (string $class): string => '<nav class="' . $class . '"><ul class="menu">'
    . implode('', array_map(static fn (string $href, string $label): string => '<li><a class="link" href="' . $href . '">' . $label . '</a></li>', array('/', '/about/', '/contact/'), array('Home', 'About', 'Contact')))
    . '</ul></nav>';
$page = static fn (string $title, string $main): string => '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $title . '</title><style>'
    . '.menu{display:flex;margin:0;padding:0;list-style:none}.site .link{color:#000}#page-menu .page .link{padding:10px;margin:4px}</style></head><body><header>' . $menu('site') . '</header><main>' . $main . '</main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Home', '<p>Home body.</p>'),
    'about/index.html' => $page('About', '<div id="page-menu">' . $menu('page') . '</div><p>About body.</p>'),
)))->toArray()['source_reports']['wordpress_site_plan'];

$menus = array_values(array_filter($plan['menus'], static fn (array $menu): bool => is_string($menu['token'] ?? null)));
$assert(1 === count($menus), 'The header and page menus reference one navigation entity.');
$css = implode("\n", array_map(static fn (array $write): string => (string) $write['payload']['data'], array_filter($plan['writes'], static fn (array $write): bool => str_ends_with($write['target_path'], '.css'))));
preg_match_all('/[^{}]*\{[^}]*padding:10px[^}]*\}/', $css, $rules);
$anchors = array();
foreach ($rules[0] as $rule) {
    if (preg_match_all('/blocks-engine-navigation-(?:anchor|item|box)-[a-f0-9]{12}-\d+/', $rule, $markers)) array_push($anchors, ...$markers[0]);
}
$assert(array() !== $anchors, 'The page menu link padding is projected onto navigation subject markers.');
foreach (array_unique($anchors) as $anchor) {
    $assert(str_contains($menus[0]['block_markup'], $anchor), "The shared entity carries the page menu's subject marker {$anchor}.");
}
$orphans = array_filter($plan['diagnostics'], static fn (array $diagnostic): bool => 'wordpress_site_plan_orphaned_navigation_instance' === ($diagnostic['code'] ?? ''));
$assert(array() === $orphans, 'No projected navigation instance is orphaned.');

echo "Shared navigation entity reference item markers contract passed.\n";
