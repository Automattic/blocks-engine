<?php
declare(strict_types=1);

/**
 * FormControlClassifier::isInlineSafeButton() decides whether a `<button>`
 * nested in a paragraph can ride through as inline RichText content instead of
 * forcing the whole paragraph to a core/html fallback. Safe means: no real or
 * pseudo form to submit, no wired runtime behavior, and no nested interactive
 * or media content.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;

$assertions = 0;
$failures   = array();
$assert     = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']';
    }
};

$buttonFrom = static function (string $html): DOMElement {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $button = $document->getElementsByTagName('button')->item(0);
    if ( ! $button instanceof DOMElement ) {
        throw new RuntimeException('No <button> parsed from: ' . $html);
    }
    return $button;
};

// The exact regression shape: a plain typeless button labelling an inline
// control, no form ancestor, no submit-like text.
$assert(
    FormControlClassifier::isInlineSafeButton($buttonFrom('<p>Already a seller? <button class="text-primary hover:underline">Sign in</button></p>')),
    'plain-text-button-is-inline-safe'
);

// A non-<button> element is never inline-safe through this predicate.
$anchorDocument = new DOMDocument();
$anchorDocument->loadHTML('<?xml encoding="utf-8" ?><body><a href="/x">Sign in</a></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$anchor = $anchorDocument->getElementsByTagName('a')->item(0);
$assert(
    $anchor instanceof DOMElement && ! FormControlClassifier::isInlineSafeButton($anchor),
    'non-button-element-is-never-inline-safe'
);

// A real <form> ancestor always disqualifies the button, regardless of type.
$assert(
    ! FormControlClassifier::isInlineSafeButton($buttonFrom('<form><p>Text <button type="button">Go</button></p></form>')),
    'form-ancestor-disqualifies'
);

// Explicit type="submit" always disqualifies, form ancestor or not.
$assert(
    ! FormControlClassifier::isInlineSafeButton($buttonFrom('<p>Text <button type="submit">Go</button></p>')),
    'explicit-submit-type-disqualifies'
);

// A typeless button outside a form with submit-like text is pseudo-form
// evidence (newsletter/contact submits), not a plain inline control.
$assert(
    ! FormControlClassifier::isInlineSafeButton($buttonFrom('<p>Get updates. <button class="cta">Subscribe</button></p>')),
    'pseudo-form-submit-text-disqualifies'
);

// A typeless button outside a form with unrelated text is not submit-like and
// stays inline-safe (mirrors add-to-cart/quantity-stepper reasoning in
// FormControlClassifier::isPseudoFormSubmitControl()).
$assert(
    FormControlClassifier::isInlineSafeButton($buttonFrom('<p>Need help? <button class="link">Contact us</button></p>')),
    'typeless-non-submit-text-is-inline-safe'
);

// Wired runtime behavior disqualifies even a typeless, form-free button.
foreach ( array( 'disabled', 'aria-controls="menu"', 'aria-expanded="false"', 'data-action="toggle"', 'jsaction="click:x"', 'onclick="x()"', 'onchange="x()"', 'onsubmit="x()"' ) as $attribute ) {
    $assert(
        ! FormControlClassifier::isInlineSafeButton($buttonFrom('<p>Text <button ' . $attribute . '>Go</button></p>')),
        'runtime-attribute-disqualifies-' . $attribute
    );
}

// Form-action attributes disqualify even without a <form> ancestor (a button
// can own its own form association via the `form` attribute).
foreach ( array( 'form="f1"', 'formaction="/x"', 'formmethod="post"', 'formenctype="text/plain"', 'formnovalidate', 'formtarget="_blank"', 'popovertarget="p1"', 'popovertargetaction="show"', 'command="show-popover"', 'commandfor="p1"' ) as $attribute ) {
    $assert(
        ! FormControlClassifier::isInlineSafeButton($buttonFrom('<p>Text <button ' . $attribute . '>Go</button></p>')),
        'form-action-attribute-disqualifies-' . $attribute
    );
}

// Nested interactive or media content disqualifies the button.
foreach ( array( '<a href="/x">link</a>', '<button>nested</button>', '<svg></svg>', '<img src="x.png">', '<input type="text">', '<select></select>', '<textarea></textarea>' ) as $descendant ) {
    $assert(
        ! FormControlClassifier::isInlineSafeButton($buttonFrom('<p>Text <button>' . $descendant . '</button></p>')),
        'unsafe-descendant-disqualifies-' . strtok($descendant, ' >')
    );
}

// Simple phrasing descendants (matching what RichText already tolerates
// elsewhere) do not disqualify the button.
$assert(
    FormControlClassifier::isInlineSafeButton($buttonFrom('<p>Text <button><strong>Sign in</strong></button></p>')),
    'phrasing-descendant-stays-inline-safe'
);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Form control classifier inline-safe-button tests: ' . $assertions . " passed\n";
