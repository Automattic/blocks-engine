<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification;

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
            if ( $control instanceof DOMElement && self::isControlElement($control) ) {
                $controls[] = $control;
            }
        }

        return $controls;
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

    public static function hasFormAncestor(DOMElement $element): bool
    {
        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( 'form' === strtolower($parent->tagName) ) {
                return true;
            }
        }

        return false;
    }

    public static function isReadableControl(DOMElement $control): bool
    {
        $tagName = strtolower($control->tagName);
        if ( in_array($tagName, array( 'select', 'textarea' ), true) ) {
            return true;
        }

        return 'button' === $tagName || ( 'input' === $tagName && in_array(self::controlType($control), array( 'checkbox', 'email', 'number', 'radio', 'range', 'search', 'submit', 'tel', 'text', 'url' ), true) );
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
        return in_array(self::controlType($control), array( 'submit', 'image' ), true)
            || self::hasSubmitSemantics($control);
    }

    public static function isPseudoFormDataEntryControl(DOMElement $control): bool
    {
        return self::isDataEntryControl($control) && 'search' !== self::controlType($control);
    }

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
