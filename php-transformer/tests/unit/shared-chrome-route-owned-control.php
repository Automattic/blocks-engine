<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\SharedChromeStylesheets;

// A control class addresses its complete rule sequence, so a header part's
// skip link and a page's own inlined copy of that link carry one class. The
// page's copy keeps the control's rules in that route's stylesheet, in their
// cascade position. Every other route renders the same header from a
// template without that stylesheet, so the part needs a copy of the rules
// that those routes see and the owning route does not.
$control = 'blocks-engine-control-37614c30b62d-0';
$rule = '.' . $control . '.' . $control . '>.wp-block-button__link{border-radius:0!important;text-align:start!important}';
$button = static fn(string $label): string => '<!-- wp:buttons {"className":"' . $control . '"} --><div class="wp-block-buttons ' . $control . '"><!-- wp:button {"className":"' . $control . '"} --><div class="wp-block-button ' . $control . '"><a class="wp-block-button__link wp-element-button" href="#main">' . $label . '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
$assets = array(array(
    'kind' => 'css',
    'content' => $rule . '.founder-intro{color:red}',
    'source_path' => 'founder.css',
    'path' => 'founder.css',
    'target_path' => 'assets/founder.css',
    'scopes' => array(array('kind' => 'page', 'source_path' => 'founder.html')),
));
$parts = array(array(
    'slug' => 'header',
    'area' => 'header',
    'placement' => array('kind' => 'shared_shell', 'source_paths' => array('founder.html', 'mare.html')),
    'canonical_block_markup' => '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group">' . $button('Skip to content') . '</header><!-- /wp:group -->',
));
$page = static fn(string $path, string $route, string $markup): array => array(
    'source_path' => $path,
    'canonical_block_markup' => $markup,
    'document_metadata' => array('root_attributes' => array('class' => $route)),
);
$pages = array(
    $page('founder.html', 'be-r-aaaaaaaa', $button('Skip to content') . '<!-- wp:paragraph {"className":"founder-intro"} --><p class="founder-intro">Founder</p><!-- /wp:paragraph -->'),
    $page('mare.html', 'be-r-bbbbbbbb', '<!-- wp:paragraph --><p>Mare</p><!-- /wp:paragraph -->'),
);

$projected = SharedChromeStylesheets::project($assets, $parts, $pages);
$route = (string) $projected[0]['content'];
if (!str_contains($route, $rule) || !str_contains($route, '.founder-intro')) throw new RuntimeException('The owning route keeps the control rules in its own cascade: ' . $route);
$shared = array_values(array_filter($projected, static fn(array $asset): bool => str_ends_with((string) ($asset['source_path'] ?? ''), '.shared-chrome')));
if (1 !== count($shared)) throw new RuntimeException('Routes adopting the part without the owning route stylesheet need the control rules in shared chrome');
$sharedCss = (string) $shared[0]['content'];
$gated = ':where(:root:not(.be-r-aaaaaaaa)) .' . $control . '.' . $control . '>.wp-block-button__link{border-radius:0!important;text-align:start!important}';
if (!str_contains($sharedCss, $gated)) throw new RuntimeException('The shared copy keeps the control specificity and is gated away from the owning route: ' . $sharedCss);
if (str_contains($sharedCss, 'founder-intro')) throw new RuntimeException('Only the part\'s control rules follow the part: ' . $sharedCss);

// A route stylesheet without a stamped route cannot be gated, so its control
// keeps its current route ownership instead of being restated globally.
$unstamped = SharedChromeStylesheets::project($assets, $parts, array($page('founder.html', '', $pages[0]['canonical_block_markup']), $pages[1]));
foreach ($unstamped as $asset) {
    if (str_ends_with((string) ($asset['source_path'] ?? ''), '.shared-chrome') && str_contains((string) $asset['content'], $control)) throw new RuntimeException('An ungateable route-owned control must not be lifted: ' . $asset['content']);
}
// An editor static-state capture holds rules only for the routes it covered;
// lifting them for every other route would widen that capture.
$captured = SharedChromeStylesheets::project(array(array('source' => 'editor-static-state') + $assets[0]), $parts, $pages);
foreach ($captured as $asset) {
    if (str_ends_with((string) ($asset['source_path'] ?? ''), '.shared-chrome') && str_contains((string) $asset['content'], $control)) throw new RuntimeException('Editor static-state control rules must not widen past their capture: ' . $asset['content']);
}
echo "Shared chrome route-owned control passed\n";
