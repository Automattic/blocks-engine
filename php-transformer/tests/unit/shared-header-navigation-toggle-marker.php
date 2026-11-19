<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\SharedChromeStylesheets;

// A shared header keeps the navigation one route compiled, with that route's
// content-addressed toggle marker (a hash, no instance index). The rules the
// marker names arrive in that route's support sheet only, so every other
// route rendering the part showed the expanded menu where the source has a
// collapsed toggle (a Wix header overflowing a 768px viewport). The rules
// follow the part into shared chrome, like indexed generated markers do.
$toggle = 'blocks-engine-native-navigation-toggle-9438c8046c0d';
$host = '.wp-block-navigation.blocks-engine-list-navigation.blocks-engine-native-responsive-navigation.' . $toggle;
$css = '@media (min-width:600px) and (max-width:1023px){' . $host . '>.wp-block-navigation__responsive-container-open{display:flex!important}'
    . $host . ' .wp-block-navigation__responsive-container:not(.is-menu-open){display:none!important}}'
    . '.route-only{color:red}';
$assets = array(array(
    'kind' => 'css', 'source' => 'engine-support', 'content' => $css,
    'source_path' => 'about/index.html.support.css', 'path' => 'assets/css/engine-support-about.css', 'target_path' => 'assets/assets/css/engine-support-about.css',
    'scopes' => array(array('kind' => 'page', 'source_path' => 'about/index.html')),
));
$navClass = 'blocks-engine-list-navigation blocks-engine-native-responsive-navigation ' . $toggle;
$parts = array(array(
    'slug' => 'header',
    'area' => 'header',
    'placement' => array('kind' => 'shared_shell', 'source_paths' => array('about/index.html', 'privacy-policy/index.html')),
    'canonical_block_markup' => '<!-- wp:group {"tagName":"header","className":"site-header"} --><header class="wp-block-group site-header">'
        . '<!-- wp:navigation {"overlayMenu":"mobile","className":"' . $navClass . '"} /--></header><!-- /wp:group -->',
));
$projected = SharedChromeStylesheets::project($assets, $parts);
$shared = array_values(array_filter($projected, static fn(array $asset): bool => str_ends_with((string) ($asset['source_path'] ?? ''), '.shared-chrome')));
if (1 !== count($shared) || !str_contains((string) $shared[0]['content'], $toggle . '>.wp-block-navigation__responsive-container-open')) {
    throw new RuntimeException('The shared header\'s toggle rules must follow it to every route: ' . json_encode(array_column($projected, 'content', 'source_path')));
}
$route = array_values(array_filter($projected, static fn(array $asset): bool => 'about/index.html.support.css' === ($asset['source_path'] ?? null)));
if (1 !== count($route) || str_contains((string) $route[0]['content'], $toggle) || !str_contains((string) $route[0]['content'], '.route-only')) {
    throw new RuntimeException('Only the toggle rules leave the route sheet: ' . json_encode($route));
}
echo "Shared header navigation toggle marker passed\n";
