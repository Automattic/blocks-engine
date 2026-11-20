<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A utility stylesheet expresses line-height as a number calculation. The
// captures prove it against different font sizes; widths between captures
// must scale the leading too, not retain the desktop's captured pixels.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$html = str_replace('</head>', '<style>main{font-size:60px;line-height:calc(2.5 / 2.25)}'
    . '@media(max-width:767px){main{font-size:36px}}</style></head>', $html);
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$tree['source_hash'] = hash('sha256', $html);
foreach ($tree['nodes']['n5']['viewports'] as $key => &$observation) {
    $mobile = $tree['viewports'][$key]['width'] < 768;
    $observation['used_styles']['font-size'] = $mobile ? '36px' : '60px';
    $observation['used_styles']['line-height'] = $mobile ? '40px' : '66.6667px';
}
unset($observation);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html, 'box-tree/index.json' => json_encode($tree),
)))->toArray();
$css = implode("\n", array_column($result['assets'], 'content'));
if (!preg_match('/\.be-inline-geometry-[a-f0-9]+\.be-inline-geometry-[a-f0-9]+\{[^{}]*line-height:calc\(2\.5 \/ 2\.25\)/', $css)) {
    throw new RuntimeException('Calculated number leading must remain font-relative in the geometry carrier.');
}
echo "Box-tree calculated line-height passed\n";
