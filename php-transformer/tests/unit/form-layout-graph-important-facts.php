<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;

/**
 * A v3 layout graph names the base layout facts its source declared
 * `!important`. A wrapper hidden with `display:none !important` stays hidden
 * against every conflicting rule and against a later runtime `style.display`
 * toggle, unlike a plain `display:none` that a multi-step page or a
 * conditional field reveals. Consumers need that distinction from the graph,
 * which otherwise carries only the resolved value.
 */

$html = '<form method="post" action="/contact/"><input name="name" aria-label="Name">'
    . '<p id="trap" style="display:none !important"><label>Leave empty<textarea name="trap"></textarea></label></p>'
    . '<div id="step" style="display:none"><input name="phone" aria-label="Phone"></div>'
    . '<div id="sheet" class="sheet"><input name="email" aria-label="Email"></div>'
    . '<button type="submit">Send</button></form>';
$css = '.sheet{display:none!important;width:100%}';
$graph = ( new HtmlTransformer() )->transform($html, array('static_css' => $css))->toArray()['fallbacks'][0]['layout_graph'] ?? array();
$bySource = array();
foreach ( $graph['nodes'] ?? array() as $node ) {
    $bySource[$node['source']['id'] ?? ''] = $node;
}

$failures = array();
if ( array( 'display' ) !== ($bySource['trap']['important'] ?? null) || 'none' !== ($bySource['trap']['layout']['display'] ?? null) ) {
    $failures[] = 'an inline display:none !important wrapper names display as important: ' . json_encode($bySource['trap'] ?? null);
}
if ( array( 'display' ) !== ($bySource['sheet']['important'] ?? null) ) {
    $failures[] = 'a stylesheet !important fact is named only for that fact: ' . json_encode($bySource['sheet'] ?? null);
}
if ( ! isset($bySource['step']) || array_key_exists('important', $bySource['step']) ) {
    $failures[] = 'a plain display:none wrapper carries no important facts: ' . json_encode($bySource['step'] ?? null);
}

// The validator accepts the field only as a non-empty list of the node's own layout properties.
FormLayoutGraphBuilder::assertValid($graph);
foreach ( array(
    'empty list'           => array(),
    'property not in layout' => array( 'width' ),
    'not a list'           => array( 'display' => true ),
) as $name => $important ) {
    $tampered = $graph;
    foreach ( $tampered['nodes'] as &$node ) {
        if ( 'trap' === ($node['source']['id'] ?? '') ) {
            $node['important'] = $important;
        }
    }
    unset($node);
    try {
        FormLayoutGraphBuilder::assertValid($tampered);
        $failures[] = 'the validator rejects important facts with ' . $name;
    } catch ( InvalidArgumentException ) {
    }
}

if ( array() !== $failures ) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}

echo "Form layout graph important facts passed.\n";
