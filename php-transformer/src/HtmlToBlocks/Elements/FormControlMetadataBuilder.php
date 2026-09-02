<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;

/** Builds provider-neutral form and control metadata from source DOM. */
final class FormControlMetadataBuilder
{
    /** @param Closure(DOMElement): string $elementSelector */
    public function __construct(
        private readonly Closure $elementSelector,
        private readonly ?Closure $presentationAttributes = null
    ) {
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
            'id'           => SourceDom::attr($form, 'id'),
            'name'         => SourceDom::attr($form, 'name'),
            'class'        => SourceDom::attr($form, 'class'),
            'aria_label'   => SourceDom::attr($form, 'aria-label'),
            'action'       => SourceDom::attr($form, 'action'),
            'method'       => strtolower(SourceDom::attr($form, 'method')),
            'enctype'      => SourceDom::attr($form, 'enctype'),
            'target'       => SourceDom::attr($form, 'target'),
            'autocomplete' => SourceDom::attr($form, 'autocomplete'),
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
        $labelElement = $this->labelElement($control);
        if ( 'button' === $type && FormControlClassifier::isSubmitLikeControl($control) ) {
            $type = 'submit';
        }
        $metadata = array_filter(array(
            'tag'          => $tagName,
            'selector'     => ($this->elementSelector)($control),
            'id'           => SourceDom::attr($control, 'id'),
            'class'        => $this->classNames($control),
            'label_class'  => $labelElement instanceof DOMElement ? $this->classNames($labelElement) : '',
            'name'         => SourceDom::attr($control, 'name'),
            'type'         => $type,
            'label'        => $this->label($control),
            'placeholder'  => SourceDom::attr($control, 'placeholder'),
            'autocomplete' => SourceDom::attr($control, 'autocomplete'),
            'pattern'      => SourceDom::attr($control, 'pattern'),
            'min'          => SourceDom::attr($control, 'min'),
            'max'          => SourceDom::attr($control, 'max'),
            'step'         => SourceDom::attr($control, 'step'),
            'maxlength'    => SourceDom::attr($control, 'maxlength'),
            'rows'         => SourceDom::attr($control, 'rows'),
        ), static fn (string $value): bool => '' !== $value);

        if ( in_array($type, array( 'button', 'reset', 'submit' ), true) ) {
            $text = $this->buttonText($control);
            if ( '' !== $text ) {
                $metadata['text'] = $text;
            }
            if ( null !== $this->presentationAttributes ) {
                $presentation = ($this->presentationAttributes)($control);
                if ( is_array($presentation['style'] ?? null) && array() !== $presentation['style'] ) {
                    $metadata['presentation'] = array( 'style' => $presentation['style'] );
                }
            }
        }

        if ( $control->hasAttribute('required') || 'true' === strtolower(trim(SourceDom::attr($control, 'aria-required'))) ) {
            $metadata['required'] = true;
        }
        foreach ( array( 'disabled', 'readonly', 'checked', 'multiple' ) as $attribute ) {
            if ( $control->hasAttribute($attribute) ) {
                $metadata[$attribute] = true;
            }
        }

        $value = SourceDom::attr($control, 'value');
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
        $ariaLabel = trim(SourceDom::attr($control, 'aria-label'));
        if ( '' !== $ariaLabel ) {
            return $ariaLabel;
        }

        $label = $this->labelElement($control);
        if ( $label instanceof DOMElement ) {
            return $this->labelText($label);
        }

        return '';
    }

    public function readableLabel(DOMElement $control): string
    {
        $label = $this->label($control);
        if ( '' === $label ) {
            $label = SourceDom::attr($control, 'aria-label');
        }
        foreach ( array( 'placeholder', 'name' ) as $attribute ) {
            if ( '' === $label ) {
                $label = SourceDom::attr($control, $attribute);
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
        $id = SourceDom::attr($control, 'id');
        if ( '' === $id || ! $control->ownerDocument instanceof DOMDocument ) {
            return null;
        }

        foreach ( $control->ownerDocument->getElementsByTagName('label') as $label ) {
            if ( $label instanceof DOMElement && $id === SourceDom::attr($label, 'for') ) {
                return $label;
            }
        }

        return null;
    }

    private function labelElement(DOMElement $control): ?DOMElement
    {
        $label = $this->associatedLabel($control);
        if ( $label instanceof DOMElement ) {
            return $label;
        }
        for ( $parent = $control->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( 'label' === strtolower($parent->tagName) ) {
                return $parent;
            }
        }
        return null;
    }

    private function classNames(DOMElement $element): string
    {
        $classes = array();
        foreach ( preg_split('/\s+/', trim(SourceDom::attr($element, 'class'))) ?: array() as $className ) {
            if ( count($classes) >= 16 ) {
                break;
            }
            if ( 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $className) ) {
                $classes[] = $className;
            }
        }
        return implode(' ', $classes);
    }

    public function submitText(DOMElement $control, string $fallback): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $control->textContent ?? '') ?? '');
        if ( '' !== $text ) {
            return $text;
        }

        $value = trim(SourceDom::attr($control, 'value'));
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

            $value = SourceDom::attr($option, 'value');
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
        return trim(preg_replace('/\s+/', ' ', $this->labelTextWithoutControls($label)) ?? '');
    }

    private function labelTextWithoutControls(DOMNode $node): string
    {
        if ( XML_TEXT_NODE === $node->nodeType ) {
            return $node->textContent ?? '';
        }
        if ( $node instanceof DOMElement && 'true' === strtolower(SourceDom::attr($node, 'aria-hidden')) ) {
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
            $label = trim(SourceDom::attr($control, $attribute));
            if ( '' !== $label ) {
                return $label;
            }
        }

        $text = trim(preg_replace('/\s+/', ' ', $control->textContent ?? '') ?? '');
        return '' !== $text ? $text : trim(SourceDom::attr($control, 'value'));
    }
}
