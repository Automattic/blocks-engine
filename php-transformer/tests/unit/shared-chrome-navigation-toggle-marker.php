<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DeadElementMarkers;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\SharedChromeStylesheets;

// Routes compile their own copy of one source-equal header; the shared part
// keeps one capture's content-addressed native navigation toggle marker.
// That marker's collapse rules are emitted by the chosen route's stylesheet,
// so they must follow the part to every route adopting it. Otherwise those
// routes render the menu expanded below the collapse boundary.
$toggle = 'blocks-engine-native-navigation-toggle-9438c8046c0d';
$overlay = 'blocks-engine-navigation-overlay-cf09a55b0598';
$host = '.wp-block-navigation.blocks-engine-list-navigation.blocks-engine-native-responsive-navigation.' . $toggle;
$css = '@media(max-width:1023px){' . $host . '>.wp-block-navigation__responsive-container-open{display:flex!important}'
    . $host . ' .wp-block-navigation__responsive-container:not(.is-menu-open){display:none!important}}'
    . '.wp-block-navigation.' . $overlay . ' .wp-block-navigation__responsive-container.is-menu-open{background:#fdfdfd!important}'
    . '.about-intro{color:red}';
$assets = array(array('kind' => 'css', 'content' => $css, 'source_path' => 'about.css', 'path' => 'about.css', 'target_path' => 'assets/about.css', 'scopes' => array('about.html')));
$parts = array(array(
    'slug' => 'header',
    'area' => 'header',
    'placement' => array('kind' => 'shared_shell', 'source_paths' => array('about.html', 'privacy-policy.html')),
    'canonical_block_markup' => '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group"><!-- wp:navigation {"overlayMenu":"mobile","className":"blocks-engine-list-navigation blocks-engine-native-responsive-navigation ' . $toggle . ' ' . $overlay . '"} /--></header><!-- /wp:group -->',
));
$projected = SharedChromeStylesheets::project($assets, $parts);
$shared = array_values(array_filter($projected, static fn(array $asset): bool => str_ends_with((string) ($asset['source_path'] ?? ''), '.shared-chrome')));
if (1 !== count($shared)) throw new RuntimeException('Shared header toggle rules must be lifted into shared chrome');
$sharedCss = (string) $shared[0]['content'];
if (!str_contains($sharedCss, $toggle . '>.wp-block-navigation__responsive-container-open') || !str_contains($sharedCss, 'max-width:1023px')) throw new RuntimeException('The collapse rules of the part\'s toggle marker must render on every adopting route: ' . $sharedCss);
if (!str_contains($sharedCss, $overlay)) throw new RuntimeException('The part\'s overlay marker rules must render on every adopting route: ' . $sharedCss);
$route = (string) $projected[0]['content'];
if (str_contains($route, $toggle) || str_contains($route, $overlay) || !str_contains($route, '.about-intro')) throw new RuntimeException('Only route content keeps route-scoped rules: ' . $route);
// The adopting routes compiled their own toggle markers, which no emitted
// markup carries once the part renders instead: their rules are dead weight.
$routeToggle = 'blocks-engine-native-navigation-toggle-406954d54743';
$routeCss = str_replace($toggle, $routeToggle, $css);
$pruned = DeadElementMarkers::withoutDeadSelectors(array(array('kind' => 'css', 'content' => $routeCss, 'source_path' => 'privacy.css')), array($toggle => true));
if (str_contains((string) $pruned[0]['content'], $routeToggle) || !str_contains((string) $pruned[0]['content'], '.about-intro')) throw new RuntimeException('Rules for a toggle marker no markup carries must be pruned: ' . $pruned[0]['content']);
$kept = DeadElementMarkers::withoutDeadSelectors($assets, array($toggle => true));
if ($kept !== $assets) throw new RuntimeException('Rules for a live toggle marker must be kept');
echo "Shared chrome navigation toggle marker passed\n";
