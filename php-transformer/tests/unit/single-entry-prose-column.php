<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A landing page's body prose lives in the right-hand grid column. New
// pages must inherit that column, not a centered max-width approximation.
$html = '<html data-blocks-engine-node-id="html"><head data-blocks-engine-node-id="head"></head><body data-blocks-engine-node-id="body"><main data-blocks-engine-node-id="main"><p data-blocks-engine-node-id="p">An editable body paragraph in the wide right column.</p></main></body></html>';
$styles = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true)['nodes']['n7']['viewports']['desktop']['used_styles'];
$nodes = array();
foreach (array('html' => array('head', 'body'), 'head' => array(), 'body' => array('main'), 'main' => array('p'), 'p' => array()) as $id => $children) {
    $box = 'p' === $id ? array(667.5, 100, 662.5, 60) : array(0, 0, 1440, 900);
    $nodes[$id] = array('children' => $children, 'viewports' => array('desktop' => array(
        'border_box' => array_combine(array('x', 'y', 'width', 'height'), $box),
        'used_styles' => array_replace($styles, array('display' => 'head' === $id ? 'none' : 'block', 'width' => $box[2] . 'px', 'height' => $box[3] . 'px')),
    )));
}
$tree = array('schema' => 'blocks-engine/box-tree/v2', 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html), 'viewports' => array('desktop' => array('width' => 1440, 'height' => 900)), 'nodes' => $nodes);
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))));
$plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
if (!str_contains($templates['page'] ?? '', 'margin-left:667.5px') || !str_contains($templates['page'], 'margin-right:110px') || str_contains($templates['front-page'] ?? '', 'margin-left:667.5px')) {
    fwrite(STDERR, "FAIL: only the fallback page inherits the observed prose column\n" . json_encode($templates));
    exit(1);
}
echo "single-entry-prose-column: passed\n";
