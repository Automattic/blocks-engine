<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * The one answer to "which source element labels this control". Form metadata
 * and the form presentation graph both read it, so the label whose text a
 * provider field carries is the same label whose styles it carries.
 */
final class FormControlLabel
{
    /** How far a control's own field wrapper may sit above it. */
    private const FIELD_WRAPPER_DEPTH = 4;

    public static function element(DOMElement $control): ?DOMElement
    {
        $label = SourceDom::associatedLabel($control);
        if ( $label instanceof DOMElement ) {
            return $label;
        }
        for ( $parent = $control->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( 'label' === strtolower($parent->tagName) ) {
                return $parent;
            }
        }
        return self::capturedChoiceFieldLabel($control) ?? self::fieldWrapperLabel($control);
    }

    /** The captured choice field's four direct siblings supply one bounded positional label. */
    private static function capturedChoiceFieldLabel(DOMElement $control): ?DOMElement
    {
        $field = $control->parentNode;
        $label = $control->previousElementSibling;
        $panel = $control->nextElementSibling;
        if ( 'button' !== strtolower($control->tagName)
            || ! $field instanceof DOMElement || 'div' !== strtolower($field->tagName)
            || 4 !== $field->childElementCount || ! $label instanceof DOMElement
            || 'label' !== strtolower($label->tagName) || $field->firstElementChild !== $label
            || '' !== SourceDom::attr($label, 'for')
            || ! $panel instanceof DOMElement
            || ! $panel->nextElementSibling instanceof DOMElement
            || $field->lastElementChild !== $panel->nextElementSibling
            || null === FormControlClassifier::sourceSelectAfterCapturedPanel($panel) ) {
            return null;
        }

        // An explicit aria reference to a different element wins over position.
        $labelledBy = trim(SourceDom::attr($control, 'aria-labelledby'));
        if ( '' !== $labelledBy && $labelledBy !== SourceDom::attr($label, 'id') ) {
            return null;
        }

        return $label;
    }

    /**
     * Markup rendered by a client framework routinely omits both `id` and `for`
     * and states the association by position alone: one label and one control
     * inside the same field wrapper. That is the only association the source
     * makes, so read it instead of reporting the control as unlabelled.
     */
    private static function fieldWrapperLabel(DOMElement $control): ?DOMElement
    {
        $depth = 0;
        for ( $wrapper = $control->parentNode; $wrapper instanceof DOMElement && $depth < self::FIELD_WRAPPER_DEPTH; $wrapper = $wrapper->parentNode, ++$depth ) {
            if ( in_array(strtolower($wrapper->tagName), array( 'form', 'fieldset', 'body', 'html' ), true) ) {
                return null;
            }

            $controls = FormControlClassifier::controlElements($wrapper);
            // A wrapper shared with another control cannot say which one a label belongs to.
            if ( 1 !== count($controls) || ! $controls[0]->isSameNode($control) ) {
                return null;
            }

            $labels = array();
            foreach ( $wrapper->getElementsByTagName('label') as $label ) {
                if ( $label instanceof DOMElement && '' === SourceDom::attr($label, 'for') ) {
                    $labels[] = $label;
                }
            }
            if ( 1 === count($labels) ) {
                return $labels[0];
            }
        }

        return null;
    }
}
