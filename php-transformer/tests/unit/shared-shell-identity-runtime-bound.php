<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;

// One source header compiles differently per route context, and each copy
// anchors a runtime binding (a cart count). The isolated artifact proves the
// copies are one shell: they must compare equal, so the header is extracted as
// one shared part rather than split into route variants. The bound compiled
// payload itself stays untouched for the binding hoist.
$landmark = static fn(string $class): string => '<!-- wp:group {"tagName":"header","className":"' . $class . '"} --><header class="wp-block-group ' . $class . '"><!-- wp:paragraph --><p><a href="/cart">Cart (0)</a></p><!-- /wp:paragraph --></header><!-- /wp:group -->';
$pages = array(); $declarations = array();
foreach (array('home.html', 'product.html') as $i => $path) {
    $markup = $landmark('route-context-' . $i);
    $pages[] = array('source_path' => $path, 'canonical_block_markup' => $markup, 'shell_candidates' => array(array('area' => 'header', 'markup' => $markup, 'identity_markup' => $markup, 'template_part_markup' => $markup, 'offset' => 0, 'length' => strlen($markup))));
    $index = new BlockMarkupIndex($markup);
    foreach ($index->ranges() as $blockIndex => $range) {
        $block = substr($markup, $range['offset'], $range['length']);
        if (!str_starts_with($block, '<!-- wp:paragraph')) continue;
        $declarations[] = array('type' => 'commerce', 'payload' => array('entities' => array(array('kind' => 'cart_count', 'bindings' => array(array('source_path' => $path, 'search_block_markup' => $block, 'position' => array('schema' => 'blocks-engine/runtime-binding-position/v1', 'block_index' => $blockIndex, 'offset' => $range['offset'], 'length' => $range['length'])))))));
    }
}
if (2 !== count($declarations)) throw new RuntimeException('Fixture must bind one block per route.');
$artifacts = array(array('area' => 'header', 'canonical_block_markup' => $landmark('isolated-context'), 'placement' => array('equal_source_identity' => true, 'source_paths' => array_column($pages, 'source_path'))));
$result = (new ShellExtraction(new \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RouteLinks('', '', array(), array(), null, array(), array())))->withSourceSharedShellIdentity($pages, $artifacts, $declarations);
if ($result[0]['shell_candidates'][0]['identity_markup'] !== $result[1]['shell_candidates'][0]['identity_markup']) throw new RuntimeException('Runtime-bound equal source shells must compare equal.');
foreach ($result as $i => $page) {
    $candidate = $page['shell_candidates'][0];
    if ($candidate['markup'] !== $pages[$i]['shell_candidates'][0]['markup'] || $candidate['template_part_markup'] !== $pages[$i]['shell_candidates'][0]['template_part_markup']) throw new RuntimeException('A runtime-bound shell must keep the compiled payload its binding addresses.');
}
echo "Shared shell identity covers runtime-bound shells: passed\n";
