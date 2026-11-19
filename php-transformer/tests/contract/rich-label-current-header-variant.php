<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/*
 * Every route renders the same anchored header and marks its own menu item
 * current; the home route also paints the header differently. Menu labels
 * are rich text (a styled span), so each route's current label carries state
 * classes the others lack. The home header is still the site chrome in a
 * route-local presentation: it moves to its own header part rather than
 * staying a copy of the shell in page content.
 */
$routes = array('index.html' => '/', 'about/index.html' => '/about/', 'work/index.html' => '/work/', 'shop/index.html' => '/shop/');
$files = array();
foreach ($routes as $source => $path) {
    $items = '';
    foreach ($routes as $href) {
        $selected = $href === $path;
        $label = '/' === $href ? 'Home' : ucfirst(trim($href, '/'));
        $items .= '<li><div class="item' . ($selected ? ' menu__item--selected' : '') . '"><a href="' . $href . '"' . ($selected ? ' aria-current="page"' : '') . '><span class="label">' . $label . '</span></a></div></li>';
    }
    $style = '/' === $path ? ' style="min-height:0;margin:0"' : '';
    $files[$source] = '<!doctype html><html><head><title>' . $source . '</title><style>#site-header{background:#eee;padding:10px}#site-header ul{display:flex;gap:12px;list-style:none}.menu__item--selected .label{color:#c00;font-weight:700}main{min-height:200px}</style></head><body><header id="site-header"' . $style . '><p class="brand">Shared brand</p><nav><ul>' . $items . '</ul></nav></header><main><h1>' . $source . '</h1><p>Route content.</p></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
foreach ($plan['pages'] as $page) {
    if (preg_match('/<header[ >]/', $page['canonical_block_markup'])) throw new RuntimeException($page['source_path'] . ' retains a copy of the header shell in page content.');
}
echo "rich-label-current-header-variant: passed\n";
