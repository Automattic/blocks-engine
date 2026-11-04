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

// The reduced regression page: a trail per route whose items walk the route's
// ancestors and end at the route itself, styled by the author's own rules.
$fixture = dirname(__DIR__) . '/fixtures/breadcrumb-trail-site/';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => file_get_contents($fixture . 'index.html'),
    'about/index.html' => file_get_contents($fixture . 'about/index.html'),
    'news/index.html' => file_get_contents($fixture . 'news/index.html'),
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$aboutMarkup = (string) ($document($plan, 'about/index.html')['canonical_block_markup'] ?? '');
$newsMarkup = (string) ($document($plan, 'news/index.html')['canonical_block_markup'] ?? '');
$assert(1 === substr_count($aboutMarkup, '<!-- wp:breadcrumbs ') && 1 === substr_count($newsMarkup, '<!-- wp:breadcrumbs '), 'Each carrying route renders its trail as one core/breadcrumbs block.');

$breadcrumbs = (new Runtime())->parseBlocks($aboutMarkup);
$breadcrumbs = array_values(array_filter($breadcrumbs, static fn(array $block): bool => 'core/breadcrumbs' === ($block['blockName'] ?? '')));
$attributes = $breadcrumbs[0]['attrs'] ?? array();
$assert('>' === ($attributes['separator'] ?? ''), 'The separator the source paints between items maps to the block attribute.');
$assert('#666' === ($attributes['style']['color']['link'] ?? ''), 'The trail link color maps to the block link color.');
$assert('#f3f1ec' === ($attributes['style']['color']['background'] ?? ''), 'The trail surface maps to the block background.');
$assert('12px' === ($attributes['style']['spacing']['padding']['top'] ?? '') && '16px' === ($attributes['style']['spacing']['padding']['right'] ?? ''), 'The trail box padding maps to the block padding.');
$assert(!isset($attributes['ref']) && !isset($attributes['showHomeItem']), 'The home item and the entity reference stay on core defaults, with no per-route identity.');
$newsBlocks = array_values(array_filter((new Runtime())->parseBlocks($newsMarkup), static fn(array $block): bool => 'core/breadcrumbs' === ($block['blockName'] ?? '')));
$withoutTrail = static function (array $attrs): array { unset($attrs["metadata"]); return $attrs; };
$assert($withoutTrail($attributes) === $withoutTrail($newsBlocks[0]["attrs"] ?? array()), 'A second route with the same trail shape renders the same hierarchy-driven block.');

$assert(0 === count($entityMenus($plan)), 'No wp_navigation entity is created for breadcrumb trails.');
$allMarkup = implode("\n", array_merge(
    array_column($plan['pages'] ?? array(), 'canonical_block_markup'),
    array_column($plan['template_parts'] ?? array(), 'canonical_block_markup'),
    array_column($plan['templates'] ?? array(), 'canonical_block_markup')
));
$assert(! str_contains($allMarkup, 'wp:navigation'), 'The trails stay off the navigation path entirely: no core/navigation placement carries them.');

// The site that motivated the issue wraps the trail's list in layout: a
// container div between the landmark and the list, and a section around the
// landmark. The wrappers stay ordinary groups while the list itself still
// becomes the one hierarchy-driven block — no wp_navigation entity, and the
// same attribute mapping.
$crumbStyle = '<style>.breadcrumb{display:flex;list-style:none;margin:0;padding:12px 16px;background:#f3f1ec}.breadcrumb-item+.breadcrumb-item::before{content:">";padding:0 8px;color:#888}.breadcrumb a{color:#666;text-decoration:none}</style>';
$crumbItem = static fn (string $href): string =>
    '<li class="breadcrumb-item"><a href="/">Home</a></li>'
    . '<li class="breadcrumb-item active"><a href="' . $href . '">Studio</a></li>';
$wrappedPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1></main>',
    // The real-site shape: a section wraps the landmark, and the landmark
    // wraps a container div around the list.
    'studio/work/index.html' => $crumbStyle
        . '<section class="c-breadcrumb"><nav aria-label="breadcrumb"><div class="container">'
        . '<ol class="breadcrumb">' . $crumbItem('/studio/work/') . '</ol>'
        . '</div></nav></section><main><h1>Studio</h1></main>',
    // The reduced variant: only the container div sits inside the landmark.
    'studio/notes/index.html' => $crumbStyle
        . '<nav aria-label="breadcrumb"><div class="container">'
        . '<ol class="breadcrumb">' . $crumbItem('/studio/notes/') . '</ol>'
        . '</div></nav><main><h1>Notes</h1></main>',
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($wrappedPlan);
$assert(0 === count($entityMenus($wrappedPlan)), 'A wrapped trail creates no wp_navigation entity either.');
foreach (array('studio/work/index.html', 'studio/notes/index.html') as $wrappedPath) {
    $wrappedMarkup = (string) ($document($wrappedPlan, $wrappedPath)['canonical_block_markup'] ?? '');
    $assert(1 === substr_count($wrappedMarkup, '<!-- wp:breadcrumbs '), 'The wrapped trail on ' . $wrappedPath . ' renders as one core/breadcrumbs block.');
    $assert(! str_contains($wrappedMarkup, 'wp:navigation'), 'The wrapped trail on ' . $wrappedPath . ' stays off the navigation path.');
    $assert(! str_contains($wrappedMarkup, 'wp:list'), 'The wrapped trail on ' . $wrappedPath . ' does not fall back to a plain list.');
}
$collectBlocks = static function (array $blocks, string $name) use (&$collectBlocks): array {
    $found = array();
    foreach ($blocks as $block) {
        if ($name === ($block['blockName'] ?? '')) $found[] = $block;
        $found = array_merge($found, $collectBlocks($block['innerBlocks'] ?? array(), $name));
    }
    return $found;
};
$wrappedBlock = $collectBlocks((new Runtime())->parseBlocks((string) ($document($wrappedPlan, 'studio/work/index.html')['canonical_block_markup'] ?? '')), 'core/breadcrumbs');
$assert('>' === ($wrappedBlock[0]['attrs']['separator'] ?? '') && '#f3f1ec' === ($wrappedBlock[0]['attrs']['style']['color']['background'] ?? ''), 'The wrapped trail keeps the separator and surface mapping.');

// The guard survives wrappers: a wrapped trail whose last item names another
// route stays on the menu path, landmark and all.
$wrappedMismatchPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1></main>',
    'studio/other/index.html' =>
        '<section class="c-breadcrumb"><nav aria-label="breadcrumb"><div class="container">'
        . '<ol class="breadcrumb">' . $crumbItem('/elsewhere/') . '</ol>'
        . '</div></nav></section><main><h1>Other</h1></main>',
)))->toArray()['source_reports']['wordpress_site_plan'];
$wrappedMismatchMarkup = (string) ($document($wrappedMismatchPlan, 'studio/other/index.html')['canonical_block_markup'] ?? '');
$assert(! str_contains($wrappedMismatchMarkup, 'wp:breadcrumbs') && str_contains($wrappedMismatchMarkup, '<!-- wp:navigation '), 'A wrapped trail that does not end at its own route stays a core/navigation placement.');
$assert(1 === count($entityMenus($wrappedMismatchPlan)), 'That wrapped non-trail still becomes one navigation entity, as before.');

// A breadcrumb-shaped trail that does not match the route hierarchy — its
// last item names another route — keeps today's menu conversion.
$mismatch = static fn (string $lastHref): string =>
    '<nav aria-label="breadcrumb"><ol class="breadcrumb">'
    . '<li class="breadcrumb-item"><a href="/">Home</a></li>'
    . '<li class="breadcrumb-item"><a href="' . $lastHref . '">Contact</a></li>'
    . '</ol></nav><main><h1>About</h1></main>';
$mismatchPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1></main>',
    'about.html' => $mismatch('/contact/'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$aboutPage = (string) ($document($mismatchPlan, 'about.html')['canonical_block_markup'] ?? '');
$assert(! str_contains($aboutPage, 'wp:breadcrumbs') && str_contains($aboutPage, '<!-- wp:navigation '), 'A trail that does not end at its own route stays a core/navigation placement.');
$assert(1 === count($entityMenus($mismatchPlan)), 'That unrecognized trail still becomes one navigation entity, as before.');

// A fragment names a section rather than the route, so the trail stays a menu.
$fragmentPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1></main>',
    'about.html' => str_replace('href="/contact/"', 'href="/about.html#team"', $mismatch('/contact/')),
)))->toArray()['source_reports']['wordpress_site_plan'];
$fragmentPage = (string) ($document($fragmentPlan, 'about.html')['canonical_block_markup'] ?? '');
$assert(! str_contains($fragmentPage, 'wp:breadcrumbs') && str_contains($fragmentPage, '<!-- wp:navigation '), 'A trail ending at a fragment stays on the menu path.');

echo "breadcrumb-trail-navigation: ok\n";
