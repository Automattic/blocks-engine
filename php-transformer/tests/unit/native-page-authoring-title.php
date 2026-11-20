<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RouteLinks;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;

$links = new RouteLinks('', '', array(), array(), null, array(), array());
$pages = array(array('post_type' => 'page', 'slug' => 'details', 'title' => 'Details', 'canonical_block_markup' => '<!-- wp:heading {"level":1} --><h1>Details</h1><!-- /wp:heading -->', 'metadata' => array()));
$templates = array_column(ThemeTemplates::build($links, $pages, array()), 'canonical_block_markup', 'slug');
if (!str_contains($templates['page'], '<!-- wp:post-title {"level":1} /-->')) throw new RuntimeException('New pages must render their native title even without an extracted shared frame.');
if (!isset($templates['page-details']) || str_contains($templates['page-details'], 'wp:post-title') || !str_contains($templates['page-details'], 'wp:post-content')) throw new RuntimeException('Captured pages must retain a content-only override, without a duplicate title.');
// Explicit source templates remain authoritative.
$templates = array_column(ThemeTemplates::build($links, $pages, array(), array(), array(), null, null, array(), array(), array(), array('page' => '<!-- wp:post-content /-->')), 'canonical_block_markup', 'slug');
if (str_contains($templates['page'], 'wp:post-title')) throw new RuntimeException('Do not add a title to a source-owned page lifecycle.');
// The generic lifecycle must retain the captured column without copying any
// sibling content or rendering a second title on captured routes.
$pages[0]['canonical_block_markup'] = '<!-- wp:group {"className":"site"} --><div class="wp-block-group site"><!-- wp:group {"tagName":"main","className":"container"} --><main class="wp-block-group container"><!-- wp:heading {"level":1} --><h1>Details</h1><!-- /wp:heading --><!-- wp:paragraph {"className":"body-column"} --><p class="body-column">Captured text</p><!-- /wp:paragraph --></main><!-- /wp:group --></div><!-- /wp:group -->';
$templates = array_column(ThemeTemplates::build($links, $pages, array()), 'canonical_block_markup', 'slug');
if (!str_contains($templates['page'], 'wp-block-group container') || !str_contains($templates['page'], 'wp-block-group body-column')) throw new RuntimeException('The native title and content must inherit the source body column.');
if (str_contains($templates['page'], 'Captured text') || str_contains($templates['page'], '<h1>Details')) throw new RuntimeException('A new page must not inherit route-owned text.');
if (str_contains($templates['page-details'], 'body-column')) throw new RuntimeException('Captured content must not be wrapped a second time.');
echo "Native page authoring title assertions passed.\n";
