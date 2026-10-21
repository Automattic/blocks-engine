<?php
declare(strict_types=1);

/**
 * The `gap` shorthand and `row-gap`/`column-gap` set the same two values. When
 * both reach one box, the form layout graph states the row and column gaps that
 * hold after the cascade, including declaration order inside one rule, instead
 * of two facts a consumer cannot order.
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

$gaps = static function (string $css, string $rowStyle = ''): array {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body><form class="f"><div class="row"' . ( '' !== $rowStyle ? ' style="' . $rowStyle . '"' : '' ) . '><label>Name <input type="text" name="name"></label><label>Mail <input type="email" name="mail"></label></div><button type="submit">Send</button></form></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $form = $document->getElementsByTagName('form')->item(0);
    $graph = (new FormLayoutGraphBuilder())->build($form, array(), '.row { display: grid; grid-template-columns: repeat(2, 1fr); } ' . $css);
    $node = array_column($graph['nodes'], null, 'id')['wrapper-0'] ?? array();
    $layout = array_intersect_key($node['layout'] ?? array(), array_flip(array( 'gap', 'row_gap', 'column_gap' )));
    ksort($layout);
    $properties = array();
    foreach ( $node['provenance'] ?? array() as $entry ) {
        $properties = array_merge($properties, $entry['properties']);
    }
    return array( 'layout' => $layout, 'properties' => $properties );
};

$cases = array(
    'a longhand after the shorthand in one rule wins' => array( '.row { gap: 40px; column-gap: 10px; }', '', array( 'column_gap' => '10px', 'row_gap' => '40px' ) ),
    'a shorthand after the longhand in one rule wins' => array( '.row { column-gap: 10px; gap: 40px; }', '', array( 'column_gap' => '40px', 'row_gap' => '40px' ) ),
    'a later rule shorthand wins' => array( '.row { column-gap: 10px; } .row { gap: 40px; }', '', array( 'column_gap' => '40px', 'row_gap' => '40px' ) ),
    'a more specific longhand wins over a later shorthand' => array( 'div.row { column-gap: 10px; } .row { gap: 40px; }', '', array( 'column_gap' => '10px', 'row_gap' => '40px' ) ),
    'a two-value shorthand sets row then column' => array( '.row { row-gap: 8px; gap: 12px 40px; }', '', array( 'column_gap' => '40px', 'row_gap' => '12px' ) ),
    'inline declarations resolve in their own order' => array( '', 'gap: 40px; column-gap: 10px', array( 'column_gap' => '10px', 'row_gap' => '40px' ) ),
    'a longhand that agrees with the shorthand stays as authored' => array( '.row { gap: 24px; column-gap: 24px; }', '', array( 'column_gap' => '24px', 'gap' => '24px' ) ),
    'a shorthand alone stays as authored' => array( '.row { gap: 12px 40px; }', '', array( 'gap' => '12px 40px' ) ),
    'a custom-property shorthand is not split' => array( '.row { gap: var(--g); column-gap: 10px; }', '', array( 'column_gap' => '10px', 'gap' => 'var(--g)' ) ),
);
foreach ( $cases as $message => $case ) {
    $actual = $gaps($case[0], $case[1]);
    $assert($case[2] === $actual['layout'], $message, json_encode($actual['layout']));
}

$resolved = $gaps('.row { gap: 40px; column-gap: 10px; }');
$assert(! in_array('gap', $resolved['properties'], true) && in_array('row-gap', $resolved['properties'], true) && in_array('column-gap', $resolved['properties'], true), 'resolved longhands carry their own provenance', json_encode($resolved['properties']));

if ( 0 < $failures ) {
    fwrite(STDERR, "form layout gap longhands FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "form layout gap longhands passed: {$passes} assertions\n";
