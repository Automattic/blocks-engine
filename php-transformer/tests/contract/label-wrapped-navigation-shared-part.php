<?php
declare(strict_types=1);

// Some builders wrap each menu link in a `<label>` (the dropdown toggle
// target): `nav > ul > li > label > div > a`. The label holds the link without
// changing its destination, so routes with the same menu share one
// wp_navigation entity and the header extracts as a shared template part.
// The label keeps its paint as a projected wrapper box.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

$page = static function (string $title, string $current, bool $activeModifier): string {
    $item = static function (string $href, string $label) use ($current, $activeModifier): string {
        $wrapper = 'item-content-wrapper' . ($activeModifier && $href === $current ? ' item-content-wrapper--active' : '');
        return '<li class="item"><label class="item__label"><div class="' . $wrapper . '"><a class="item-content" href="' . $href . '">' . $label . '</a></div></label></li>';
    };
    return '<!DOCTYPE html><html><head><title>' . $title . '</title><style>.item__label{display:block;padding:6px 10px;background:#eee}</style></head><body>'
        . '<div><main class="page"><div class="top-blocks">'
        . '<header class="block-header"><a href="/">Brand</a><nav class="block-header__nav"><ul>' . $item('/', 'Home') . $item('/about/', 'About') . '</ul></nav></header>'
        . '</div><div class="page__blocks"><section id="hero" class="block"><h1>' . $title . ' page</h1><p>' . $title . ' body.</p></section></div>'
        . '</main></div></body></html>';
};

foreach (array('identical menus' => false, 'per-route active modifier' => true) as $case => $activeModifier) {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => array(
        array('path' => 'website/index.html', 'content' => $page('Home', '/', $activeModifier)),
        array('path' => 'website/about/index.html', 'content' => $page('About', '/about/', $activeModifier)),
    )))->toWordPressSitePlanView()['wordpress_site_plan'];

    $menus = $plan['menus'] ?? array();
    $assert(1 === count($menus), $case . ': label-wrapped menus share one wp_navigation entity, got ' . count($menus));
    $menuMarkup = (string) ($menus[0]['block_markup'] ?? '');
    $token = (string) ($menus[0]['token'] ?? '');
    $assert('' !== $token && 2 === ($menus[0]['items'] ?? null), $case . ': the shared entity holds both items.');
    $assert(str_contains($menuMarkup, '"label":"Home"') && str_contains($menuMarkup, '"label":"About"'), $case . ': the entity keeps the source labels.');
    $assert(!str_contains($menuMarkup, 'item-content-wrapper--active'), $case . ': route state does not enter the shared entity.');
    $assert(str_contains($menuMarkup, '"tag":"span"') && str_contains($menuMarkup, 'item__label'), $case . ': the label keeps its presentation as a projected wrapper box.');

    $header = array_values(array_filter($plan['template_parts'] ?? array(), static fn (array $part): bool => 'header' === ($part['slug'] ?? null)))[0] ?? null;
    $assert(is_array($header), $case . ': the header extracts as a shared template part.');
    $assert(str_contains((string) $header['canonical_block_markup'], WordPressSitePlan::NAVIGATION_TOKEN_PREFIX . $token . '}}'), $case . ': the header part references the shared navigation.');

    // The source header sits inside `<main>`, so the part reference stays in
    // that frame: each route renders it through its template or page content.
    $templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
    $partReference = '/<!--\s*wp:template-part\s+\{[^}]*"slug":"header"/';
    foreach ($plan['pages'] as $row) {
        $content = (string) ($row['canonical_block_markup'] ?? '');
        $template = (string) ($templates[!empty($row['entrypoint']) ? 'front-page' : 'page-' . $row['slug']] ?? $templates['page'] ?? '');
        $assert(str_contains($template, 'wp:post-content') || str_contains($template, '"slug":"header"'), $case . ': ' . $row['source_path'] . ' renders through a template.');
        $assert(1 === preg_match_all($partReference, $template . $content), $case . ': ' . $row['source_path'] . ' references the header part once.');
        $assert(!str_contains($content, 'wp:navigation'), $case . ': no navigation stays in ' . $row['source_path'] . ' content.');
    }
}

echo "Label-wrapped navigation shared part contract passed.\n";
