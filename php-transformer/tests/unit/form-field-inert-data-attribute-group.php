<?php
declare(strict_types=1);

/**
 * A form field wrapper whose only non-class attributes are an id and a
 * valueless data marker nothing addresses stays a native Group (id as its
 * anchor) instead of a generated layout-shell. A marker a stylesheet selects
 * keeps the shell, the only block that can carry it. A value does not make an
 * unaddressed data attribute any less inert.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};

$field = '<form><div class="form-item field textarea" id="textarea-field" data-dynamic-strings=""><label for="message">Message</label><textarea id="message" name="message"></textarea></div><button type="submit">Send</button></form>';

$markup = ( new HtmlTransformer() )->transform('<!DOCTYPE html><html><head></head><body>' . $field . '</body></html>')->toArray()['serialized_blocks'] ?? '';
$assert(! str_contains($markup, '/layout-shell'), 'an inert data attribute does not force a generated layout-shell');
$assert(1 === preg_match('/<!-- wp:group \{"anchor":"textarea-field","className":"form-item field textarea /', $markup), 'the field wrapper is a native Group keeping its id and classes');

$styled = ( new HtmlTransformer() )->transform('<!DOCTYPE html><html><head><style>[data-dynamic-strings]{color:red}</style></head><body>' . $field . '</body></html>')->toArray()['serialized_blocks'] ?? '';
$assert(str_contains($styled, '"data-dynamic-strings":""'), 'a data attribute a stylesheet selects stays on a layout-shell wrapper');

$valued = ( new HtmlTransformer() )->transform('<!DOCTYPE html><html><head></head><body>' . str_replace('data-dynamic-strings=""', 'data-dynamic-strings="keep"', $field) . '</body></html>')->toArray()['serialized_blocks'] ?? '';
$assert(! str_contains($valued, '/layout-shell'), 'an unaddressed data attribute carrying a value does not force a generated layout-shell');

$valuedStyled = ( new HtmlTransformer() )->transform('<!DOCTYPE html><html><head><style>[data-dynamic-strings="keep"]{color:red}</style></head><body>' . str_replace('data-dynamic-strings=""', 'data-dynamic-strings="keep"', $field) . '</body></html>')->toArray()['serialized_blocks'] ?? '';
$assert(str_contains($valuedStyled, '"data-dynamic-strings":"keep"'), 'a valued data attribute a stylesheet selects stays on a layout-shell wrapper');

fwrite(STDOUT, sprintf('form-field-inert-data-attribute-group: %d failed%s', $failures, PHP_EOL));
exit($failures > 0 ? 1 : 0);
