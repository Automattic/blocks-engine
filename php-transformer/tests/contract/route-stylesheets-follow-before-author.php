<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// The engine reset sheet is placed before the author CSS and states the
// cascade layer order (`@layer blocks-engine-reset,base,utilities`). A route
// whose styles are evidence-owned (box-tree reduction) still delivers its
// author sheet through the route table. That table must be emitted where the
// route's first instance falls, after the before-author sheet: emitted first,
// the author sheet names its layers ahead of the engine's statement and the
// reset layer wins over the author's utilities (a `space-y-2` gap collapses).
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$pages = array(array(
    'source_path' => 'index.html',
    'post_type' => 'page',
    'route' => array('path' => '/'),
    'reconciliation_identity' => 'home',
    'canonical_block_markup' => '',
    'metadata' => array(),
    'document_metadata' => array('engine_owned_styles' => true),
));
$assets = array(
    array('kind' => 'css', 'source' => 'engine-support', 'source_path' => 'engine-before.css', 'target_path' => 'assets/css/engine-support-before-author.css', 'content_hash' => hash('sha256', 'before'), 'stylesheet_placement' => 'before-author', 'scopes' => array(array('kind' => 'global'))),
    array('kind' => 'css', 'source_path' => 'author.css', 'target_path' => 'assets/author.css', 'content_hash' => hash('sha256', 'author'), 'scopes' => array(array('kind' => 'global')), 'stylesheet_instances' => array(array('source_path' => 'index.html', 'order' => 0, 'media' => ''))),
    array('kind' => 'css', 'source' => 'engine-support', 'source_path' => 'engine-after.css', 'target_path' => 'assets/css/engine-support-after-author.css', 'content_hash' => hash('sha256', 'after'), 'stylesheet_placement' => 'after-author', 'scopes' => array(array('kind' => 'global'))),
);
$bootstrap = (new ReflectionMethod(ThemeBootstrap::class, 'functionsPhp'))->invoke(null, $assets, array(), array(), array(), array(), $pages);
$before = strpos($bootstrap, "'assets/css/engine-support-before-author.css'");
$route = strpos($bootstrap, "'assets/author.css'");
$after = strpos($bootstrap, "'assets/css/engine-support-after-author.css'");
$table = strpos($bootstrap, 'foreach ( $blocks_engine_style_routes as');
$assert(false !== $before && false !== $route && false !== $after && false !== $table, 'Every stylesheet reaches the front-end bootstrap.');
$assert($before < $route && $before < $table, 'The before-author engine sheet is enqueued ahead of the evidence route author sheet.');
$assert($table < $after, 'The after-author engine sheet still follows the route author sheet.');
