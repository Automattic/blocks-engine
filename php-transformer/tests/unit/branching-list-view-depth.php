<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Branching wrappers can dominate List View without exceeding the separate
// wrapper-to-content ratio. Their depth still needs the List View budget.
$html = '<h3>Editable heading</h3><p>Editable leaf</p>';
for ($i = 6; $i >= 1; --$i) {
    $html = '<div id="outer-' . $i . '"><div id="branch-' . $i . '">'
        . '<p>Branch ' . $i . '</p>' . $html . '</div></div>';
}
$result = (new ArtifactCompiler())->compile(array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'website/index.html',
    'files' => array(array(
        'path' => 'website/index.html',
        'content' => '<!doctype html><html><body>' . $html . '</body></html>',
    )),
))->toArray();
$depth = static function (array $blocks) use (&$depth): int {
    $height = 0;
    foreach ($blocks as $block) {
        $height = max($height, 1 + $depth($block['innerBlocks'] ?? array()));
    }
    return $height;
};
if ($depth($result['blocks']) > 8) {
    throw new RuntimeException('Branching Group-majority documents must fit the List View budget.');
}
$markup = (string) $result['serialized_blocks'];
foreach (range(1, 6) as $i) {
    if (!str_contains($markup, 'id="outer-' . $i . '"') || !str_contains($markup, 'id="branch-' . $i . '"')) {
        throw new RuntimeException('Compression must preserve every authored box.');
    }
}
if (7 !== substr_count($markup, '<!-- wp:paragraph')) {
    throw new RuntimeException('Every paragraph must remain independently editable.');
}
echo "Branching List View depth passed\n";
