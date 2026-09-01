<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;

/** Builds provider-neutral form and control metadata from source DOM. */
final class FormControlMetadataBuilder
{
    /** @param Closure(DOMElement): string $elementSelector */
    public function __construct(private readonly Closure $elementSelector)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function controls(DOMElement $form): array
    {
        $controls = array();
        $order = 0;
        foreach ( FormControlClassifier::controlElements($form) as $control ) {
            $metadata = $this->control($control);
            if ( array() !== $metadata ) {
                $metadata['order'] = $order;
                $controls[] = $metadata;
                ++$order;
            }
        }

        return $controls;
    }

    /** @return array<string, mixed> */
    public function form(DOMElement $form): array
    {
        $metadata = array_filter(array(
            'id'           => $this->attr($form, 'id'),
            'name'         => $this->attr($form, 'name'),
            'class'        => $this->attr($form, 'class'),
            'aria_label'   => $this->attr($form, 'aria-label'),
            'action'       => $this->attr($form, 'action'),
            'method'       => strtolower($this->attr($form, 'method')),
            'enctype'      => $this->attr($form, 'enctype'),
            'target'       => $this->attr($form, 'target'),
            'autocomplete' => $this->attr($form, 'autocomplete'),
        ), static fn (string $value): bool => '' !== $value);

        if ( $form->hasAttribute('novalidate') ) {
            $metadata['novalidate'] = true;
        }

        return $metadata;
    }

    /** @return array<string, mixed> */
    public function control(DOMElement $control): array
    {
        if ( ! FormControlClassifier::isControlElement($control) ) {
            return array();
        }

        $tagName = strtolower($control->tagName);
        $type = FormControlClassifier::controlType($control);
        if ( 'button' === $type && FormControlClassifier::isSubmitLikeControl($control) ) {
            $type = 'submit';
        }
        $metadata = array_filter(array(
            'tag'          => $tagName,
            'selector'     => ($this->elementSelector)($control),
            'id'           => $this->attr($control, 'id'),
            'name'         => $this->attr($control, 'name'),
            'type'         => $type,
            'label'        => $this->label($control),
            'placeholder'  => $this->attr($control, 'placeholder'),
            'autocomplete' => $this->attr($control, 'autocomplete'),
            'pattern'      => $this->attr($control, 'pattern'),
            'min'          => $this->attr($control, 'min'),
            'max'          => $this->attr($control, 'max'),
            'step'         => $this->attr($control, 'step'),
            'maxlength'    => $this->attr($control, 'maxlength'),
            'rows'         => $this->attr($control, 'rows'),
        ), static fn (string $value): bool => '' !== $value);

        if ( in_array($type, array( 'button', 'reset', 'submit' ), true) ) {
            $text = $this->buttonText($control);
            if ( '' !== $text ) {
                $metadata['text'] = $text;
            }
        }

        if ( $control->hasAttribute('required') || 'true' === strtolower(trim($this->attr($control, 'aria-required'))) ) {
            $metadata['required'] = true;
        }
        foreach ( array( 'disabled', 'readonly', 'checked', 'multiple' ) as $attribute ) {
            if ( $control->hasAttribute($attribute) ) {
                $metadata[$attribute] = true;
            }
        }

        $value = $this->attr($control, 'value');
        if ( '' !== $value && 'select' !== $tagName ) {
            $metadata['value'] = $value;
        }

        if ( 'select' === $tagName ) {
            $options = $this->options($control);
            if ( array() !== $options ) {
                $metadata['options'] = $options;
            }
        }

        return $metadata;
    }

    public function label(DOMElement $control): string
    {
        $ariaLabel = trim($this->attr($control, 'aria-label'));
        if ( '' !== $ariaLabel ) {
            return $this->collapseRepeatedLabel($ariaLabel);
        }

        $id = $this->attr($control, 'id');
        if ( '' !== $id && $control->ownerDocument instanceof DOMDocument ) {
            foreach ( $control->ownerDocument->getElementsByTagName('label') as $label ) {
                if ( $label instanceof DOMElement && $id === $this->attr($label, 'for') ) {
                    return $this->labelText($label);
                }
            }
        }

        for ( $parent = $control->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( 'label' === strtolower($parent->tagName) ) {
                return $this->labelText($parent);
            }
        }

        return '';
    }

    public function readableLabel(DOMElement $control): string
    {
        $label = $this->label($control);
        if ( '' === $label ) {
            $label = $this->attr($control, 'aria-label');
        }
        foreach ( array( 'placeholder', 'name' ) as $attribute ) {
            if ( '' === $label ) {
                $label = $this->attr($control, $attribute);
            }
        }

        $type = FormControlClassifier::controlType($control);
        if ( '' === $label && FormControlClassifier::isSubmitLikeControl($control) ) {
            $label = trim(preg_replace('/\s+/', ' ', $control->textContent ?? '') ?? '');
        }

        return '' !== $label ? $label : ( 'select' === $type ? 'Select option' : ucfirst($type) );
    }

    /** Label associated by `for`; wrapping labels are handled with their control. */
    public function associatedLabel(DOMElement $control): ?DOMElement
    {
        $id = $this->attr($control, 'id');
        if ( '' === $id || ! $control->ownerDocument instanceof DOMDocument ) {
            return null;
        }

        foreach ( $control->ownerDocument->getElementsByTagName('label') as $label ) {
            if ( $label instanceof DOMElement && $id === $this->attr($label, 'for') ) {
                return $label;
            }
        }

        return null;
    }

    public function submitText(DOMElement $control, string $fallback): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $control->textContent ?? '') ?? '');
        if ( '' !== $text ) {
            return $text;
        }

        $value = trim($this->attr($control, 'value'));
        return '' !== $value ? $value : $fallback;
    }

    /** @return array<int, array<string, mixed>> */
    public function options(DOMElement $select): array
    {
        $options = array();
        foreach ( $select->getElementsByTagName('option') as $option ) {
            if ( ! $option instanceof DOMElement ) {
                continue;
            }

            $value = $this->attr($option, 'value');
            $optionMetadata = array(
                'label' => trim(preg_replace('/\s+/', ' ', $option->textContent ?? '') ?? ''),
                // An explicit empty value is a placeholder semantic, not a missing value.
                'value' => $option->hasAttribute('value') ? $value : trim($option->textContent ?? ''),
            );
            if ( $option->hasAttribute('selected') ) {
                $optionMetadata['selected'] = true;
            }
            if ( $option->hasAttribute('disabled') ) {
                $optionMetadata['disabled'] = true;
            }
            if ( '' === trim($value) && ( $option->hasAttribute('disabled') || $option->hasAttribute('selected') ) ) {
                $optionMetadata['placeholder'] = true;
            }

            $options[] = $optionMetadata;
        }

        return $options;
    }

    public function labelText(DOMElement $label): string
    {
        return $this->collapseRepeatedLabel(trim(preg_replace('/\s+/', ' ', $this->labelTextWithoutControls($label)) ?? ''));
    }

    private function labelTextWithoutControls(DOMNode $node): string
    {
        if ( XML_TEXT_NODE === $node->nodeType ) {
            return $node->textContent ?? '';
        }
        if ( $node instanceof DOMElement && 'true' === strtolower($this->attr($node, 'aria-hidden')) ) {
            return '';
        }
        if ( $node instanceof DOMElement && FormControlClassifier::isControlElement($node) ) {
            return '';
        }

        $text = '';
        foreach ( $node->childNodes as $child ) {
            $text .= $this->labelTextWithoutControls($child);
        }

        return $text;
    }

    private function buttonText(DOMElement $control): string
    {
        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $label = trim($this->attr($control, $attribute));
            if ( '' !== $label ) {
                return $label;
            }
        }

        $text = trim(preg_replace('/\s+/', ' ', $control->textContent ?? '') ?? '');
        return '' !== $text ? $text : trim($this->attr($control, 'value'));
    }

    private function attr(DOMElement $element, string $name): string
    {
        return $element->hasAttribute($name) ? $element->getAttribute($name) : '';
    }

    private function collapseRepeatedLabel(string $label): string
    {
        if ( preg_match('/^\s*(.+?)[.!?]\s+\1\s*$/iu', $label, $match) ) {
            return trim($match[1]);
        }
        return $label;
    }
}
