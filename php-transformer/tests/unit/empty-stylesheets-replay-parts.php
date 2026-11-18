<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\EmptyStylesheets;

$scope = array(array('kind' => 'global'));
$parent = array(
    'kind' => 'css',
    'source_path' => 'assets/site.css',
    'target_path' => 'assets/site.css',
    'token' => 'asset-parent',
    'scopes' => $scope,
    'stylesheet_instances' => array(array('source_path' => 'index.html', 'order' => 0, 'media' => '')),
    'stylesheet_parts' => array('assets/css/part-a.css', 'assets/css/part-b.css'),
    'references' => array(array('element' => 'link')),
);
$a = array(
    'kind' => 'css',
    'source_path' => 'assets/css/part-a.css',
    'target_path' => 'assets/css/part-a.css',
    'token' => 'asset-a',
    'content' => 'h1{color:red}',
    'scopes' => $scope,
    'stylesheet_replays' => array($parent),
);
$b = array(
    'kind' => 'css',
    'source_path' => 'assets/css/part-b.css',
    'target_path' => 'assets/css/part-b.css',
    'token' => 'asset-b',
    'content' => '  ',
    'scopes' => $scope,
);
$page = array(
    'source_path' => 'index.html',
    'post_type' => 'page',
    'reconciliation_identity' => 'page:index',
    'route' => array('path' => '/'),
    'document_metadata' => array('links' => array()),
);
$result = EmptyStylesheets::elide(array($a, $b), array('pages' => array($page)), array($page), array(), '');
if (array($a, $b) !== $result['assets']) throw new RuntimeException('A replay must keep the empty part it delivers through.');
if (array() !== $result['tokens']) throw new RuntimeException('A replay-delivered part must not have its token elided.');
echo "Empty stylesheet replay parts passed\n";
