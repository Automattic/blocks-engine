<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// A header shared by the entry route (no box-tree evidence) and an evidence
// route. The evidence route retires author CSS, so the shared part must carry
// the evidence route's engine-owned styles rather than the entry's author-styled copy.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$template = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$page = static fn(string $title): string => '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><title>' . $title . '</title><link rel="stylesheet" href="/author.css"></head><body data-blocks-engine-node-id="n4"><header class="site-header" data-blocks-engine-node-id="h1"><a class="logo" href="/index.html" data-blocks-engine-node-id="h2">APEX</a><nav data-blocks-engine-node-id="h3"><a href="/index.html" data-blocks-engine-node-id="h4">Home</a><a href="/about/index.html" data-blocks-engine-node-id="h5">About</a></nav></header><main data-blocks-engine-node-id="n5"><h1 data-blocks-engine-node-id="n6">' . $title . '</h1><p data-blocks-engine-node-id="n7">' . $title . ' body.</p></main></body></html>';
$about = $page('About');
$viewports = $template['nodes']['n6']['viewports'];
$logo = $viewports;
foreach ($logo as &$viewport) $viewport['used_styles']['font-size'] = '18px';
unset($viewport);
$nodes = array();
foreach (array('n1' => array('n2', 'n4'), 'n2' => array(), 'n4' => array('h1', 'n5'), 'h1' => array('h2', 'h3'), 'h2' => array(), 'h3' => array('h4', 'h5'), 'h4' => array(), 'h5' => array(), 'n5' => array('n6', 'n7'), 'n6' => array(), 'n7' => array()) as $id => $children) {
    $nodes[$id] = array('children' => $children, 'viewports' => 'h2' === $id ? $logo : $viewports);
}
$tree = array('schema' => $template['schema'], 'source_path' => 'about/index.html', 'source_hash' => hash('sha256', $about), 'viewports' => $template['viewports'], 'nodes' => $nodes);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Home'),
    'about/index.html' => $about,
    'author.css' => '.logo{font-size:18px}',
    'box-tree/about.json' => json_encode($tree),
)));
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];
$pages = array_column($plan['pages'], null, 'source_path');
$assert(empty($pages['index.html']['document_metadata']['engine_owned_styles']) && !empty($pages['about/index.html']['document_metadata']['engine_owned_styles']), 'Only the non-entry route owns its styles from evidence.');
$header = array_column($plan['template_parts'], null, 'slug')['header']['canonical_block_markup'] ?? '';
$assert(1 === preg_match('/<p class="[^"]*\b(be-inline-geometry-[a-f0-9]{16})\b[^"]*"[^>]*><a class="logo"/', $header, $carrier), 'The shared header part carries the evidence route\'s engine-owned logo carrier.');
$assets = array_column($plan['assets'], null, 'target_path');
$delivered = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'about/index.html') as $row) $delivered .= $assets[$row['target_path']]['content'] ?? '';
$assert(!str_contains($delivered, '.logo{') && str_contains($delivered, '.' . $carrier[1] . '.' . $carrier[1]), 'The evidence route styles its shared header from the carrier, not author CSS.');
