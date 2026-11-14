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
        $labels = SourceDom::associatedLabels($control);
        if ( in_array(FormControlClassifier::controlType($control), array( 'checkbox', 'radio' ), true) ) {
            foreach ( $labels as $label ) {
                if ( SourceDom::elementContains($label, $control) ) {
                    return $label;
                }
            }
            // Without a wrapper, the trailing associated caption is the choice
            // text; an earlier label commonly supplies the field title.
            if ( 1 < count($labels) ) {
                return $labels[count($labels) - 1];
            }
        }
        $label = $labels[0] ?? null;
        if ( $label instanceof DOMElement ) {
            return $label;
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

    /** An unassociated caption immediately preceding one same-name radio or checkbox group. */
    public static function choiceGroupLabel(DOMElement $group): ?DOMElement
    {
        $label = $group->previousElementSibling;
        if ( ! $label instanceof DOMElement || 'label' !== strtolower($label->tagName)
            || '' !== SourceDom::attr($label, 'for') || array() !== FormControlClassifier::controlElements($label) ) {
            return null;
        }
        $controls = FormControlClassifier::controlElements($group);
        if ( count($controls) < 2 ) return null;
        $name = SourceDom::attr($controls[0], 'name');
        $type = FormControlClassifier::controlType($controls[0]);
        if ( '' === $name || ! in_array($type, array( 'radio', 'checkbox' ), true) ) return null;
        foreach ( $controls as $control ) {
            if ( 'input' !== strtolower($control->tagName) || $type !== FormControlClassifier::controlType($control)
                || $name !== SourceDom::attr($control, 'name') ) return null;
        }
        return $label;
    }

    /**
     * Markup rendered by a client framework routinely states the association
     * by position alone: one label and one control inside the same field
     * wrapper, with no `for`, or with a `for` that names no element (matching
     * is case-sensitive), or targets a non-authored control. That is the only
     * authored association the source makes, so read it instead of reporting
     * the control as unlabelled. A `for` resolving to an authored element wins.
     */
    private static function fieldWrapperLabel(DOMElement $control): ?DOMElement
    {
        $depth = 0;
        for ( $wrapper = $control->parentNode; $wrapper instanceof DOMElement && $depth < self::FIELD_WRAPPER_DEPTH; $wrapper = $wrapper->parentNode, ++$depth ) {
            if ( in_array(strtolower($wrapper->tagName), array( 'form', 'fieldset', 'body', 'html' ), true) ) {
                return null;
            }

            $controls = FormControlClassifier::controlElements($wrapper);
            // Preserve positional labels on captured button-backed fields. Only
            // a native fillable target can ignore sibling action triggers.
            if ( in_array(strtolower($control->tagName), array('input', 'select', 'textarea'), true)
                && ! in_array(FormControlClassifier::controlType($control), array('button', 'submit', 'reset', 'image', 'hidden'), true) ) {
                $controls = array_values(array_filter($controls, static fn (DOMElement $candidate): bool =>
                (in_array(strtolower($candidate->tagName), array('input', 'select', 'textarea'), true)
                    && ! in_array(FormControlClassifier::controlType($candidate), array('button', 'submit', 'reset', 'image', 'hidden'), true))
                    || 'combobox' === SourceDom::role($candidate)
                ));
            }
            // A wrapper shared with another control cannot say which one a label belongs to.
            if ( 1 !== count($controls) || ! $controls[0]->isSameNode($control) ) {
                return null;
            }

            $labels = array();
            foreach ( $wrapper->getElementsByTagName('label') as $label ) {
                if ( $label instanceof DOMElement ) {
                    $targetId = SourceDom::attr($label, 'for');
                    $target = '' !== $targetId ? $label->ownerDocument->getElementById($targetId) : null;
                    if ( null === $target || (FormControlClassifier::isControlElement($target) && FormControlClassifier::isNonAuthoredControl($target)) ) {
                        $labels[] = $label;
                    }
                }
            }
            if ( 1 === count($labels) ) {
                return $labels[0];
            }
        }

        return null;
    }
}
