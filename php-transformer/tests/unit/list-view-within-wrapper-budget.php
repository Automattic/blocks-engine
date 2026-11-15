<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Groups can be a majority without exceeding the wrapper-to-content budget.
// These branching native boxes are within the public depth limit and must not
// change transport just because their document has more groups than leaves.
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
$markup = (string) ($result['serialized_blocks'] ?? '');
if (str_contains($markup, '<!-- wp:custom/layout-shell')) {
    throw new RuntimeException('Within-budget branching Groups must retain native transport.');
}
foreach (range(1, 6) as $i) {
    if (!str_contains($markup, 'id="outer-' . $i . '"') || !str_contains($markup, 'id="branch-' . $i . '"')) {
        throw new RuntimeException('Native transport must retain every authored box.');
    }
}
echo "Within-budget List View passed\n";
