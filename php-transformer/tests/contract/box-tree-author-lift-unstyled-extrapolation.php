<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Both captures prove the author padding. A missing declaration below them
// must not discard either the tablet variant or the valid wider author band.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$html = str_replace('</head>', '<style>@media(min-width:300px){main{padding-top:10px}}'
    . '@media(min-width:700px){main{padding-top:30px}}'
    . '@media(min-width:1000px){main{padding-top:10px}}'
    . '@media(min-width:1600px){main{padding-top:50px}}</style></head>', $html);
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$tree['source_hash'] = hash('sha256', $html);
foreach ($tree['nodes']['n5']['viewports'] as &$observation) {
    $observation['used_styles']['padding-top'] = '10px';
}
unset($observation);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html, 'box-tree/index.json' => json_encode($tree),
)))->toArray();
$css = implode("\n", array_column($result['assets'], 'content'));
if (!preg_match('/@media \(max-width:999px\)\{[^@]*padding-top:30px/', $css)) {
    throw new RuntimeException('An unstyled extrapolation must not discard the proven tablet padding.');
}
if (!preg_match('/\.be-inline-geometry-[a-f0-9]+\.be-inline-geometry-[a-f0-9]+\{[^{}]*padding-top:50px/', $css)) {
    throw new RuntimeException('A missing narrow declaration must not discard a valid wider author variant.');
}
echo "Box-tree author lift unstyled extrapolation passed\n";
