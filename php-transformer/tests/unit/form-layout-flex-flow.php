<?php
declare(strict_types=1);

/**
 * Builders commonly declare flex direction and wrap through the `flex-flow`
 * shorthand. The form layout graph expands it into its longhands, so a
 * column-flowing form or panel is not reported as the initial row.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$graph = static function (string $css, string $formStyle = ''): array {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body><form class="f"' . ( '' !== $formStyle ? ' style="' . $formStyle . '"' : '' ) . '><div class="row"><label>Name <input type="text" name="name"></label><label>Mail <input type="email" name="mail"></label></div><button type="submit">Send</button></form></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $form = $document->getElementsByTagName('form')->item(0);
    $result = (new FormLayoutGraphBuilder())->build($form, array(), $css);
    return array( 'graph' => $result, 'nodes' => array_column($result['nodes'], null, 'id') );
};

$column = $graph('.f { display: flex; flex-flow: column; gap: 20px; } .row { display: flex; flex-flow: row wrap; gap: 8px; }');
$assert('column' === ($column['nodes']['form']['layout']['direction'] ?? null) && 'nowrap' === ($column['nodes']['form']['layout']['wrap'] ?? null), 'flex-flow: column reaches the graph as a nowrap column', json_encode($column['nodes']['form']['layout'] ?? null));
$assert('row' === ($column['nodes']['wrapper-0']['layout']['direction'] ?? null) && 'wrap' === ($column['nodes']['wrapper-0']['layout']['wrap'] ?? null), 'flex-flow: row wrap reaches the graph as a wrapping row', json_encode($column['nodes']['wrapper-0']['layout'] ?? null));
$provenance = $column['nodes']['form']['provenance'][0]['properties'] ?? array();
$assert(in_array('flex-direction', $provenance, true) && in_array('flex-wrap', $provenance, true) && ! in_array('flex-flow', $provenance, true), 'expanded longhands carry the shorthand rule as their provenance', json_encode($provenance));

$reversedOrder = $graph('.f { display: flex; flex-flow: wrap-reverse column-reverse; }');
$assert('column-reverse' === ($reversedOrder['nodes']['form']['layout']['direction'] ?? null) && 'wrap-reverse' === ($reversedOrder['nodes']['form']['layout']['wrap'] ?? null), 'flex-flow keywords are read in either order', json_encode($reversedOrder['nodes']['form']['layout'] ?? null));

$longhandWins = $graph('.f { display: flex; flex-flow: column; } .f { flex-direction: row; }');
$assert('row' === ($longhandWins['nodes']['form']['layout']['direction'] ?? null) && 'nowrap' === ($longhandWins['nodes']['form']['layout']['wrap'] ?? null), 'a later longhand overrides the shorthand direction only', json_encode($longhandWins['nodes']['form']['layout'] ?? null));

$inline = $graph('.f { display: flex; }', 'flex-flow: column wrap');
$assert('column' === ($inline['nodes']['form']['layout']['direction'] ?? null) && 'wrap' === ($inline['nodes']['form']['layout']['wrap'] ?? null), 'an inline flex-flow expands too', json_encode($inline['nodes']['form']['layout'] ?? null));

$unresolved = $graph('.f { display: flex; flex-flow: var(--flow); gap: 4px; }');
$assert(false === $unresolved['graph']['truncated'] && '4px' === ($unresolved['nodes']['form']['layout']['gap'] ?? null) && ! isset($unresolved['nodes']['form']['layout']['direction']) && in_array('unsupported_flex_flow', $unresolved['graph']['diagnostics'], true), 'an unresolved flex-flow is named without discarding the rest of the graph', json_encode($unresolved['graph']['diagnostics']));

if ( 0 < $failures ) {
    fwrite(STDERR, "form layout flex-flow FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "form layout flex-flow passed: {$passes} assertions\n";
