<?php
declare(strict_types=1);

/**
 * A sibling-stacking field group (Tailwind's `space-y-1.5`) spaces an inline
 * `for` label from its control in block flow: the label sits alone in a line
 * box as tall as the parent's line-height, and the control's sibling-combinator
 * margin-top spaces it below that line. The provider field neutralizes the
 * control's sibling-stacking margin as inter-field spacing, so the label must
 * own the whole label-to-control distance: the parent's line box as
 * block padding around its own (unchanged) line-height, and the control's
 * margin as its margin-block-end. The label's typography stays the source's.
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

$css = 'html{line-height:1.5} body{line-height:inherit}'
    . ' .space-y-4>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1rem * calc(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1rem * var(--tw-space-y-reverse))}'
    . ' .space-y-1\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.375rem * calc(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.375rem * var(--tw-space-y-reverse))}'
    . ' .text-sm{font-size:.875rem} .leading-none{line-height:1} .h-9{height:2.25rem} .w-full{width:100%}';
$html = '<form method="post" class="space-y-4">'
    . '<div class="space-y-1.5"><label class="text-sm leading-none" for="name">Full Name</label><input class="h-9 w-full" id="name" name="name"></div>'
    . '<div class="space-y-1.5"><label class="text-sm leading-none" for="message">Message</label><textarea class="w-full" id="message" name="message"></textarea></div>'
    . '<button type="submit">Send</button>'
    . '</form>';

$result = (new HtmlTransformer())->transform($html, array( 'static_css' => $css ))->toArray();
$rows = $result['fallbacks'][0]['presentation_graph']['controls'] ?? array();

foreach ( array( 0 => 'Full Name', 1 => 'Message' ) as $index => $name ) {
    $row = $rows[$index] ?? array();
    $label = is_array($row['label']['styles'] ?? null) ? $row['label']['styles'] : array();
    $control = is_array($row['control']['styles'] ?? null) ? $row['control']['styles'] : array();
    $assert('1' === ($label['line_height'] ?? null), $name . ' label keeps its own line-height', json_encode($label));
    $assert('5px' === ($label['padding_top'] ?? null) && '5px' === ($label['padding_bottom'] ?? null), $name . ' label pads its own line to the line box its block parent gives an inline label', json_encode($label));
    $assert('calc(.375rem * calc(1 - 0))' === ($label['margin_block_end'] ?? null), $name . ' label carries the control sibling margin to its control', json_encode($label));
    $assert(! isset($control['margin_top']) && ! isset($control['margin_bottom']), $name . ' control keeps no second copy of the label spacing', json_encode($control));
    $sources = array();
    foreach ( $row['label']['provenance'] ?? array() as $fact ) foreach ( $fact['properties'] as $property ) $sources[$property][] = $fact['selector'];
    $assert(in_array('html', $sources['padding-top'] ?? array(), true) && str_contains(implode(',', $sources['margin-block-end'] ?? array()), '~'), $name . ' carried label spacing names its source rules', json_encode($row['label']['provenance'] ?? null));
}

// A flex field group blockifies the label: no anonymous line box, no carry.
$flex = (new HtmlTransformer())->transform(str_replace('class="space-y-1.5"', 'class="space-y-1.5" style="display:flex;flex-direction:column"', $html), array( 'static_css' => $css ))->toArray();
$flexLabel = $flex['fallbacks'][0]['presentation_graph']['controls'][0]['label']['styles'] ?? array();
$assert('1' === ($flexLabel['line_height'] ?? null) && ! isset($flexLabel['margin_block_end']), 'a flex field group leaves the label spacing to the layout graph', json_encode($flexLabel));

if ( $failures > 0 ) {
    fwrite(STDERR, "Form sibling-stacked label spacing tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Form sibling-stacked label spacing tests: {$passes} passed\n");
