<?php
declare(strict_types=1);

/**
 * A form label declared through the `flex-flow` shorthand keeps the same
 * presentation facts as one declared with the `flex-direction` longhand: the
 * presentation graph expands the shorthand, so the direction token becomes
 * `flex_direction` and the wrap token becomes `flex_wrap` instead of being
 * dropped to the allowlist and lost to the projected field boxes.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

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

/** @return array<string, array{styles: array<string, string>, provenance: array<string, mixed>}> Control rows keyed by role. */
$roles = static function (string $css, string $label = '<label class="l"><span>Name</span><input type="text" name="name"></label>'): array {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body><form class="f" method="post" action="/contact">' . $label . '<button type="submit">Send</button></form></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $result = (new FormPresentationGraphBuilder())->build($document->getElementsByTagName('form')->item(0), array(), $css);
    $row = $result['controls'][0] ?? array();
    unset($row['index']);
    return $row;
};

$facts = static fn (array $row, string $role): array => $row[$role]['styles'] ?? array();

$shorthand = $roles('.l { display: flex; flex-flow: column; align-items: flex-start; gap: 10px; width: 100%; }');
$longhand = $roles('.l { display: flex; flex-direction: column; align-items: flex-start; gap: 10px; width: 100%; }');
$assert('column' === ($facts($shorthand, 'label')['flex_direction'] ?? null) && 'nowrap' === ($facts($shorthand, 'label')['flex_wrap'] ?? null), 'flex-flow: column yields a flex_direction fact and resets the omitted wrap to its initial value', json_encode($facts($shorthand, 'label')));
$assert('column' === ($facts($longhand, 'label')['flex_direction'] ?? null) && ! isset($facts($longhand, 'label')['flex_wrap']), 'the longhand label reports the same direction without a wrap reset', json_encode($facts($longhand, 'label')));

$wrapped = $roles('.l { display: flex; flex-flow: column wrap; }');
$assert('column' === ($facts($wrapped, 'label')['flex_direction'] ?? null) && 'wrap' === ($facts($wrapped, 'label')['flex_wrap'] ?? null), 'flex-flow: column wrap reports both tokens', json_encode($facts($wrapped, 'label')));

$omitted = $roles('.l { display: flex; flex-flow: wrap-reverse; }');
$assert('row' === ($facts($omitted, 'label')['flex_direction'] ?? null) && 'wrap-reverse' === ($facts($omitted, 'label')['flex_wrap'] ?? null), 'an omitted token is its initial value', json_encode($facts($omitted, 'label')));

$inline = $roles('', '<label class="l" style="display:flex;flex-flow:column;gap:6px"><span>Name</span><input type="text" name="name"></label>');
$assert('column' === ($facts($inline, 'label')['flex_direction'] ?? null) && '6px' === ($facts($inline, 'label')['gap'] ?? null), 'an inline flex-flow expands too', json_encode($facts($inline, 'label')));

$longhandWins = $roles('.l { display: flex; flex-flow: column; } .l { flex-direction: row; }');
$assert('row' === ($facts($longhandWins, 'label')['flex_direction'] ?? null) && 'nowrap' === ($facts($longhandWins, 'label')['flex_wrap'] ?? null), 'a later longhand overrides the shorthand direction but not its wrap reset', json_encode($facts($longhandWins, 'label')));

$provenanceRow = $roles('.l { display: flex; flex-flow: column; }');
$properties = array_merge(...array_map(static fn (array $fact): array => $fact['properties'], $provenanceRow['label']['provenance'] ?? array()));
$assert(in_array('flex-direction', $properties, true) && in_array('flex-wrap', $properties, true) && ! in_array('flex-flow', $properties, true), 'expanded longhands carry the shorthand rule as their provenance', json_encode($properties));

$unresolvedForm = static function (): DOMElement {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body><form method="post" action="/contact"><label class="l" style="display:flex;flex-flow:var(--flow);gap:4px"><span>Name</span><input type="text" name="name"></label></form></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    return $document->getElementsByTagName('form')->item(0);
};
$unresolved = (new FormPresentationGraphBuilder())->build($unresolvedForm(), array(), '');
$unresolvedLabel = array();
foreach ( $unresolved['controls'][0] ?? array() as $role => $cell ) {
    if ( is_array($cell) ) $unresolvedLabel = $cell['styles'] ?? array();
}
$assert(false === ($unresolved['truncated'] ?? true) && '4px' === ($unresolvedLabel['gap'] ?? null) && ! isset($unresolvedLabel['flex_direction']) && in_array('unsupported_flex_flow', $unresolved['diagnostics'] ?? array(), true), 'an unresolved flex-flow is named without discarding the rest of the graph', json_encode(array( 'styles' => $unresolvedLabel, 'diagnostics' => $unresolved['diagnostics'] ?? array() )));

if ( 0 < $failures ) {
    fwrite(STDERR, "form presentation flex-flow FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "form presentation flex-flow passed: {$passes} assertions\n";
