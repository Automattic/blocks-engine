<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;

$build = static function (string $sibling = '', string $prefix = '', string $css = ''): array {
    $document = new DOMDocument();
    $document->loadHTML('<form>' . $prefix . '<div id="column" style="display:flex;flex-direction:column;align-items:stretch">' . "\n" . '<div class="submit-row"><button type="submit">Send</button></div>' . $sibling . "\n</div></form>", LIBXML_NOERROR | LIBXML_NOWARNING);
    return (new FormLayoutGraphBuilder())->build($document->getElementsByTagName('form')->item(0), array(), $css);
};

$cases = array(
    'single submit row' => array('', '', '', 1),
    'text note' => array(' A note ', '', '', 2),
    'sibling div' => array('<div>Note</div>', '', '', 2),
    'hidden sibling' => array('<div class="hidden">Note</div>', '', '.hidden { display:none }', 1),
    'absolute sibling' => array('<div class="floating">Note</div>', '', '.floating { position:absolute }', 1),
    'whitespace and comments' => array(" \n<!-- note -->\t", '', '', 1),
    'hidden native input' => array('<input type="hidden" name="token">', '', '', 1),
    'non-authored trap wrapper' => array('<div><input name="trap" tabindex="-1" aria-hidden="true"></div>', '', '', 1),
    'fixed sibling' => array('<div style="position:fixed">Note</div>', '', '', 1),
    'inline cascade winner' => array('<div class="hidden" style="display:block">Note</div>', '', '.hidden { display:none }', 2),
    'custom property' => array('<div style="--mode:none;display:var(--mode)">Note</div>', '', '', 1),
    'late wrapper' => array('', '<div>' . str_repeat('Earlier markup ', 200) . '</div>', '', 1),
);
foreach ( $cases as $name => [ $sibling, $prefix, $css, $expected ] ) {
    $graph = $build($sibling, $prefix, $css);
    $column = array_values(array_filter($graph['nodes'], static fn (array $node): bool => 'column' === ($node['source']['id'] ?? null)))[0];
    if ( $expected !== ($column['in_flow_children'] ?? null) ) {
        fwrite(STDERR, 'FAIL: ' . $name . ': ' . json_encode($column) . PHP_EOL);
        exit(1);
    }
    foreach ( $graph['nodes'] as &$node ) unset($node['in_flow_children']);
    unset($node);
    FormLayoutGraphBuilder::assertValid($graph);
}

foreach ( array(-1, '1', 1.5, null) as $invalid ) {
    $graph = $build();
    $graph['nodes'][0]['in_flow_children'] = $invalid;
    try {
        FormLayoutGraphBuilder::assertValid($graph);
        throw new RuntimeException('Invalid child count accepted: ' . json_encode($invalid));
    } catch (InvalidArgumentException $expected) {
        // Counts must be non-negative integers when present.
    }
}
$graph = $build('', '', 'button { display:block }');
$controlIndex = array_search('control', array_column($graph['nodes'], 'kind'), true);
$graph['nodes'][$controlIndex]['in_flow_children'] = 0;
try {
    FormLayoutGraphBuilder::assertValid($graph);
    throw new RuntimeException('Control node accepted a child count');
} catch (InvalidArgumentException $expected) {
    // Only containers carry child counts.
}
echo "form layout in-flow children passed\n";
