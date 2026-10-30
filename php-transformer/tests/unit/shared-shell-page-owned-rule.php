<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Both pages share the footer, so it compiles once as a shared shell. Their
// headers differ by the current item, so each page projects its own menu rule.
// Core prints a submenu's class on its item and its container, so the menu
// rule must stay narrowed to the item in the stylesheet every page loads.
$page = file_get_contents(dirname(__DIR__) . '/fixtures/shared-shell-page-owned-rule.html');
$second = str_replace(array('menu-item current', '<li class="menu-item"><a href="/two/">'), array('menu-item', '<li class="menu-item current"><a href="/two/">'), $page);
if ( $second === $page ) {
    throw new RuntimeException('fixture lost its current item');
}
$compiled = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files'      => array(
        'index.html'     => $page,
        'two/index.html' => $second,
        'style.css'      => file_get_contents(dirname(__DIR__) . '/fixtures/shared-shell-page-owned-rule.css'),
    ),
))->toArray();

$css = '';
foreach ( $compiled['assets'] as $file ) {
    if ( 'css' === ($file['kind'] ?? null) ) {
        $css .= (string) ($file['content'] ?? '');
    }
}
if ( ! str_contains($css, '.site-nav .wp-block-navigation-item.menu-item{position:relative}') ) {
    throw new RuntimeException('menu rule was not projected to the navigation item');
}
if ( str_contains($css, '.site-nav .menu-item{position:relative}') ) {
    throw new RuntimeException('shared shell leaked the unprojected menu rule onto submenu containers');
}

echo "Shared shell leaves page-owned rules to pages passed\n";
