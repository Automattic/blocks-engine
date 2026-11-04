<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };
$entityMenus = static function (array $plan): array {
    return array_values(array_filter($plan['menus'] ?? array(), static fn(array $menu): bool => is_string($menu['token'] ?? null) && is_string($menu['block_markup'] ?? null)));
};
$document = static function (array $plan, string $sourcePath): array {
    foreach (array_merge($plan['pages'] ?? array(), $plan['template_parts'] ?? array()) as $row) {
        if ($sourcePath === ($row['source_path'] ?? null)) return $row;
    }
    return array();
};
$collectBlocks = static function (array $blocks, string $name) use (&$collectBlocks): array {
    $found = array();
    foreach ($blocks as $block) {
        if ($name === ($block['blockName'] ?? '')) $found[] = $block;
        $found = array_merge($found, $collectBlocks($block['innerBlocks'] ?? array(), $name));
    }
    return $found;
};
$breadcrumbsIn = static function (string $markup) use ($assert, $collectBlocks): array {
    $blocks = $collectBlocks((new Runtime())->parseBlocks($markup), 'core/breadcrumbs');
    $assert(1 === count($blocks), 'Exactly one core/breadcrumbs block carries the trail.');
    return $blocks;
};

// The issue's reduced page: every trail link addresses its route as
// `<path>/index.html`, the directory-index form static captures serve. Those
// hrefs name the same routes `/` and `/about/` name, so each carrying route
// still renders its own trail as the one hierarchy-driven block instead of a
// navigation menu entity of its own.
$fixture = dirname(__DIR__) . '/fixtures/breadcrumb-trail-directory-index-site/';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => file_get_contents($fixture . 'index.html'),
    'about/index.html' => file_get_contents($fixture . 'about/index.html'),
    'news/index.html' => file_get_contents($fixture . 'news/index.html'),
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$aboutMarkup = (string) ($document($plan, 'about/index.html')['canonical_block_markup'] ?? '');
$newsMarkup = (string) ($document($plan, 'news/index.html')['canonical_block_markup'] ?? '');
foreach (array('about/index.html' => $aboutMarkup, 'news/index.html' => $newsMarkup) as $path => $markup) {
    $assert(1 === substr_count($markup, '<!-- wp:breadcrumbs '), 'The trail on ' . $path . ' renders as one core/breadcrumbs block.');
    $assert(! str_contains($markup, 'wp:navigation'), 'The trail on ' . $path . ' stays off the navigation path.');
}
$assert(0 === count($entityMenus($plan)), 'No wp_navigation entity is created for directory-index-addressed trails.');

$attributes = $breadcrumbsIn($aboutMarkup)[0]['attrs'] ?? array();
$assert('>' === ($attributes['separator'] ?? ''), 'The separator the source paints between items maps to the block attribute.');
$assert('#666' === ($attributes['style']['color']['link'] ?? ''), 'The trail link color maps to the block link color.');
$assert('#f3f1ec' === ($attributes['style']['color']['background'] ?? ''), 'The trail surface maps to the block background.');
$assert(!isset($attributes['ref']) && !isset($attributes['showHomeItem']), 'The home item and the entity reference stay on core defaults: /index.html names the root route, not a /index/ page.');
$assert($attributes === ($breadcrumbsIn($newsMarkup)[0]['attrs'] ?? null), 'A second route with the same trail shape renders the same hierarchy-driven block.');

// A deeper trail built from directory-index links walks one ancestor chain
// across several segment depths.
$deepStyle = '<style>.breadcrumb-item+.breadcrumb-item::before{content:">"}</style>';
$crumb = static fn (array $items): string =>
    '<nav aria-label="breadcrumb"><div class="container"><ol class="breadcrumb">'
    . implode('', array_map(static fn(array $item): string =>
        '<li class="breadcrumb-item' . (true === $item[2] ? ' active' : '') . '"><a href="' . $item[1] . '">' . $item[0] . '</a></li>', $items))
    . '</ol></div></nav>';
$deepPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1></main>',
    'about/index.html' => '<main><h1>About</h1></main>',
    'about/work/index.html' => $deepStyle . $crumb(array(
        array('Home', '/index.html', false),
        array('About', '/about/index.html', false),
        array('Work', '/about/work/index.html', true),
    )) . '<main><h1>Work</h1></main>',
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($deepPlan);
$deepMarkup = (string) ($document($deepPlan, 'about/work/index.html')['canonical_block_markup'] ?? '');
$assert(1 === substr_count($deepMarkup, '<!-- wp:breadcrumbs '), 'A three-deep directory-index trail renders as one core/breadcrumbs block.');
$assert(0 === count($entityMenus($deepPlan)), 'A deeper directory-index trail creates no wp_navigation entity.');
$deepAttributes = $breadcrumbsIn($deepMarkup)[0]['attrs'] ?? array();
$assert('>' === ($deepAttributes['separator'] ?? '') && !isset($deepAttributes['ref']) && !isset($deepAttributes['showHomeItem']), 'The deeper trail keeps the separator and the core home-item default.');

// The guard survives directory-index addressing: a trail whose last item
// names another route stays on the menu path.
$mismatchPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1></main>',
    'about/index.html' => $deepStyle . $crumb(array(
        array('Home', '/index.html', false),
        array('Contact', '/contact/index.html', true),
    )) . '<main><h1>About</h1></main>',
)))->toArray()['source_reports']['wordpress_site_plan'];
$mismatchMarkup = (string) ($document($mismatchPlan, 'about/index.html')['canonical_block_markup'] ?? '');
$assert(! str_contains($mismatchMarkup, 'wp:breadcrumbs') && str_contains($mismatchMarkup, '<!-- wp:navigation '), 'A directory-index trail that does not end at its own route stays a core/navigation placement.');
$assert(1 === count($entityMenus($mismatchPlan)), 'That unrecognized trail still becomes one navigation entity, as before.');

echo "breadcrumb-trail-directory-index: ok\n";
