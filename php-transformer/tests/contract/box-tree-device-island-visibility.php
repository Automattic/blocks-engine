<?php
declare(strict_types=1);

// A responsive capture folds a route's desktop and phone copies of one
// component into sibling device islands and ships the sheet choosing which
// paints at each width. Box-tree evidence retires author CSS on the route, but
// that sheet is capture contract: without it both islands paint everywhere.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = str_replace('</head>', '<link data-blocks-engine-node-id="styles" rel="stylesheet" href="author.css"><link data-blocks-engine-node-id="islands" rel="stylesheet" href="islands.css"></head>', $html);
$html = str_replace(
    '<p data-blocks-engine-node-id="n7">Evidence only.</p>',
    '<p class="data-blocks-engine-capture-desktop-only" data-blocks-engine-node-id="n7">Desktop card.</p>'
    . '<p id="card--data-blocks-engine-capture-mobile" class="data-blocks-engine-capture-mobile-only" data-blocks-engine-node-id="n8">Phone card.</p>',
    $html
);
$tree['source_hash'] = hash('sha256', $html);
array_push($tree['nodes']['n2']['children'], 'styles', 'islands');
$tree['nodes']['styles'] = $tree['nodes']['islands'] = array('children' => array(), 'viewports' => $tree['nodes']['n3']['viewports']);
$tree['nodes']['n5']['children'][] = 'n8';
$tree['nodes']['n8'] = $tree['nodes']['n7'];

$visibility = '@media(min-width:1024px){.data-blocks-engine-capture-mobile-only{display:none!important}}'
    . '@media(max-width:1023px){.data-blocks-engine-capture-desktop-only{display:none!important}}';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'author.css' => 'h1{opacity:.25}',
    'islands.css' => '@font-face{font-family:Card;src:url(card.woff2)}' . $visibility . 'p{color:red}',
    'box-tree/index.json' => json_encode($tree),
)));
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$assert(!empty($plan['pages'][0]['document_metadata']['engine_owned_styles']), 'The route is an evidence route.');
$assets = array_column($plan['assets'], null, 'target_path');
$delivered = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $delivered .= (string) ($assets[$row['target_path']]['content'] ?? '');
$assert(!str_contains($delivered, 'h1{opacity') && !str_contains($delivered, 'p{color:red}'), 'Author rules stay retired on the evidence route.');
$assert(
    1 === preg_match('/@media\s*\(min-width:\s*1024px\)\s*\{[^{}]*\.data-blocks-engine-capture-mobile-only[^{}]*\{display:none!important\}/', $delivered)
    && 1 === preg_match('/@media\s*\(max-width:\s*1023px\)\s*\{[^{}]*\.data-blocks-engine-capture-desktop-only[^{}]*\{display:none!important\}/', $delivered),
    'The device-island visibility sheet still selects one island per width.'
);
$assert(1 === substr_count($delivered, '@font-face'), 'Font faces are retained once.');
