<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\EvidenceRouteStylesheets;

// The public retirement boundary must preserve direct animation bindings,
// separate timing rules and reduced-motion overrides, not just var() consumers.
$asset = array(
    'kind' => 'css', 'source' => 'author',
    'scopes' => array(array('kind' => 'page', 'source_path' => 'index.html')),
    'content' => '@keyframes enter{from{opacity:0}to{opacity:1}}'
        . '@keyframes unused{to{opacity:0}}'
        . '.reveal{animation:enter 1s ease forwards;color:red;width:100px}'
        . '.delayed{animation-delay:300ms}'
        . '@media(prefers-reduced-motion:reduce){.reveal{animation:none;color:blue}}',
);
$page = array(
    'source_path' => 'index.html',
    'document_metadata' => array('engine_owned_styles' => true),
    'canonical_block_markup' => '<p class="reveal delayed">Hello</p>',
);
$css = EvidenceRouteStylesheets::retire(array($asset), array($page))[0]['content'];
foreach (array('@keyframes enter{', '.reveal{animation:enter 1s ease forwards}', '.delayed{animation-delay:300ms}', '@media(prefers-reduced-motion:reduce){.reveal{animation:none}}') as $expected) {
    if (!str_contains($css, $expected)) throw new RuntimeException('Missing ' . $expected . ': ' . $css);
}
foreach (array('unused', 'color:', 'width:') as $retired) {
    if (str_contains($css, $retired)) throw new RuntimeException('Retained ' . $retired . ': ' . $css);
}
echo "PASS: direct animation bindings survive evidence-route retirement without layout rules\n";
