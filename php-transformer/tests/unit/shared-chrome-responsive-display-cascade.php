<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\SharedChromeStylesheets;

// A shared menu button also carries a route-owned utility hook. Responsive
// display must travel with it; typography and paint must keep their original
// cascade position relative to the route's authored utility rules.
$marker = 'blocks-engine-control-0123456789ab-1';
$asset = array('kind' => 'css', 'source' => 'engine-support', 'source_path' => 'support.css', 'path' => 'support.css', 'target_path' => 'assets/support.css',
    'content' => '.' . $marker . '{font-size:12px;color:red;display:block}@media(min-width:768px){.' . $marker . '{display:none!important;color:blue}}');
$parts = array(array('slug' => 'header', 'placement' => array('kind' => 'shared_shell'),
    'canonical_block_markup' => '<!-- wp:button --><div class="wp-block-button utility ' . $marker . '"><a class="wp-block-button__link">Menu</a></div><!-- /wp:button -->'));
$pages = array(array('source_path' => 'index.html', 'canonical_block_markup' => '<!-- wp:paragraph --><p class="utility">Route text</p><!-- /wp:paragraph -->'));
$projected = SharedChromeStylesheets::project(array($asset), $parts, $pages);
$shared = '';
$route = '';
foreach ($projected as $item) {
    if (str_ends_with($item['source_path'], '.shared-chrome')) $shared .= $item['content'];
    else $route .= $item['content'];
}
if (!str_contains($shared, 'display:block') || !str_contains($shared, '@media(min-width:768px)') || !str_contains($shared, 'display:none!important')) {
    throw new RuntimeException('Shared chrome must carry its responsive display rules: ' . $shared);
}
if (str_contains($shared, 'font-size') || str_contains($shared, 'color:') || !str_contains($route, 'font-size:12px') || !str_contains($route, 'color:blue')) {
    throw new RuntimeException('Unrelated support styling must retain the route cascade: ' . $shared);
}
if (str_contains($route, 'display:')) throw new RuntimeException('Part-only display rules must not remain on the route');
echo "Shared chrome responsive display cascade passed\n";
