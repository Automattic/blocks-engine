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
$a['stylesheet_replays'][0]['stylesheet_parts'] = array('assets/css/part-a.css');
if (array($a) !== $result['assets']) throw new RuntimeException('A replay must drop its empty part and retain its non-empty part.');
if (array('asset-b' => true) !== $result['tokens']) throw new RuntimeException('An emptied part must have its token elided.');
// The first resource hosts delivery metadata, not a reason to keep its bytes.
$a['content'] = '';
$b['content'] = 'h2{color:blue}';
$a['stylesheet_replays'][0]['stylesheet_parts'] = array('assets/css/part-a.css', 'assets/css/part-b.css');
$result = EmptyStylesheets::elide(array($a, $b), array('pages' => array($page)), array($page), array(), '');
$b['stylesheet_replays'] = $a['stylesheet_replays'];
$b['stylesheet_replays'][0]['stylesheet_parts'] = array('assets/css/part-b.css');
if (array($b) !== $result['assets']) throw new RuntimeException('An empty host must transfer its replay to the first surviving part.');

// An exhausted owner becomes an ordinary empty stylesheet, not an immortal
// delivery wrapper. Existing reference/route guards still decide its removal.
$b['content'] = '';
$result = EmptyStylesheets::elide(array($b), array('pages' => array($page)), array($page), array(), '');
$owner = $parent;
unset($owner['stylesheet_parts']);
$owner = Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\CompiledAssets::withRewrittenTextContent($owner, '');
$expected = EmptyStylesheets::elide(array($owner), array('pages' => array($page)), array($page), array(), '');
if ($expected['assets'] !== $result['assets']) throw new RuntimeException('An owner with no remaining parts must follow empty-stylesheet elision.');
echo "Empty stylesheet replay parts passed\n";
