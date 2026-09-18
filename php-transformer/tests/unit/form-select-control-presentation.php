<?php
declare(strict_types=1);

/**
 * Authored select control presentation must survive the same capture path
 * that already carries padding, border, and box-sizing onto text inputs.
 *
 * A provider that materializes the native <select> from these facts can
 * reconstruct the source control height: with box-sizing:border-box,
 * padding-block + border-width are the geometry that sizes the control.
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
    . '@layer base { * { box-sizing: border-box; border: 0 solid; margin: 0; padding: 0 }'
    . ' button,input,select,optgroup,textarea{font:inherit;color:inherit;background-color:#0000;border-radius:0} }'
    . '@layer utilities { .w-full { width: 100% } .px-4 { padding-inline: 16px } .py-3 { padding-block: 12px }'
    . ' .border { border-style: solid } .border-border { border-width: 1px } }';

$html = '<form method="post">'
    . '<label for="name">Name</label>'
    . '<input id="name" class="w-full border border-border px-4 py-3" name="name">'
    . '<label for="category">Category</label>'
    . '<select id="category" class="w-full border border-border px-4 py-3" name="category"><option>One</option></select>'
    . '<button type="submit">Send</button>'
    . '</form>';

$result = ( new HtmlTransformer() )->transform($html, array( 'static_css' => $css ))->toArray();
$fallback = $result['fallbacks'][0] ?? array();
$controls = $fallback['controls'] ?? array();
$rows     = array();
foreach ( $fallback['presentation_graph']['controls'] ?? array() as $row ) {
    if ( is_array($row) && isset($row['index'], $row['control']['styles']) ) {
        $rows[ $row['index'] ] = $row['control']['styles'];
    }
}

$assert('html_form_fallback' === ($fallback['diagnostic_code'] ?? null), 'a form with an authored select stays provider-materializable');
$assert('select' === ($controls[1]['tag'] ?? null) && 'select' === ($controls[1]['type'] ?? null), 'the select remains a reported control', json_encode($controls[1] ?? null));
$assert(isset($rows[0], $rows[1]), 'input and select both receive control presentation rows', json_encode(array_keys($rows)));

$input  = $rows[0];
$select = $rows[1];
$assert('12px' === ($input['padding_block'] ?? null) && '16px' === ($input['padding_inline'] ?? null) && '1px' === ($input['border_width'] ?? null), 'input padding and border survive capture', json_encode($input));
$assert('12px' === ($select['padding_block'] ?? null) && '16px' === ($select['padding_inline'] ?? null) && '1px' === ($select['border_width'] ?? null), 'select padding and border survive the same capture path', json_encode($select));
$assert('border-box' === ($select['box_sizing'] ?? null) && '100%' === ($select['width'] ?? null), 'select keeps border-box sizing and authored width', json_encode($select));
$assert(
    ($input['padding_block'] ?? null) === ($select['padding_block'] ?? false)
        && ($input['border_width'] ?? null) === ($select['border_width'] ?? false)
        && ($input['box_sizing'] ?? null) === ($select['box_sizing'] ?? false),
    'select geometry facts match the sibling input that already materializes correctly',
    json_encode(array( 'input' => $input, 'select' => $select ))
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Form select control presentation tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Form select control presentation tests: {$passes} passed\n");
