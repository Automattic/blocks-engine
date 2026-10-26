<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NativeControlState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

final class FormControlClassifier
{
    /**
     * Native elements that participate in form control semantics.
     *
     * @var array<int, string>
     */
    public const CONTROL_TAGS = array( 'button', 'input', 'select', 'textarea' );

    /**
     * Controls that collect user-entered values rather than only submitting or
     * carrying hidden/runtime state.
     *
     * @var array<int, string>
     */
    public const DATA_ENTRY_TAGS = array( 'input', 'select', 'textarea' );

    /**
     * The external label host emitted independently from its control: a label
     * that names a control by `for`, or a group by its id in `aria-labelledby`.
     */
    public static function isExternalAssociatedLabel(DOMElement $element): bool
    {
        return 'label' === strtolower($element->tagName)
            && ('' !== $element->getAttribute('for') || self::namesLabelledElement($element))
            && array() === self::controlElements($element);
    }

    /** Whether another element in the document lists this element's id in `aria-labelledby`. */
    private static function namesLabelledElement(DOMElement $element): bool
    {
        $id = trim($element->getAttribute('id'));
        if ( '' === $id || null === $element->ownerDocument ) {
            return false;
        }
        $xpath = new \DOMXPath($element->ownerDocument);
        foreach ( $xpath->query('//*[@aria-labelledby]') ?: array() as $labelled ) {
            if ( $labelled instanceof DOMElement && $labelled !== $element
                && in_array($id, preg_split('/\s+/', trim($labelled->getAttribute('aria-labelledby'))) ?: array(), true) ) {
                return true;
            }
        }

        return false;
    }

    public static function isControlElement(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), self::CONTROL_TAGS, true);
    }

    public static function controlType(DOMElement $control): string
    {
        $tagName = strtolower($control->tagName);
        if ( 'input' === $tagName ) {
            $type = strtolower(trim($control->hasAttribute('type') ? $control->getAttribute('type') : ''));
            return '' !== $type ? $type : 'text';
        }
        if ( 'button' === $tagName ) {
            $type = strtolower(trim($control->hasAttribute('type') ? $control->getAttribute('type') : ''));
            return '' !== $type ? $type : 'submit';
        }
        if (in_array($tagName, array('div', 'span'), true) && 'button' === strtolower(trim($control->getAttribute('role')))) return 'button';
        if ( 'select' === $tagName && $control->hasAttribute('multiple') ) {
            return 'select-multiple';
        }

        return $tagName;
    }

    public static function isDataEntryControl(DOMElement $control): bool
    {
        $tagName = strtolower($control->tagName);
        if ( in_array($tagName, array( 'select', 'textarea' ), true) ) {
            return true;
        }

        if ( 'input' !== $tagName ) {
            return false;
        }

        return ! in_array(
            self::controlType($control),
            array( 'submit', 'reset', 'button', 'image', 'hidden', 'file' ),
            true
        );
    }

    /** @return array<int, DOMElement> */
    public static function controlElements(DOMElement $form): array
    {
        $controls = array();
        foreach ( $form->getElementsByTagName('*') as $control ) {
            if ( $control instanceof DOMElement && self::isControlElement($control) && ! self::isNonAuthoredControl($control) ) {
                $controls[] = $control;
            }
        }

        return $controls;
    }

    /**
     * The shared control enumeration, with each control replaced by the element
     * that paints it. Layout and presentation describe these boxes; control
     * semantics stay on `controlElements()`, index for index.
     *
     * @return array<int, DOMElement>
     */
    public static function presentedControlElements(DOMElement $form): array
    {
        return array_map(self::presentingElement(...), self::controlElements($form));
    }

    /**
     * The element that paints a native submit control.
     *
     * A site builder may move its native submit off-screen with inline styles
     * and paint an adjacent sibling carrying the same caption in its place (a
     * styled link with no destination). That sibling is the submit a visitor
     * sees and the box a provider button reproduces. Any other control, or an
     * ambiguous match, paints itself.
     */
    public static function presentingElement(DOMElement $control): DOMElement
    {
        $parent = $control->parentNode;
        if ( ! $parent instanceof DOMElement || ! in_array(self::controlType($control), array( 'submit', 'image' ), true) ) {
            return $control;
        }
        $declarations = array_column(CssRuleAnalyzer::declarations($control->getAttribute('style'), array( 'position', 'left', 'top' )), 'value', 'name');
        if ( ! CssValueInspector::isMovedOffscreenBox($declarations) ) {
            return $control;
        }
        $caption = self::normalizedCaption('input' === strtolower($control->tagName) ? ( '' !== trim($control->getAttribute('value')) ? $control->getAttribute('value') : $control->getAttribute('aria-label') ) : (string) $control->textContent);
        if ( '' === $caption ) {
            return $control;
        }
        $presenter = null;
        foreach ( $parent->childNodes as $sibling ) {
            if ( ! $sibling instanceof DOMElement || $sibling === $control || $sibling->hasAttribute('href') || self::isControlElement($sibling) || self::normalizedCaption((string) $sibling->textContent) !== $caption ) {
                continue;
            }
            foreach ( self::CONTROL_TAGS as $tag ) {
                if ( 0 < $sibling->getElementsByTagName($tag)->length ) {
                    continue 2;
                }
            }
            if ( null !== $presenter ) {
                return $control;
            }
            $presenter = $sibling;
        }

        return $presenter ?? $control;
    }

    private static function normalizedCaption(string $text): string
    {
        return strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    /**
     * Hidden honeypots, template scaffolding, and accessibility-hidden traps
     * are not authored fields. aria-hidden outside a native form is closed
     * overlay render state, not non-authorship.
     */
    public static function isNonAuthoredControl(DOMElement $control): bool
    {
        if ( false === ( NativeControlState::payload($control)['rendered'] ?? null ) ) {
            return true;
        }
        if ( 'hidden' === self::controlType($control) ) {
            return true;
        }
        if ( 1 === preg_match('/(?:^|;)\s*display\s*:\s*none\s*(?:;|$)/i', $control->getAttribute('style')) ) {
            return true;
        }
        if ( 'new-password' === strtolower(trim($control->getAttribute('autocomplete'))) ) {
            return true;
        }
        if ( self::isHoneypotControl($control) ) {
            return true;
        }
        if ( SourceDom::hasNonAuthoredAncestor($control) ) {
            return true;
        }
        $insideNativeForm = false;
        for ( $parent = $control->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( null !== self::sourceSelectAfterCapturedPanel($parent) ) {
                return true;
            }
            if ( 'form' === strtolower($parent->tagName) ) {
                $insideNativeForm = true;
            }
            if ( ! $insideNativeForm && 'true' === strtolower(trim($parent->getAttribute('aria-hidden'))) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * An untabbable free-text field is a trap when aria-hidden on itself, or
     * invisible and non-interactive on itself or its immediate field container.
     * Choice and select value carriers share those attributes beside their
     * visible widget, so only free-text entry qualifies.
     */
    private static function isUnreachableTextTrap(DOMElement $control): bool
    {
        if ( ! is_numeric(trim($control->getAttribute('tabindex')))
            || 0 <= (int) trim($control->getAttribute('tabindex')) ) {
            return false;
        }
        $tag = strtolower($control->tagName);

        if ( 'textarea' !== $tag && ( 'input' !== $tag
            || ! in_array(self::controlType($control), array( 'text', 'email', 'url', 'tel', 'search', 'number' ), true) ) ) {
            return false;
        }
        if ( 'true' === strtolower(trim($control->getAttribute('aria-hidden'))) ) {
            return true;
        }
        foreach ( array( $control, $control->parentNode ) as $element ) {
            if ( ! $element instanceof DOMElement
                || in_array(strtolower($element->tagName), array( 'form', 'fieldset', 'body', 'html' ), true) ) {
                continue;
            }
            $declarations = array_column(
                CssRuleAnalyzer::declarations($element->getAttribute('style'), array( 'opacity', 'pointer-events' )),
                'value',
                'name'
            );
            if ( '0' === CssValueInspector::comparable((string) ( $declarations['opacity'] ?? '' ))
                && 'none' === CssValueInspector::comparable((string) ( $declarations['pointer-events'] ?? '' )) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A decoy field bots fill and no reader sees: `aria-hidden="true"`, out of
     * the tab order, and hidden or moved off-screen by inline style — all
     * three together on the control itself, or all three together on the label
     * that wraps it. A field carrying only some of the signals stays authored
     * except for an untabbable free-text trap that is aria-hidden or carries
     * invisible, non-interactive inline styling (see `isUnreachableTextTrap()`).
     * Form providers bring their own spam protection, so a honeypot is left out of form extraction entirely:
     * no field, and no unrepresented label text.
     */
    public static function isHoneypotControl(DOMElement $control): bool
    {
        return self::isUnreachableTextTrap($control) || self::hasHoneypotSignals($control) || null !== self::honeypotSignalLabel($control);
    }

    /**
     * A trap owns its associated/wrapping label and its immediate field
     * container's copy. Never claim a form/group or a container shared with
     * a real control, and count raw controls here (authored enumeration has
     * already dropped the traps).
     *
     * @return array<int, DOMElement>
     */
    public static function honeypotCopyElements(DOMElement $form): array
    {
        $elements = array();
        foreach ( $form->getElementsByTagName('*') as $control ) {
            if ( ! $control instanceof DOMElement || ! self::isControlElement($control) || ! self::isHoneypotControl($control) ) {
                continue;
            }
            $label = FormControlLabel::element($control);
            if ( null !== $label ) {
                $elements[] = $label;
            }
            $container = $control->parentNode;
            if ( ! $container instanceof DOMElement
                || in_array(strtolower($container->tagName), array( 'form', 'fieldset', 'body', 'html' ), true) ) {
                continue;
            }
            foreach ( $container->getElementsByTagName('*') as $sibling ) {
                if ( $sibling instanceof DOMElement && self::isControlElement($sibling) && ! self::isHoneypotControl($sibling) ) {
                    continue 2;
                }
            }
            $elements[] = $container;
        }

        return $elements;
    }

    /** The control's wrapping label when that label, not the control, carries the honeypot signals. */
    private static function honeypotSignalLabel(DOMElement $control): ?DOMElement
    {
        for ( $parent = $control->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( 'label' === strtolower($parent->tagName) ) {
                return self::hasHoneypotSignals($parent) ? $parent : null;
            }
            if ( in_array(strtolower($parent->tagName), array( 'form', 'fieldset' ), true) ) {
                return null;
            }
        }

        return null;
    }

    /**
     * The three honeypot signals on one element: hidden from assistive
     * technology, removed from the tab order, and hidden or moved off-screen
     * by its inline style (`display:none`, `visibility:hidden`, or an
     * absolute box shifted 999px or further out by `left`/`top`).
     */
    private static function hasHoneypotSignals(DOMElement $element): bool
    {
        if ( 'true' !== strtolower(trim($element->getAttribute('aria-hidden')))
            || '-1' !== trim($element->getAttribute('tabindex')) ) {
            return false;
        }
        $declarations = array_column(
            CssRuleAnalyzer::declarations($element->getAttribute('style'), array( 'display', 'visibility', 'position', 'left', 'top' )),
            'value',
            'name'
        );

        return 'none' === CssValueInspector::comparable((string) ( $declarations['display'] ?? '' ))
            || 'hidden' === CssValueInspector::comparable((string) ( $declarations['visibility'] ?? '' ))
            || CssValueInspector::isMovedOffscreenBox($declarations);
    }

    /**
     * A capture-only popup is bounded by its linked visible trigger and the
     * immediately following hidden native value carrier. Only its descendants
     * leave provider control topology; the panel remains in the page replay.
     */
    public static function sourceSelectAfterCapturedPanel(DOMElement $panel): ?DOMElement
    {
        $key = trim($panel->getAttribute(CaptureEvidence::LISTBOX_PANEL));
        if ( '' === $key || ! $panel->hasAttribute('hidden') ) {
            return null;
        }
        $trigger = $panel->previousElementSibling;
        $select = $panel->nextElementSibling;
        if ( ! $trigger instanceof DOMElement || 'button' !== strtolower($trigger->tagName)
            || $key !== trim($trigger->getAttribute(CaptureEvidence::LISTBOX_TRIGGER))
            || ( 'combobox' !== strtolower(trim($trigger->getAttribute('role')))
                && 'listbox' !== strtolower(trim($trigger->getAttribute('aria-haspopup'))) )
            || ! $select instanceof DOMElement || 'select' !== strtolower($select->tagName)
            || ( ! $select->hasAttribute('hidden')
                && ! ( 'true' === strtolower(trim($select->getAttribute('aria-hidden')))
                    && '-1' === trim($select->getAttribute('tabindex')) ) )
            || 0 === $select->getElementsByTagName('option')->length ) {
            return null;
        }

        return $select;
    }

    public static function hasDataEntryControls(DOMElement $form): bool
    {
        foreach ( self::controlElements($form) as $control ) {
            if ( self::isDataEntryControl($control) ) {
                return true;
            }
        }

        return false;
    }

    public static function hasCapturedChoiceGroups(DOMElement $form): bool
    {
        foreach ( $form->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement && 'true' === strtolower($element->getAttribute('data-blocks-engine-choice-group')) ) {
                return true;
            }
        }

        return false;
    }

    public static function hasFormAncestor(DOMElement $element): bool
    {
        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( 'form' === strtolower($parent->tagName) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a degraded page can summarize the control as prose. Other
     * readable controls stay native inputs in the readable tree only.
     */
    public static function hasProseSummary(DOMElement $control): bool
    {
        $tagName = strtolower($control->tagName);
        if ( in_array($tagName, array( 'select', 'textarea', 'button' ), true) ) {
            return true;
        }

        return 'input' === $tagName && in_array(self::controlType($control), array( 'checkbox', 'email', 'number', 'password', 'radio', 'range', 'search', 'submit', 'tel', 'text', 'url' ), true);
    }

    /** Whether every control in the form has a prose summary. */
    public static function controlsHaveProseSummaries(DOMElement $form): bool
    {
        foreach ( self::controlElements($form) as $control ) {
            if ( ! self::hasProseSummary($control) ) {
                return false;
            }
        }

        return true;
    }

    public static function isReadableControl(DOMElement $control): bool
    {
        // Formless controls lower straight onto the page, so they need a prose
        // summary. Form controls are gated by the form dispatcher instead.
        if ( ! self::hasFormAncestor($control) && ! self::hasProseSummary($control) ) {
            return false;
        }

        // Inside a form every control belongs to the readable tree, so a
        // provider can account for it by index; page lowering stays gated by
        // hasProseSummary().
        if ( self::hasFormAncestor($control) ) {
            return true;
        }

        $tagName = strtolower($control->tagName);
        if ( in_array($tagName, array( 'select', 'textarea' ), true) ) {
            return true;
        }

        return 'button' === $tagName || self::isDataEntryControl($control)
            || ('input' === $tagName && 'submit' === self::controlType($control));
    }

    public static function isSubmitLikeControl(DOMElement $control): bool
    {
        $tagName = strtolower($control->tagName);
        if ( 'button' !== $tagName && 'input' !== $tagName ) {
            return false;
        }

        $type = self::controlType($control);
        if ( in_array($type, array( 'submit', 'image' ), true) ) {
            return true;
        }
        if ( 'reset' === $type ) {
            return false;
        }

        // Data-entry inputs never become submit controls through labels alone.
        if ( 'input' === $tagName && 'button' !== $type ) {
            return false;
        }

        return self::hasSubmitSemantics($control) || self::isSoleFormActionControl($control);
    }

    public static function hasSubmitSemantics(DOMElement $control): bool
    {
        // A control with an ARIA choice role selects state; a caption such as
        // "Subscribe & Save" on a purchase option is not submit intent.
        if ( self::hasAriaChoiceRole($control) ) {
            return false;
        }

        $values = array( $control->textContent ?? '' );
        foreach ( array( 'value', 'class', 'id', 'name', 'aria-label', 'data-hook', 'data-field-type', 'data-testid' ) as $attribute ) {
            $values[] = $control->hasAttribute($attribute) ? $control->getAttribute($attribute) : '';
        }
        $haystack = strtolower(implode(' ', $values));

        foreach ( array( 'submit', 'subscribe', 'sign up', 'sign-up', 'signup', 'send' ) as $needle ) {
            if ( str_contains($haystack, $needle) ) {
                return true;
            }
        }

        return false;
    }

    public static function isPseudoFormSubmitControl(DOMElement $control): bool
    {
        $type = self::controlType($control);
        if ( 'image' === $type ) {
            return true;
        }
        if ( 'submit' === $type ) {
            // HTML defaults a typeless button to submit. Outside a real form that
            // default is not form-submit evidence — add-to-cart and quantity
            // steppers must not become contact-form islands.
            if ( 'button' === strtolower($control->tagName) && ! $control->hasAttribute('type') && ! self::hasFormAncestor($control) ) {
                return self::hasSubmitSemantics($control);
            }

            return true;
        }

        return self::hasSubmitSemantics($control);
    }

    private static function hasAriaChoiceRole(DOMElement $control): bool
    {
        return in_array(strtolower(trim($control->getAttribute('role'))), array( 'radio', 'checkbox', 'switch', 'option', 'tab', 'menuitemradio', 'menuitemcheckbox' ), true);
    }

    public static function isPseudoFormDataEntryControl(DOMElement $control): bool
    {
        return self::isDataEntryControl($control) && 'search' !== self::controlType($control);
    }

    /**
     * A `<button>` a paragraph's RichText can carry inline instead of forcing
     * the whole paragraph to a `core/html` fallback: no real or pseudo form to
     * submit, no wired runtime behavior, and no nested interactive or media
     * content that would make an inline run unsafe or invalid.
     */
    public static function isInlineSafeButton(DOMElement $button): bool
    {
        if ( 'button' !== strtolower($button->tagName) ) {
            return false;
        }

        if ( self::hasFormAncestor($button) || self::isPseudoFormSubmitControl($button) ) {
            return false;
        }

        foreach ( self::UNSAFE_INLINE_BUTTON_ATTRIBUTES as $attribute ) {
            if ( $button->hasAttribute($attribute) ) {
                return false;
            }
        }

        foreach ( $button->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && in_array(strtolower($descendant->tagName), self::UNSAFE_INLINE_BUTTON_DESCENDANTS, true) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Form-action and runtime-behavior attributes that make a button unsafe to
     * flatten into RichText, shared with the wrapped-button preservation checks
     * in {@see \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonsPattern}
     * and {@see \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ElementConversionPrelude}.
     *
     * @var array<int, string>
     */
    private const UNSAFE_INLINE_BUTTON_ATTRIBUTES = array(
        'disabled',
        'form',
        'formaction',
        'formenctype',
        'formmethod',
        'formnovalidate',
        'formtarget',
        'popovertarget',
        'popovertargetaction',
        'command',
        'commandfor',
        'aria-controls',
        'aria-expanded',
        'data-action',
        'jsaction',
        'onclick',
        'onchange',
        'onsubmit',
    );

    /**
     * Interactive or media descendants that cannot live inside inline RichText,
     * or that would nest interactive content invalidly inside a button.
     *
     * @var array<int, string>
     */
    private const UNSAFE_INLINE_BUTTON_DESCENDANTS = array(
        'a',
        'button',
        // Inline SVG is materialized as a RichText image by the caller;
        // unsupported SVG still fails that materialization's fallback gate.
        'canvas',
        'img',
        'picture',
        'video',
        'audio',
        'iframe',
        'object',
        'embed',
        'input',
        'select',
        'textarea',
        'form',
    );

    private static function isSoleFormActionControl(DOMElement $control): bool
    {
        $form = null;
        for ( $parent = $control->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( 'form' === strtolower($parent->tagName) ) {
                $form = $parent;
                break;
            }
        }
        if ( ! $form instanceof DOMElement ) {
            return false;
        }

        $actions = 0;
        foreach ( self::controlElements($form) as $candidate ) {
            $tagName = strtolower($candidate->tagName);
            $type = self::controlType($candidate);
            if ( 'reset' === $type ) {
                continue;
            }
            if ( 'button' === $tagName || ( 'input' === $tagName && in_array($type, array( 'submit', 'image', 'button' ), true) ) ) {
                ++$actions;
                if ( 1 < $actions ) {
                    return false;
                }
            }
        }

        return 1 === $actions;
    }
}
