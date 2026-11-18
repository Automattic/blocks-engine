<?php
declare(strict_types=1);

// A responsive capture folds desktop and phone frames into sibling device
// islands and ships the rules that pick one per viewport. On a box-tree route
// the author stylesheet is retired. The phone capture governs every width up
// to the switch, not only up to its own width, and the desktop island, which
// has no evidence there, hides in that band; losing that switch left tablet
// widths in frozen desktop pixels with images pushed out of the viewport.
// Widths above the switch keep the desktop capture's rendering unchanged.

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = str_replace('</head>', '<link data-blocks-engine-node-id="styles" rel="stylesheet" href="author.css"></head>', $html);
// The route's two device islands (the switch rules are pruned without subjects).
$html = str_replace(array('<h1 ', '<p '), array('<h1 class="data-blocks-engine-capture-mobile-only" ', '<p class="data-blocks-engine-capture-desktop-only" '), $html);
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes']['n2']['children'][] = 'styles';
$tree['nodes']['styles'] = array('children' => array(), 'viewports' => $tree['nodes']['n3']['viewports']);
$tree['nodes']['n6']['viewports']['desktop']['used_styles']['opacity'] = '0.75';
$tree['nodes']['n6']['viewports']['mobile']['used_styles']['opacity'] = '1';
$assert = static function (bool $ok, string $message, string $context = ''): void {
    if (!$ok) throw new RuntimeException($message . ('' !== $context ? "\n" . $context : ''));
    echo "PASS: $message\n";
};

$switch = '@media(min-width:1024px){.data-blocks-engine-capture-mobile-only{display:none!important}}'
    . '@media(max-width:1023px){.data-blocks-engine-capture-desktop-only{display:none!important}}';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'author.css' => 'h1{opacity:.25}' . $switch,
    'box-tree/index.json' => json_encode($tree),
)));
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(!empty($plan['pages'][0]['document_metadata']['engine_owned_styles']), 'The route is owned by box-tree evidence.');
$assets = array_column($plan['assets'], null, 'target_path');
$delivered = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $delivered .= ($assets[$row['target_path']]['content'] ?? '') . "\n";
$compact = (string) preg_replace('/\s+/', '', $delivered);
$assert(!str_contains($compact, 'h1{opacity'), 'Author presentation is still retired on the evidence route.', $delivered);
$assert(1 === preg_match('/@media\(max-width:1023px\)\{[^{}]*\.data-blocks-engine-capture-desktop-only\{display:none!important\}\}/', $compact), 'The desktop island hides below the capture device switch.', $delivered);
$assert(!str_contains($compact, 'min-width:1024px'), 'Rules for the desktop band stay retired.', $delivered);

$css = implode("\n", array_column(array_filter($result->toArray()['assets'], static fn(array $a): bool => 'css' === ($a['kind'] ?? null)), 'content'));
$assert(str_contains($css, '@media (max-width:1023px)') && !str_contains($css, '@media (max-width:390px)'), 'The phone capture governs every width that shows the phone island.', $css);
