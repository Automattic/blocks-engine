<?php
declare(strict_types=1);

/**
 * A shadcn field box stacks an inline label above a block control in block
 * flow and spaces them with a sibling-stacking utility (Tailwind v3
 * `.space-y-1.5>:not([hidden])~:not([hidden])`, #6918). The provider renders
 * label and control inside its own field wrapper, where that rule no longer
 * matches, and renders the label as a block. The graph keeps the field's
 * geometry by:
 *
 * - carrying the control's sibling-stacked margin onto the label as
 *   margin-block-end instead of the control (where the importer neutralises
 *   it as an inter-field gap);
 * - padding the label by the parent strut's extra leading, since the inline
 *   label's footprint was its parent's line box, not its own `leading-none`
 *   box. The label's own line height (its text metrics) is left alone.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$check = static function (bool $ok, string $message, string $detail = '') use (&$failures): void {
    if ( ! $ok ) $failures[] = $message . ('' !== $detail ? ' - ' . $detail : '');
};

$css = 'html{line-height:1.5}*,:after,:before{margin:0}'
    . '.space-y-4>:not([hidden])~:not([hidden]){margin-top:1rem;margin-bottom:0}'
    . '.space-y-1\.5>:not([hidden])~:not([hidden]){margin-top:.375rem;margin-bottom:0}'
    . '.text-sm{font-size:.875rem}.leading-none{line-height:1}'
    . '.flex{display:flex}.h-9{height:2.25rem}.w-full{width:100%}.block{display:block}';

$rows = static function (string $form) use ($css): array {
    $result = (new HtmlTransformer())->transform($form, array( 'static_css' => $css ))->toArray();
    $rows = array();
    foreach ( $result['fallbacks'][0]['presentation_graph']['controls'] ?? array() as $row ) {
        $rows[$row['index']] = $row;
    }
    return $rows;
};
$margins = static fn (array $styles): array => array_filter($styles, static fn (string $key): bool => str_starts_with($key, 'margin'), ARRAY_FILTER_USE_KEY);

$rows = $rows('<form class="space-y-4">'
    . '<div class="space-y-1.5"><label for="n" class="text-sm leading-none">Name</label><input id="n" class="flex h-9 w-full"></div>'
    . '<div class="space-y-1.5"><label for="m" class="text-sm leading-none">Message</label><textarea id="m" class="flex w-full"></textarea></div>'
    . '<div class="space-y-1.5"><label for="e" class="block text-sm leading-none">Email</label><input id="e" class="flex h-9 w-full"></div>'
    . '<div class="space-y-1.5"><label for="p" class="text-sm leading-none">Phone</label><input id="p"></div>'
    . '<button type="submit">Send</button></form>');

foreach ( array( 0 => 'input', 1 => 'textarea' ) as $index => $kind ) {
    $label = $rows[$index]['label']['styles'] ?? array();
    $control = $rows[$index]['control']['styles'] ?? array();
    $check('.375rem' === ($label['margin_block_end'] ?? null), $kind . ': the label-to-control sibling margin rides on the label', json_encode($margins($label)));
    $check(array( 'margin' => '0' ) === $margins($control), $kind . ': and is no longer the control\'s own margin', json_encode($margins($control)));
    $check('1' === ($label['line_height'] ?? null), $kind . ': the label keeps its own line height', (string) ($label['line_height'] ?? ''));
    $check('5px' === ($label['padding_top'] ?? null) && '5px' === ($label['padding_bottom'] ?? null), $kind . ': the inline label keeps its parent\'s 24px line box', json_encode(array( $label['padding_top'] ?? null, $label['padding_bottom'] ?? null )));
    $controlProvenance = array_merge(...array_column($rows[$index]['control']['provenance'] ?? array(), 'properties'));
    $check(! in_array('margin-top', $controlProvenance, true), $kind . ': the control no longer reports the sibling-stacked margin');
}

// A block label already is its own box: only the gap is carried.
$check(! isset($rows[2]['label']['styles']['padding_top']), 'a block label is not padded to a line box');
$check('.375rem' === ($rows[2]['label']['styles']['margin_block_end'] ?? null), 'a block label still carries the gap');
// An inline-block control shares the label's line: no line box is carried.
$check(! isset($rows[3]['label']['styles']['padding_top']), 'a label beside an inline control is not padded to a line box');

if ( array() !== $failures ) {
    fwrite(STDERR, implode(PHP_EOL, array_map(static fn (string $f): string => 'FAIL: ' . $f, $failures)) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Form label sibling stack gap tests passed\n");
