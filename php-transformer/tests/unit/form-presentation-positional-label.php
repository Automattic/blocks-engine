<?php
declare(strict_types=1);

/**
 * Client-rendered forms often state a label's association by position only:
 * one `<label>` without `for` beside one control in its own field wrapper.
 * Form metadata already carries that label's text to the provider field. The
 * presentation graph must resolve the same label, or the provider's default
 * label styles (Jetpack: bold) replace the source label's own presentation.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$html = '<style>.lbl{display:block;font-size:.875rem;margin-bottom:.375rem}.in{width:100%}</style>'
    . '<form method="post">'
    . '<div><label class="lbl">Your Name</label><input class="in" name="name"></div>'
    . '<div><label class="lbl">Email</label><input class="in" type="email" name="email"></div>'
    . '<button type="submit">Send</button>'
    . '</form>';
$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$rows = array_column($result['fallbacks'][0]['presentation_graph']['controls'] ?? array(), null, 'index');

foreach ( array( 0, 1 ) as $index ) {
    $label = $rows[$index]['label']['styles'] ?? null;
    $assert(is_array($label), "positional label for control {$index} is captured", json_encode($rows[$index] ?? null));
    $assert('.875rem' === ($label['font_size'] ?? null), "control {$index} label keeps its authored font size", json_encode($label));
    $assert(! isset($label['font_weight']), "control {$index} label records no weight it never authored", json_encode($label));
}

// Shared wrapper: one label cannot be attributed to either of two controls.
$shared = ( new HtmlTransformer() )->transform(
    '<style>.lbl{font-size:.875rem}</style><form method="post"><div><label class="lbl">Range</label><input name="a"><input name="b"></div></form>',
    array()
)->toArray();
foreach ( $shared['fallbacks'][0]['presentation_graph']['controls'] ?? array() as $row ) {
    $assert(! isset($row['label']), 'a label shared by two controls is not attributed to one', json_encode($row));
}

if ( $failures > 0 ) {
    fwrite(STDERR, "form presentation positional label: {$failures} failed, {$passes} passed\n");
    exit(1);
}
echo "form presentation positional label: {$passes} passed\n";
