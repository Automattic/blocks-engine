<?php
declare(strict_types=1);

/**
 * Form conversion must keep associated labels, submit copy, and stacked fields
 * (issue #1282). Source builders often use `<button type="button">` as the
 * submit control and associate labels with `for`, not wrapping markup.
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
$boxResult = $transformer->transform('<form method="post"><input name="name"><button type="submit">Send</button></form>', array('static_css' => 'form{max-width:500px;margin:0 auto;text-align:left}@media (max-width:600px){form{max-width:100%}}'))->toArray();
$box = $boxResult['fallbacks'][0]['form']['container_presentation'] ?? array();
$assert('500px' === ($box['styles']['max_width'] ?? null) && '0 auto' === ($box['styles']['margin'] ?? null) && 'left' === ($box['styles']['text_align'] ?? null) && '100%' === ($box['variants'][0]['styles']['max_width'] ?? null), 'form container owns base and responsive presentation independently of controls');

// A cascade-layered stylesheet (Tailwind v4's own default output shape) must
// resolve container and control presentation exactly like an unlayered one -
// a cascade layer only reorders precedence, it does not gate whether its
// declarations are analyzed at all. The two-value logical padding shorthand
// (`padding-inline`/`padding-block`) is as common as the margin equivalent
// this analyzer already carried and must resolve the same way.
$layeredCss = '@layer base, utilities; @layer base { input { box-sizing: border-box } } @layer utilities { .card { background-color: #fff; padding-inline: 14px; padding-block: 10px } }';
$layeredResult = $transformer->transform('<form method="post" class="card"><input name="name"><button type="submit">Send</button></form>', array('static_css' => $layeredCss))->toArray();
$layeredBox = $layeredResult['fallbacks'][0]['form']['container_presentation'] ?? array();
$assert(
    '#fff' === ($layeredBox['styles']['background_color'] ?? null) && '14px' === ($layeredBox['styles']['padding_inline'] ?? null) && '10px' === ($layeredBox['styles']['padding_block'] ?? null),
    'form container presentation resolves through a cascade layer and carries the padding-inline/padding-block shorthand',
    json_encode($layeredBox)
);
$layeredControl = $layeredResult['fallbacks'][0]['presentation_graph']['controls'][0]['control']['styles'] ?? array();
$assert(
    'border-box' === ($layeredControl['box_sizing'] ?? null),
    'control presentation resolves through a cascade layer',
    json_encode($layeredControl)
);
$statusResult = $transformer->transform('<form method="post"><input name="name"><button>Send</button><p id="result" role="status" style="margin-top:0.5rem"></p></form>')->toArray();
$assert(array('role' => 'status', 'id' => 'result', 'margin_top' => '0.5rem') === ($statusResult['fallbacks'][0]['form']['trailing_status'] ?? null), 'empty trailing status preserves identity and authored margin');
foreach (array('<p role="status">Existing message</p>', '<p role="alert"></p>', '<p role="status" aria-live="assertive"></p>', '<p role="status"><span></span></p>') as $unsupportedStatus) {
    $statusResult = $transformer->transform('<form method="post"><input name="name">' . $unsupportedStatus . '</form>')->toArray();
    $assert(!isset($statusResult['fallbacks'][0]['form']['trailing_status']), 'nonempty or non-polite status is not mapped to an empty output');
}
$serialize = static function (string $html, array $options = array()) use ($transformer): string {
    return (string) ( $transformer->transform($html, $options)->toArray()['serialized_blocks'] ?? '' );
};

$form = '<main><form method="post" action="#" aria-label="Contact">'
    . '<label for="first">First name<span aria-hidden="true">*</span></label>'
    . '<input id="first" type="text" aria-label="First name" required style="display:block;width:100%">'
    . '<label for="last">Last name</label>'
    . '<input id="last" type="text" aria-label="Last name" required style="display:block;width:100%">'
    . '<label for="email">Email</label>'
    . '<input id="email" type="email" aria-label="Email" required style="display:block;width:100%">'
    . '<button type="button"><span>Claim My Spot</span></button>'
    . '</form></main>';

$serialized = $serialize($form);
$formResult = $transformer->transform($form)->toArray();
$runtimeSubmit = $formResult['fallbacks'][0]['controls'][3] ?? array();
$assert(
    '*' === ($formResult['fallbacks'][0]['controls'][0]['required_text'] ?? null)
        && 'First name' === ($formResult['fallbacks'][0]['controls'][0]['label'] ?? null)
        && ! isset($formResult['fallbacks'][0]['controls'][1]['required_text'])
        && false === ($formResult['fallbacks'][0]['controls'][1]['required_indicator'] ?? null),
    'required marker text and visible-marker absence are captured separately from validation semantics'
);

$spacedMarker = $transformer->transform('<main><form method="post" action="#"><label for="details">Details (please include size)' . "\n\n" . '<span aria-hidden="true">*</span></label><textarea id="details" name="details" required></textarea><button type="submit">Send</button></form></main>')->toArray();
$assert(
    'Details (please include size) ' === ($spacedMarker['fallbacks'][0]['controls'][0]['label'] ?? null)
        && '*' === ($spacedMarker['fallbacks'][0]['controls'][0]['required_text'] ?? null),
    'whitespace before a required marker remains a trailing label space'
);

$assert(
    str_contains($serialized, 'Claim My Spot') && ! str_contains($serialized, '>Button<'),
    '1: type=button submit keeps visible copy instead of the type name',
    $serialized
);
$assert(
    str_contains($serialized, '<!-- wp:button') && str_contains($serialized, 'Claim My Spot'),
    '1b: submit copy lives on a core/button',
    $serialized
);
$assert(
    'submit' === ($runtimeSubmit['type'] ?? '') && 'Claim My Spot' === ($runtimeSubmit['text'] ?? ''),
    '1c: submit-like type=button exports canonical runtime submission semantics',
    json_encode($runtimeSubmit)
);
$assert(
    str_contains($serialized, 'First name') && str_contains($serialized, 'Last name') && str_contains($serialized, 'Email'),
    '2: associated labels remain visible',
    $serialized
);
$assert(
    str_contains($serialized, 'authored-input') && 3 <= substr_count($serialized, '<!-- wp:group'),
    '3: authored fields are wrapped so stacked layout survives flattening',
    $serialized
);

$nativeSubmit = $serialize('<main><form method="post" action="#"><label for="e">Email</label><input id="e" type="email" required><button type="submit">Send</button></form></main>');
$assert(
    str_contains($nativeSubmit, 'Send') && str_contains($nativeSubmit, '<!-- wp:button'),
    '4: type=submit still becomes a core/button',
    $nativeSubmit
);

$labelOf = static function (string $html) use ($transformer): string {
    $fallbacks = $transformer->transform($html)->toArray()['fallbacks'] ?? array();
    foreach ( $fallbacks as $fallback ) {
        foreach ( $fallback['controls'] ?? array() as $control ) {
            if ( isset($control['label']) && str_starts_with((string) $control['label'], 'Details') ) {
                return (string) $control['label'];
            }
        }
    }

    return '';
};

$spacedMarkerForm = '<main><form method="post" action="#" aria-label="Quote"><label for="d">Details<span aria-hidden="true">*</span></label><textarea id="d" aria-label="Details " required></textarea><button type="submit">Send</button></form></main>';
$assert(
    'Details ' === $labelOf($spacedMarkerForm),
    '5: an accessible name keeps the space that separates it from a decorative required marker',
    json_encode($labelOf($spacedMarkerForm))
);

$plainMarkerForm = '<main><form method="post" action="#" aria-label="Quote"><label for="d2">Details<span aria-hidden="true">*</span></label><textarea id="d2" aria-label="Details" required></textarea><button type="submit">Send</button></form></main>';
$assert(
    'Details' === $labelOf($plainMarkerForm),
    '6: an accessible name without that separator is reported unchanged',
    json_encode($labelOf($plainMarkerForm))
);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "form associated-label/submit tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "form associated-label/submit tests: {$passes} passed" . PHP_EOL);
