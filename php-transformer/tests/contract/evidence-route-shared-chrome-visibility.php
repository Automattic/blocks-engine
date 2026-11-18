<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\EvidenceRouteStylesheets;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\SharedChromeStylesheets;

/*
 * A shared header comes from one capture and renders unchanged on every
 * route. Its breakpoint visibility (a `hidden md:flex` desktop nav, an
 * `md:hidden` menu control) must hold on every route that renders it: on
 * evidence routes, whose author sheet retires, and on routes whose engine
 * support sheet did not generate the menu control's marker.
 */
$assert = static function (bool $ok, string $message, string $detail = ''): void {
    if (!$ok) throw new RuntimeException($message . ('' !== $detail ? ': ' . $detail : ''));
    echo "PASS: $message\n";
};
$control = 'blocks-engine-control-0123456789ab-0';
$header = array(
    'area' => 'header', 'slug' => 'header', 'source_path' => 'wordpress-site-plan/shared/header',
    'placement' => array('kind' => 'shared_shell', 'source_paths' => array('index.html', 'about/index.html')),
    'canonical_block_markup' => '<!-- wp:group {"tagName":"header","className":"site-header"} --><header class="wp-block-group site-header">'
        . '<!-- wp:navigation {"className":"hidden md:flex"} /-->'
        . '<!-- wp:buttons {"className":"flex ' . $control . '"} --><div class="wp-block-buttons flex ' . $control . '"><!-- wp:button {"className":"md:hidden"} --><div class="wp-block-button md:hidden"><a class="wp-block-button__link wp-element-button">Menu</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
        . '</header><!-- /wp:group -->',
);
$pages = array(
    array('source_path' => 'index.html', 'post_type' => 'page', 'route' => array('path' => '/'), 'reconciliation_identity' => 'page:index', 'canonical_block_markup' => '<!-- wp:group {"className":"flex"} --><div class="wp-block-group flex"><!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph --></div><!-- /wp:group -->'),
    array('source_path' => 'about/index.html', 'post_type' => 'page', 'route' => array('path' => '/about/'), 'reconciliation_identity' => 'page:about', 'document_metadata' => array('engine_owned_styles' => true), 'canonical_block_markup' => '<!-- wp:paragraph {"className":"prose"} --><p class="prose">About</p><!-- /wp:paragraph -->'),
);

// Evidence routes: the author sheet retires, its rules for the part do not.
$author = array('kind' => 'css', 'source' => 'files', 'path' => 'assets/site.css', 'source_path' => 'assets/site.css', 'target_path' => 'assets/site.css', 'scopes' => array(array('kind' => 'global')),
    'content' => '.hidden{display:none}@media (width>=48rem){.md\:flex{display:flex}.md\:hidden{display:none}}.prose{color:red}.x:not(.hidden){color:blue}');
$assets = EvidenceRouteStylesheets::retire(array($author), $pages, array($header));
$slices = array_values(array_filter($assets, static fn (array $asset): bool => str_contains((string) ($asset['target_path'] ?? ''), 'evidence-chrome-')));
$assert(1 === count($slices), 'the author rules reaching the shared part are kept for evidence routes', (string) json_encode($assets));
$slice = $slices[0];
$css = (string) $slice['content'];
$part = ':where(.wp-block-template-part *):not(:where(.wp-block-template-part .wp-block-post-content *))';
$assert(str_contains($css, '.hidden' . $part . '{display:none}'), 'the hidden nav stays hidden, confined to part content', $css);
$assert(str_contains($css, '.md\:flex' . $part . '{display:flex}') && str_contains($css, '.md\:hidden' . $part . '{display:none}') && str_contains($css, '@media (width>=48rem)'), 'breakpoint visibility keeps its media condition', $css);
$assert(!str_contains($css, 'prose') && !str_contains($css, 'color:blue'), 'rules for route content, or negating a part hook, stay retired', $css);
$assert(array('about/index.html') === array_map(static fn (array $scope): string => (string) ($scope['source_path'] ?? ''), $slice['scopes']), 'the kept rules apply only to evidence routes', (string) json_encode($slice['scopes']));

// A route-scoped engine support sheet styles the deduplicated menu control
// the shared part carries on every route, so its rule lifts to shared chrome,
// even though the control's authored `flex` keeps its author rules with the
// routes (route content uses it too).
$support = array('kind' => 'css', 'source' => 'engine-support', 'path' => 'assets/css/engine-support-after-author-x.css', 'target_path' => 'assets/css/engine-support-after-author-x.css', 'stylesheet_placement' => 'after-author',
    'scopes' => array(array('kind' => 'page', 'source_path' => 'index.html')),
    'content' => '@media (width>=48rem){.' . $control . '.wp-block-buttons:has(>.wp-block-button:only-child){display:none!important}}');
$projected = SharedChromeStylesheets::project(array($support), array($header), $pages);
$chrome = implode('', array_map(static fn (array $asset): string => (string) $asset['content'], array_filter($projected, static fn (array $asset): bool => array('global') === array_map(static fn (array $scope): string => (string) ($scope['kind'] ?? ''), $asset['scopes'] ?? array()))));
$assert(str_contains($chrome, $control) && str_contains($chrome, 'display:none!important'), 'the menu control hides at the breakpoint on every route rendering the part', (string) json_encode($projected));
