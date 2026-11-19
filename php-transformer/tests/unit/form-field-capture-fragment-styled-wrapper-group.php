<?php
declare(strict_types=1);

/**
 * Form field wrappers carrying an inline style and the capture's responsive
 * fragment hook stay native Groups instead of a generated layout-shell: the
 * Group projects the inline style like any flow container, and the fragment
 * hook rides on the block's runtime attributes, which the render filter
 * restores on its root. An inline custom property, which the Group does not
 * carry to descendants, keeps the shell.
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

$transform = static fn (string $form): string => ( new HtmlTransformer() )->transform('<!DOCTYPE html><html><head></head><body>' . $form . '</body></html>')->toArray()['serialized_blocks'] ?? '';

$form = '<form><div class="form-fields" style="vertical-align: middle"><div id="email-field" class="form-item" style="vertical-align: bottom" data-blocks-engine-capture-responsive-fragment="email-field"><label for="email">Email</label><input id="email" name="email" type="email"></div></div><button type="submit">Send</button></form>';
$markup = $transform($form);
$assert(! str_contains($markup, '/layout-shell'), 'styled wrappers holding a responsive fragment hook do not force a generated layout-shell');
$assert(1 === preg_match('/<!-- wp:group \{"className":"form-fields be-inline-geometry-[0-9a-f]+/', $markup), 'the styled outer wrapper is a native Group projecting its inline style');
$assert(1 === preg_match('/<!-- wp:group \{"anchor":"email-field",[^}]*"metadata":\{"blocksEngineRuntimeAttributes":\{"data-blocks-engine-capture-responsive-fragment":"email-field"\}/', $markup), 'the field wrapper is a native Group carrying the fragment hook as a runtime attribute');

$custom = $transform(str_replace('style="vertical-align: middle"', 'style="--gap: 4px"', $form));
$assert(str_contains($custom, '/layout-shell'), 'an inline custom property keeps a layout-shell wrapper');

fwrite(STDOUT, sprintf('form-field-capture-fragment-styled-wrapper-group: %d failed%s', $failures, PHP_EOL));
exit($failures > 0 ? 1 : 0);
