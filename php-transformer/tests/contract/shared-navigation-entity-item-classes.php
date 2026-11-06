<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// Two pages share a header menu, but each names its items with a class its own
// stylesheet defines. On Home, `k2` is a 30%-wide content column; on About it
// is the item style. The shared entity must not carry About's `k2` onto Home.
$page = static fn (string $title, string $css, string $item, string $main): string => '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $title . '</title><style>' . $css
    . '.x .menu{margin:0;padding:0;list-style:none;white-space:nowrap}</style></head><body><div class="x"><header><p>Site Title</p><nav><ul class="menu">'
    . implode('', array_map(static fn (string $href, string $label): string => '<li class="' . $item . '"><a href="' . $href . '">' . $label . '</a></li>', array('/', '/about/', '/partners/', '/store/'), array('Home', 'About', 'Partners', 'Store')))
    . '</ul></nav></header><main>' . $main . '</main></div></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Home', '.x .k1{display:inline-block;padding-left:32px}.x .k2{width:30%}', 'k1', '<div class="k2"><p>Home body.</p></div>'),
    'about/index.html' => $page('About', '.x .k2{display:inline-block;padding-left:32px}', 'k2', '<p>About body.</p>'),
)))->toArray()['source_reports']['wordpress_site_plan'];

$menus = array_values(array_filter($plan['menus'], static fn (array $menu): bool => is_string($menu['token'] ?? null)));
$assert(1 === count($menus) && 4 === $menus[0]['items'], 'Both pages reference one editable navigation entity.');
$items = array_filter((new Runtime())->parseBlocks($menus[0]['block_markup']), static fn (array $block): bool => 'core/navigation-link' === $block['blockName']);
foreach ($items as $item) {
    $classes = preg_split('/\s+/', (string) ($item['attrs']['className'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    $assert(array() === array_intersect(array('k1', 'k2'), $classes), 'Shared items carry no page-scoped source class: ' . ($item['attrs']['className'] ?? ''));
}

$css = implode("\n", array_map(static fn (array $write): string => (string) $write['payload']['data'], array_filter($plan['writes'], static fn (array $write): bool => str_ends_with($write['target_path'], '.css'))));
$assert(str_contains($css, '.x .k2{width:30%}'), 'Home keeps its own content-column rule.');
// Both item classes mean the same declarations, so the two headers are one
// design and may render the menu from one shared header part.
$parts = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => str_contains($part['canonical_block_markup'], 'wp:navigation')));
foreach ($plan['pages'] as $page) {
    $assert(str_contains($page['canonical_block_markup'], 'wp:navigation') || array() !== $parts, $page['source_path'] . ' renders the menu.');
}
$renderers = array_merge(array_values(array_filter($plan['pages'], static fn (array $page): bool => str_contains($page['canonical_block_markup'], 'wp:navigation'))), $parts);
foreach ($renderers as $document) {
    $assert(1 === preg_match('/"className":"[^"]*(blocks-engine-navigation-instance-[a-f0-9]{12}-\d+)[^"]*".*?"ref":"\{\{wordpress-site-plan:navigation:' . $menus[0]['token'] . '\}\}"/', $document['canonical_block_markup'], $scope), $document['source_path'] . ' references the shared entity through its own instance scope.');
    $assert(1 === preg_match('/\.wp-block-navigation\.' . $scope[1] . '\\)? \.wp-block-navigation__container>\.wp-block-navigation-item(?!:nth-child)[^{]*\{display:inline-block;padding-left:32px\}/', $css), $document['source_path'] . ' keeps its own item presentation through its instance scope.');
}

echo "Shared navigation entity item classes contract passed.\n";
