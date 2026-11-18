<?php
/**
 * A list navigation's item container renders `display:contents`, so the
 * navigation host lays out the items. Lowered from a `nav > ul` wrapper, the
 * host's box-tree carrier takes the list's observed layout (a flex row), not
 * the wrapper's block box, which would stack the items (#5861).
 */
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = (string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$html = str_replace('<p data-blocks-engine-node-id="n7">Evidence only.</p>', '<nav aria-label="Social" data-blocks-engine-node-id="n7"><ul class="bar" data-blocks-engine-node-id="n8"><li><a href="https://example.com/a">A</a></li><li><a href="https://example.com/b">B</a></li></ul></nav>', $html);
$html = str_replace('</head>', '<style>.bar{display:flex;justify-content:center;column-gap:20px;list-style:none}</style></head>', $html);
$tree = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes']['n8'] = $tree['nodes']['n7'];
$tree['nodes']['n7']['children'] = array('n8');
foreach ($tree['nodes']['n8']['viewports'] as $key => $_) {
    $tree['nodes']['n8']['viewports'][$key]['used_styles'] = array_replace($tree['nodes']['n8']['viewports'][$key]['used_styles'], array('display' => 'flex', 'justify-content' => 'center', 'column-gap' => '20px'));
}

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$css = implode("\n", array_column($result['assets'], 'content'));
$failures = array();
if (1 !== preg_match('/<!-- wp:navigation \{[^\n]*"className":"([^"]*blocks-engine-list-navigation[^"]*)"/', $result['serialized_blocks'], $match)) {
    $failures[] = 'the nav does not lower to a list navigation';
} elseif (1 !== preg_match('/\b(be-inline-geometry-[0-9a-f]+)\b/', $match[1], $carrier)) {
    $failures[] = 'the navigation host has no box-tree carrier';
} else {
    preg_match_all('/\.' . $carrier[1] . '(?:\.' . $carrier[1] . ')?\{([^}]*)\}/', $css, $rules);
    $declarations = implode(';', $rules[1]);
    if (!str_contains($declarations, 'display:flex')) $failures[] = "the navigation host does not lay out its items as the list's flex row: {$declarations}";
    if (str_contains($declarations, 'display:block')) $failures[] = "the navigation host keeps the wrapper's block box: {$declarations}";
}

if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "PASS: a list navigation host lays out its items with the source list's observed box\n";
