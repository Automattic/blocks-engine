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

// A choice label that declares no typography itself takes it from its sole
// text carrier — the element the cascade actually paints — resolved through
// custom properties set on an ancestor of the form.
$carrier = ( new HtmlTransformer() )->transform(
    '<style>.t{font-size:var(--l-size,12px);color:var(--l-color,black)}</style>'
    . '<div style="--l-size:15px;--l-color:#f3f2ed"><form method="post">'
    . '<label><div><p class="t">Yes, subscribe me</p></div><input type="checkbox" name="optin"></label>'
    . '<button type="submit">Go</button></form></div>',
    array()
)->toArray();
$rows = array_column($carrier['fallbacks'][0]['presentation_graph']['controls'] ?? array(), null, 'index');
$label = $rows[0]['label']['styles'] ?? null;
$assert(is_array($label), 'the choice label role is captured', json_encode($rows[0] ?? null));
$assert('15px' === ($label['font_size'] ?? null), 'the label reads its font size from its text carrier', json_encode($label));
$assert('#f3f2ed' === ($label['color'] ?? null), 'the label reads its color from its text carrier', json_encode($label));

// The deepest text carrier often declares nothing: the typography sits on an
// intermediate carrier (`<p>`) and inherits into the spans that hold the text.
// A required marker beside the text does not count as a second carrier.
$nested = ( new HtmlTransformer() )->transform(
    '<style>.t{font-size:var(--l-size,12px);color:var(--l-color,black)}</style>'
    . '<div style="--l-size:15px;--l-color:#f3f2ed"><form method="post">'
    . '<label><input type="checkbox" name="optin" required><span class="box"><span></span></span>'
    . '<div><div><p class="t"><span><span>Yes, subscribe me</span><span aria-hidden="true">*</span></span></p></div></div></label>'
    . '<button type="submit">Go</button></form></div>',
    array()
)->toArray();
$rows = array_column($nested['fallbacks'][0]['presentation_graph']['controls'] ?? array(), null, 'index');
$label = $rows[0]['label']['styles'] ?? null;
$assert('15px' === ($label['font_size'] ?? null), 'a nested label inherits its font size from the declaring carrier', json_encode($rows[0] ?? null));
$assert('#f3f2ed' === ($label['color'] ?? null), 'a nested label inherits its color from the declaring carrier', json_encode($label));

// A label that declares typography itself keeps it; the carrier never adds to it.
$authored = ( new HtmlTransformer() )->transform(
    '<style>.lbl{font-size:.875rem}.t{font-size:var(--l-size,12px);color:var(--l-color,#111111)}</style>'
    . '<div style="--l-size:15px;--l-color:#f3f2ed"><form method="post">'
    . '<label class="lbl"><div><p class="t">Yes, subscribe me</p></div><input type="checkbox" name="optin"></label>'
    . '<button type="submit">Go</button></form></div>',
    array()
)->toArray();
$rows = array_column($authored['fallbacks'][0]['presentation_graph']['controls'] ?? array(), null, 'index');
$label = $rows[0]['label']['styles'] ?? null;
$assert('.875rem' === ($label['font_size'] ?? null), 'a label that declares its own font size keeps it', json_encode($label));
$assert(! isset($label['color']), 'the carrier adds nothing once the label declares typography itself', json_encode($label));

if ( $failures > 0 ) {
    fwrite(STDERR, "form presentation positional label: {$failures} failed, {$passes} passed\n");
    exit(1);
}
echo "form presentation positional label: {$passes} passed\n";
