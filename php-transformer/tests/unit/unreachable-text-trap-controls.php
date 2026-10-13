<?php
declare(strict_types=1);

/**
 * A free-text field hidden from assistive technology on itself and removed
 * from the tab order is a bot trap, not an authored field. Value carriers that
 * share those attributes beside or inside a visible widget stay authored.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;

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

$names = static function (string $html): array {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $form = $document->getElementsByTagName('form')->item(0);
    if ( ! $form instanceof DOMElement ) {
        throw new RuntimeException('No form parsed');
    }

    return array_map(
        static fn (DOMElement $control): string => $control->getAttribute('name') ?: strtolower($control->tagName),
        FormControlClassifier::controlElements($form)
    );
};

$trap = static fn (string $name): string => '<input type="text" name="' . $name . '" tabindex="-1" autocomplete="one-time-code" aria-hidden="true" value="" style="position:absolute;transform:scale(0)">';

$form = $names('<form>'
    . '<label>Full name<input type="text" name="full_name"></label>'
    . '<div role="radiogroup"><button type="button" role="radio" aria-checked="false"><input type="radio" name="attending" value="yes" tabindex="-1" aria-hidden="true"><span>Yes</span></button></div>'
    . '<button type="button" role="combobox" aria-haspopup="listbox">Choose</button><select name="meal" tabindex="-1" aria-hidden="true"><option>Fish</option></select>'
    . '<label>Notes<textarea name="notes"></textarea></label>'
    . $trap('website') . $trap('company') . '<textarea name="comments" tabindex="-1" aria-hidden="true"></textarea>'
    . '</form>');

$assert(! in_array('website', $form, true) && ! in_array('company', $form, true), 'self aria-hidden, untabbable text inputs are not authored fields', json_encode($form));
$assert(! in_array('comments', $form, true), 'a self aria-hidden, untabbable textarea is not an authored field', json_encode($form));
$assert(in_array('full_name', $form, true) && in_array('notes', $form, true), 'visible text fields stay authored', json_encode($form));
$assert(in_array('attending', $form, true), 'a radio carried inside an ARIA radio button stays authored', json_encode($form));
$assert(in_array('meal', $form, true), 'a select value carrier beside its listbox trigger stays authored', json_encode($form));

$focusOnly = $names('<form><input type="text" name="search" tabindex="-1"><input type="text" name="label_only" aria-hidden="true"></form>');
$assert(array( 'search', 'label_only' ) === $focusOnly, 'only the combination of both signals marks a trap', json_encode($focusOnly));

fwrite(STDOUT, sprintf("unreachable text trap controls: %d passed, %d failed\n", $passes, $failures));
exit($failures > 0 ? 1 : 0);
