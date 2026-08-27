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
$serialize = static function (string $html, array $options = array()) use ($transformer): string {
    return (string) ( $transformer->transform($html, $options)->toArray()['serialized_blocks'] ?? '' );
};

$form = '<main><form aria-label="Contact">'
    . '<label for="first">First name<span aria-hidden="true">*</span></label>'
    . '<input id="first" type="text" aria-label="First name" required style="display:block;width:100%">'
    . '<label for="last">Last name</label>'
    . '<input id="last" type="text" aria-label="Last name" required style="display:block;width:100%">'
    . '<label for="email">Email</label>'
    . '<input id="email" type="email" aria-label="Email" required style="display:block;width:100%">'
    . '<button type="button"><span>Claim My Spot</span></button>'
    . '</form></main>';

$serialized = $serialize($form);

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
    str_contains($serialized, 'First name') && str_contains($serialized, 'Last name') && str_contains($serialized, 'Email'),
    '2: associated labels remain visible',
    $serialized
);
$assert(
    str_contains($serialized, 'authored-input') && 3 <= substr_count($serialized, '<!-- wp:group'),
    '3: authored fields are wrapped so stacked layout survives flattening',
    $serialized
);

$nativeSubmit = $serialize('<main><form><label for="e">Email</label><input id="e" type="email" required><button type="submit">Send</button></form></main>');
$assert(
    str_contains($nativeSubmit, 'Send') && str_contains($nativeSubmit, '<!-- wp:button'),
    '4: type=submit still becomes a core/button',
    $nativeSubmit
);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "form associated-label/submit tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "form associated-label/submit tests: {$passes} passed" . PHP_EOL);
