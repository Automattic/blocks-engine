<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Even invariant measurements at every width can be an entrance frame rather
// than resting geometry. Preserve author conversion atomically: descendants
// and layout ancestors are affected by the animated box too.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$html = str_replace('</head>', '<style>@keyframes entrance{from{opacity:0;transform:scale(1.04)}to{opacity:1;transform:none}}h1{animation:entrance 1s both}</style></head>', $html);
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$tree['source_hash'] = hash('sha256', $html);
foreach ($tree['nodes']['n6']['viewports'] as &$observation) {
    $observation['used_styles']['animation-name'] = 'entrance';
    $observation['used_styles']['opacity'] = '0';
    $observation['used_styles']['transform'] = 'matrix(1.04, 0, 0, 1.04, 0, 0)';
}
unset($observation);
$compile = static function (?array $evidence) use ($html): array {
    $files = array('index.html' => $html);
    if (null !== $evidence) $files['box-tree/index.json'] = json_encode($evidence, JSON_THROW_ON_ERROR);
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
};
$authored = $compile(null);
$observed = $compile($tree);
if ($authored['serialized_blocks'] !== $observed['serialized_blocks']) {
    throw new RuntimeException('Animated measurements must not replace authored/preset block styling.');
}
if (array_column($authored['assets'], 'content') !== array_column($observed['assets'], 'content')) {
    throw new RuntimeException('Animated measurements must not retire author CSS, keyframes or responsive rules.');
}
if (!in_array('box_tree_animated_observations', array_column($observed['diagnostics'], 'code'), true)) {
    throw new RuntimeException('Rejected temporal evidence must have an actionable diagnostic.');
}
echo "PASS: Animated box-tree observations leave authored conversion unchanged.\n";
