<?php
declare(strict_types=1);

/**
 * Site builders emit content directly inside a <ul> — a form list that opens
 * with a heading and a "required field" note is a common shape. A browser
 * renders that content, but core/list carries list items only, so converting
 * such a list to core/list drops it silently. The list has to decompose
 * instead, keeping every child in document order.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$transform = static fn (string $html): string => (string) ( ( new HtmlTransformer() )->transform($html, array())->toArray()['serialized_blocks'] ?? '' );

// The captured Weebly contact form: heading and required-field note sit
// directly inside <ul class="formlist">, ahead of the fields.
$formList = $transform(
    '<main><ul class="formlist">'
    . '<h2>Please leave a message and I will get back to you soon!</h2>'
    . '<label><span>*</span> Indicates required field</label>'
    . '<li>Your contact details</li>'
    . '</ul></main>'
);
foreach ( array( 'Please leave a message and I will get back to you soon!', 'Indicates required field', 'Your contact details' ) as $content ) {
    $assert(
        str_contains($formList, $content),
        'content directly inside a list survives: ' . $content,
        $formList
    );
}
$assert(
    strpos($formList, 'Please leave a message') < strpos($formList, 'Indicates required field')
        && strpos($formList, 'Indicates required field') < strpos($formList, 'Your contact details'),
    'the surviving content keeps its document order',
    $formList
);

// An embedded control with no text still counts as content.
$embedded = $transform('<main><ul><input type="text" name="q"><li>Item</li></ul></main>');
$assert(
    str_contains($embedded, 'wp:group') || str_contains($embedded, 'input'),
    'a non-item form control inside a list is not dropped',
    $embedded
);

// An ordinary list is untouched and stays a core/list.
$plain = $transform('<main><ul><li>One</li><li>Two</li></ul></main>');
$assert(
    str_contains($plain, '<!-- wp:list ') || str_contains($plain, '<!-- wp:list-->') || str_contains($plain, '<!-- wp:list -->'),
    'an ordinary list still converts to core/list',
    $plain
);
$assert(
    str_contains($plain, 'One') && str_contains($plain, 'Two'),
    'an ordinary list keeps its items',
    $plain
);

// Whitespace and script-only children must not force decomposition.
$scripted = $transform('<main><ul><script>var a=1;</script><li>One</li><li>Two</li></ul></main>');
$assert(
    str_contains($scripted, '<!-- wp:list ') || str_contains($scripted, '<!-- wp:list -->'),
    'a script-only non-item child does not decompose the list',
    $scripted
);

if ( 0 < $failures ) {
    fwrite(STDERR, "list keeps non-item content FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "list keeps non-item content passed: {$passes} assertions\n";
