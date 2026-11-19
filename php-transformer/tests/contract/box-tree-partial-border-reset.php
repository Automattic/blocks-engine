<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A captured bottom-only separator must not acquire medium-width borders
// on its other sides when WordPress applies border support styles.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true);
foreach ($tree['nodes']['n7']['viewports'] as &$viewport) {
    foreach (array('top', 'right', 'bottom', 'left') as $side) {
        $viewport['used_styles']['border-' . $side] = ('bottom' === $side ? '1px' : '0px') . ' solid rgb(200, 200, 200)';
    }
}
unset($viewport);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html, 'box-tree/index.json' => json_encode($tree),
)))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === $asset['kind']), 'content'));
foreach (array('top', 'right', 'left') as $side) {
    if (!str_contains($css, 'border-' . $side . ':0px solid rgb(200, 200, 200)')) {
        throw new RuntimeException('Captured non-painting border side lost its reset: ' . $side);
    }
}
echo "Captured partial borders preserve non-painting sides\n";
