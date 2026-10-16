<?php
declare(strict_types=1);

/**
 * A form whose only fields are fixed hidden values and whose one submit sends
 * the browser to an absolute endpoint on another origin is a complete native
 * workflow. It lowers to the native form companion instead of disappearing
 * behind a provider fallback that has no user fields to materialize.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $label;
    }
};
$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html)->toArray();
$formFallbacks = static fn (array $result): array => array_values(array_filter(
    $result['fallbacks'] ?? array(),
    static fn (array $fallback): bool => 'html_form_fallback' === ($fallback['diagnostic_code'] ?? '')
));
$form = static fn (string $attributes, string $controls = '<input type="hidden" name="item" value="A1"><input type="image" src="https://cdn.example.test/give.gif" alt="Give" title="Give now" width="120" height="40">'): string
    => '<p>To help:</p><div class="embed"><form ' . $attributes . '><input type="hidden" name="cmd" value="give">' . $controls . '</form></div>';

$external = $transform($form('action="https://payments.example.test/checkout" method="post" target="_top"'));
$markup = (string) ($external['serialized_blocks'] ?? '');
$assert(str_contains($markup, '<!-- wp:custom/authored-native-form'), 'external hidden-field submission is owned by the native form companion');
$assert(str_contains($markup, '<form action="https://payments.example.test/checkout" target="_top" method="post">'), 'saved form keeps its endpoint, target, and POST method');
$assert(str_contains($markup, '<input type="hidden" name="cmd" value="give">') && str_contains($markup, '<input type="hidden" name="item" value="A1">'), 'fixed hidden fields are submitted unchanged');
$assert(str_contains($markup, '<input type="image" src="https://cdn.example.test/give.gif" alt="Give" width="120" height="40" title="Give now">'), 'image submit keeps the picture that paints it');
$assert(! str_contains($markup, '<!-- wp:html') && ! str_contains($markup, 'blocks-engine-empty-visual-group'), 'submission is neither raw HTML nor dropped');
$assert(array() === $formFallbacks($external), 'self-contained submission needs no form provider');

$button = $transform($form('action="http://payments.example.test/give" method="get"', '<button type="submit">Give</button>'));
$assert(str_contains((string) ($button['serialized_blocks'] ?? ''), '<form action="http://payments.example.test/give" method="get">') && array() === $formFallbacks($button), 'a single submit button qualifies on GET as well');

$declined = array(
    'user-editable field' => $form('action="https://payments.example.test/checkout" method="post"', '<input type="text" name="amount"><input type="submit" value="Give">'),
    'relative action' => $form('action="/checkout" method="post"', '<input type="submit" value="Give">'),
    'scripted action' => $form('action="javascript:give()" method="post"', '<input type="submit" value="Give">'),
    'multipart body' => $form('action="https://payments.example.test/checkout" method="post" enctype="multipart/form-data"', '<input type="submit" value="Give">'),
    'submit handler' => $form('action="https://payments.example.test/checkout" method="post" onsubmit="track()"', '<input type="submit" value="Give">'),
    'two submits' => $form('action="https://payments.example.test/checkout" method="post"', '<input type="submit" value="Give"><input type="submit" value="Give more">'),
    'control override' => $form('action="https://payments.example.test/checkout" method="post"', '<input type="submit" formaction="/elsewhere" value="Give">'),
    'same-site handler' => '<html><head><link rel="canonical" href="https://www.site.example.test/post"></head><body>' . $form('action="https://site.example.test/handler" method="post"', '<input type="submit" value="Give">') . '</body></html>',
);
foreach ( $declined as $label => $html ) {
    $result = $transform($html);
    $assert(! str_contains((string) ($result['serialized_blocks'] ?? ''), 'wp:custom/authored-native-form'), $label . ' is not lowered as a self-contained submission');
}
$assert(array() !== $formFallbacks($transform($declined['user-editable field'])), 'a form with user-editable fields stays on the form provider path');

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Native external submission form: passed\n";
