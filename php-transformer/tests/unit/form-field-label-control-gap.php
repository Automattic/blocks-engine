<?php
declare(strict_types=1);

/**
 * Authored vertical spacing between a field's label and its control must
 * survive materialization. An exclusive field-group wrapper (flex/grid gap,
 * including cascade-layered utilities) is flattened by a provider field, so
 * the gap has to ride on the control as margin-block-start — the geometry a
 * Jetpack field shell keeps between its label and the native value — rather
 * than disappearing with the wrapper.
 *
 * Inter-field stack spacing (#1974) must stay on the form node.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$transformer = new HtmlTransformer();

$html = '<form method="post" class="card">'
    . '<div class="fields">'
    . '<div class="field"><label for="name">Name</label><input id="name" name="name"></div>'
    . '<div class="field"><label for="email">Email</label><input id="email" type="email" name="email"></div>'
    . '<div class="field"><label for="message">Message</label><textarea id="message" name="message"></textarea></div>'
    . '</div>'
    . '<button type="submit" class="submit">Send</button>'
    . '</form>';

$layoutNodes = static function (array $result): array {
    return array_column($result['fallbacks'][0]['layout_graph']['nodes'] ?? array(), null, 'id');
};
$formGap = static function (array $nodes): string {
    $layout = is_array($nodes['form']['layout'] ?? null) ? $nodes['form']['layout'] : array();
    return (string) ( $layout['row_gap'] ?? $layout['gap'] ?? '' );
};
$controlGaps = static function (array $nodes): array {
    $gaps = array();
    foreach ( $nodes as $id => $node ) {
        if ( ! is_string($id) || ! str_starts_with($id, 'control-') ) {
            continue;
        }
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $gap = (string) ( $layout['margin_block_start'] ?? '' );
        if ( '' === $gap ) {
            continue;
        }
        $gaps[$id] = $gap;
    }
    ksort($gaps, SORT_STRING);
    return $gaps;
};
$labels = static function (array $result): array {
    $names = array();
    foreach ( $result['fallbacks'][0]['controls'] ?? array() as $control ) {
        if ( is_array($control) && isset($control['label']) && 'submit' !== ( $control['type'] ?? '' ) ) {
            $names[] = $control['label'];
        }
    }
    return $names;
};

$layeredCss = '@layer properties, theme, base, components, utilities;'
    . '@layer utilities { .card { background-color: #fff; padding: 16px } .fields { display: grid; gap: 24px } .field { display: flex; flex-direction: column; gap: 8px } .submit { margin-top: 36px } }';
$layered = $transformer->transform($html, array( 'static_css' => $layeredCss ))->toArray();
$layeredNodes = $layoutNodes($layered);
$layeredGaps = $controlGaps($layeredNodes);
$layeredFallback = $layered['fallbacks'][0] ?? array();

$assert('html_form_fallback' === ($layeredFallback['diagnostic_code'] ?? null), 'layered field-group forms stay provider-materializable');
$assert('materialize_form_provider' === ($layeredFallback['suggested_repair_class'] ?? null), 'layered field-group forms still target a form provider');
$assert(array( 'Name', 'Email', 'Message' ) === $labels($layered), 'every authored field remains, in order', json_encode($labels($layered)));
$assert('24px' === $formGap($layeredNodes), 'field-list gap stays form stack spacing', $formGap($layeredNodes) . ' ' . json_encode($layeredNodes['form']['layout'] ?? null));
$assert(array( '8px', '8px', '8px' ) === array_values($layeredGaps), 'layered label-to-control gap becomes control margin-block-start a provider can apply', json_encode($layeredGaps));
$assert(str_contains((string) ($layered['serialized_blocks'] ?? ''), 'Name') && str_contains((string) ($layered['serialized_blocks'] ?? ''), 'Email') && str_contains((string) ($layered['serialized_blocks'] ?? ''), 'Message'), 'readable form markup still carries every field label');

$unlayeredCss = '.fields { display: grid; gap: 24px } .field { display: flex; flex-direction: column; gap: 8px }';
$unlayered = $transformer->transform($html, array( 'static_css' => $unlayeredCss ))->toArray();
$unlayeredNodes = $layoutNodes($unlayered);
$assert('24px' === $formGap($unlayeredNodes), 'unlayered field-list gap remains form stack spacing', $formGap($unlayeredNodes));
$assert(array( '8px', '8px', '8px' ) === array_values($controlGaps($unlayeredNodes)), 'unlayered label-to-control gap becomes the same control geometry', json_encode($controlGaps($unlayeredNodes)));

$twelveCss = '@layer utilities { .fields { display: grid; gap: 24px } .field { display: flex; flex-direction: column; gap: 12px } }';
$twelve = $transformer->transform($html, array( 'static_css' => $twelveCss ))->toArray();
$assert('24px' === $formGap($layoutNodes($twelve)), 'a different field-group gap does not steal field-list spacing', $formGap($layoutNodes($twelve)));
$assert(array( '12px', '12px', '12px' ) === array_values($controlGaps($layoutNodes($twelve))), 'the authored field-group gap is carried, not a hardcoded 8px', json_encode($controlGaps($layoutNodes($twelve))));

$rowGapCss = '@layer utilities { .field { display: flex; flex-direction: column; row-gap: 0.5rem } }';
$rowGap = $transformer->transform($html, array( 'static_css' => $rowGapCss ))->toArray();
$assert(array( '0.5rem', '0.5rem', '0.5rem' ) === array_values($controlGaps($layoutNodes($rowGap))), 'layered field-group row-gap is the same intra-field geometry', json_encode($controlGaps($layoutNodes($rowGap))));

$soloCss = '@layer utilities { .row { display: flex; flex-direction: column; gap: 8px } }';
$soloHtml = '<form method="post"><div class="row"><label for="name">Name</label><input id="name" name="name"></div><button type="submit">Send</button></form>';
$solo = $transformer->transform($soloHtml, array( 'static_css' => $soloCss ))->toArray();
$soloNodes = $layoutNodes($solo);
$assert('' === $formGap($soloNodes), 'a single-field wrapper gap is not hoisted as inter-field stack spacing', $formGap($soloNodes) . ' ' . json_encode($soloNodes['form']['layout'] ?? null));
$assert(array( '8px' ) === array_values($controlGaps($soloNodes)), 'a single-field wrapper gap still becomes label-to-control geometry', json_encode($controlGaps($soloNodes)));

$inline = $transformer->transform('<form method="post"><div class="field" style="display:flex;flex-direction:column;gap:10px"><label>Name</label><input name="name"></div><div class="field" style="display:flex;flex-direction:column;gap:10px"><label>Email</label><input type="email" name="email"></div><button type="submit">Send</button></form>')->toArray();
$assert(array( '10px', '10px' ) === array_values($controlGaps($layoutNodes($inline))), 'inline field-group gap becomes control geometry', json_encode($controlGaps($layoutNodes($inline))));

$calcCss = ':root{--spacing:.25rem} @layer utilities { .fields { display:grid; gap:calc(var(--spacing) * 6) } .field { display:flex; flex-direction:column; gap:calc(var(--spacing) * 2) } }';
$calc = $transformer->transform($html, array( 'static_css' => $calcCss ))->toArray();
$assert('calc(0.25rem * 6)' === $formGap($layoutNodes($calc)), 'resolved field-list calc gap is unchanged', $formGap($layoutNodes($calc)));
$assert(
    array( 'calc(0.25rem * 2)', 'calc(0.25rem * 2)', 'calc(0.25rem * 2)' ) === array_values($controlGaps($layoutNodes($calc))),
    'resolved field-group calc gap keeps provider-admissible leading digits',
    json_encode($controlGaps($layoutNodes($calc)))
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Form field label-control gap tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Form field label-control gap tests: {$passes} passed\n");
