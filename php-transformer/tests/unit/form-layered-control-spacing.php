<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A provider wraps each input in a field shell. Layered sibling spacing must
// identify that outer box, not remain only in the native-control paint graph.
$source = '<style>@layer utilities { .stack > :not(:last-child) { margin-block-start:0; margin-block-end:16px } }</style>'
    . '<main><form class="stack"><input placeholder="Name"><input placeholder="Vehicle">'
    . '<button type="submit">Book</button></form></main>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'booking.html', 'files' => array('booking.html' => $source)))->toArray();
$form = $result['fallbacks'][0] ?? array();
$nodes = array_column($form['layout_graph']['nodes'] ?? array(), null, 'id');
foreach (array(0, 1) as $index) {
    $node = $nodes['control-' . $index] ?? array();
    if ('16px' !== ($node['layout']['margin_block_end'] ?? null) || '0' !== ($node['layout']['margin_block_start'] ?? null)) {
        fwrite(STDERR, 'FAIL: layered control spacing lost its layout owner: ' . json_encode($node) . PHP_EOL);
        exit(1);
    }
}
if (isset($nodes['control-2']['layout']['margin_block_end'])) {
    fwrite(STDERR, 'FAIL: last control acquired sibling spacing' . PHP_EOL);
    exit(1);
}
echo 'PASS: layered control margins retain their structural owner' . PHP_EOL;
