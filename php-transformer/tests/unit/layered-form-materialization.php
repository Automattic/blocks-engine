<?php
declare(strict_types=1);

/**
 * Native form materialization must survive an author stylesheet wrapped in
 * cascade layers (issue #1955). Traversing @layer is required so presentation
 * facts such as padding-inline still resolve; treating those layered rules as
 * unlayered structural facts is not.
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

$css = '@layer properties, theme, base, components, utilities;'
    . '@layer properties { @property --tw-border-style { syntax: "*"; inherits: false; initial-value: solid } }'
    . '@layer base { * { box-sizing: border-box; border: 0 solid; border-color: oklch(40% .06 300 / .45); margin: 0; padding: 0 } input { box-sizing: border-box } button { appearance: button } }'
    . '@layer utilities { .card { background-color: #fff; padding-inline: 14px; padding-block: 10px } .row { display: flex; flex-direction: column; gap: 8px } .field { width: 100%; padding-inline: 16px } .mt-9 { margin-top: calc(.25rem * 9) } .w-full { width: 100% } .bg-gold { background-color: oklch(79% .105 82) } .hidden { display: none } }';

$html = '<form method="post" class="card"><div class="row"><label for="name">Name</label><input id="name" class="field" name="name"></div><div class="hidden" aria-hidden="true"><label for="website">Website</label><input id="website" tabindex="-1" autocomplete="off" name="website" value=""></div><button type="submit" class="mt-9 w-full bg-gold">Send</button></form>';

$result = ( new HtmlTransformer() )->transform($html, array( 'static_css' => $css ))->toArray();
$fallback = $result['fallbacks'][0] ?? array();

$assert('html_form_fallback' === ($fallback['diagnostic_code'] ?? null), 'a layered author stylesheet still emits a provider-materializable form fallback', json_encode(array_intersect_key($fallback, array_flip(array( 'diagnostic_code', 'suggested_repair_class' )))));
$assert('materialize_form_provider' === ($fallback['suggested_repair_class'] ?? null), 'layered form controls remain mapped to form-provider materialization rather than a runtime island', (string) ($fallback['suggested_repair_class'] ?? ''));
$controls = $fallback['controls'] ?? array();
$assert(2 === count($controls), 'aria-hidden honeypots are omitted from the reported control list', json_encode($controls));
$assert('website' !== ($controls[0]['name'] ?? null) && 'website' !== ($controls[1]['name'] ?? null), 'the honeypot input is not a reported control', json_encode($controls));
$assert('submit' === ($controls[1]['type'] ?? null), 'the submit button remains the last reported control', json_encode($controls[1] ?? null));
$presentationIndexes = array_column($fallback['presentation_graph']['controls'] ?? array(), 'index');
$assert($presentationIndexes === array_values(array_intersect($presentationIndexes, array_keys($controls))), 'presentation-graph indexes stay aligned with the reported control list', json_encode($presentationIndexes));
$submitPresentation = array();
foreach ( $fallback['presentation_graph']['controls'] ?? array() as $row ) {
    if ( 1 === ($row['index'] ?? null) ) {
        $submitPresentation = $row['control']['styles'] ?? array();
        break;
    }
}
$assert('button' === ($submitPresentation['appearance'] ?? null) && '100%' === ($submitPresentation['width'] ?? null), 'submit presentation resolves layered type and utility rules at the reported submit index', json_encode($submitPresentation));

$box = $fallback['form']['container_presentation'] ?? array();
$assert(
    '#fff' === ($box['styles']['background_color'] ?? null) && '14px' === ($box['styles']['padding_inline'] ?? null) && '10px' === ($box['styles']['padding_block'] ?? null),
    'form container presentation still resolves through cascade layers including padding-inline/padding-block',
    json_encode($box)
);

$controlStyles = $fallback['presentation_graph']['controls'][0]['control']['styles'] ?? array();
$assert('border-box' === ($controlStyles['box_sizing'] ?? null) && '16px' === ($controlStyles['padding_inline'] ?? null) && '100%' === ($controlStyles['width'] ?? null), 'control presentation resolves layered type and utility rules', json_encode($controlStyles));
$assert(false === ($fallback['presentation_graph']['truncated'] ?? true), 'layered presentation graph stays complete', json_encode($fallback['presentation_graph']['diagnostics'] ?? null));
$assert(array() === ($fallback['presentation_graph']['control_containers'] ?? array()), 'a layered universal border reset is not a painted control container', json_encode($fallback['presentation_graph']['control_containers'] ?? null));

$layoutNodes = array_column($fallback['layout_graph']['nodes'] ?? array(), null, 'id');
$assert(false === ($fallback['layout_graph']['truncated'] ?? true), 'layered layout analysis does not fail closed', json_encode($fallback['layout_graph']['diagnostics'] ?? null));
$assert('flex' !== ($layoutNodes['wrapper-0']['layout']['display'] ?? null), 'layered flex utilities are not emitted as unlayered layout-graph structure', json_encode($layoutNodes['wrapper-0'] ?? null));
$assert('recoverable_with_form_provider_materialization' === ($fallback['recoverability'] ?? null), 'layered forms stay recoverable with a form provider', (string) ($fallback['recoverability'] ?? ''));

if ( $failures > 0 ) {
    fwrite(STDERR, "Layered form materialization tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Layered form materialization tests: {$passes} passed\n");
