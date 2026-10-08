<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NativeControlState;
use DOMElement;

/** Lowers native GET-form controls while the form builder is converting children. */
final class NativeGetFormControlConverter implements ElementConverter
{
    public function __construct(
        private readonly NativeGetFormBlockBuilder $nativeGetFormBlockBuilder,
        private readonly AuthoredFormControlBlockConverter $authoredFormControlBlockConverter,
        private readonly FormControlMetadataBuilder $formControlMetadataBuilder,
        private readonly StyleResolver $styleResolver,
        private readonly SourceBlockCreator $createBlock
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        // Typed baseline facts are a native-control contract even without CSS,
        // including hidden/file/readonly controls and controls outside a form.
        if (null !== NativeControlState::attributes($element)) {
            $block = match ($tagName) {
                'input' => $this->authoredFormControlBlockConverter->input($element, null, false, true),
                'select' => $this->authoredFormControlBlockConverter->select($element, true),
                'textarea' => $this->authoredFormControlBlockConverter->textarea($element, null, true),
                default => null,
            };
            return ConversionOutcome::handled($block);
        }
        if ('label' === $tagName) {
            $controls = FormControlClassifier::controlElements($element);
            if (1 === count($controls) && null !== NativeControlState::attributes($controls[0])) {
                $control = $controls[0];
                return ConversionOutcome::handled(match (strtolower($control->tagName)) {
                    'input' => $this->authoredFormControlBlockConverter->input($control, $element, false, true),
                    'select' => $this->authoredFormControlBlockConverter->select($control, true, $element),
                    'textarea' => $this->authoredFormControlBlockConverter->textarea($control, $element, true),
                });
            }
        }
        if ( ! $this->nativeGetFormBlockBuilder->isInside() ) {
            return ConversionOutcome::unhandled();
        }

        if ( 'label' === $tagName && '' !== SourceDom::attr($element, 'for') ) {
            $target = $element->ownerDocument?->getElementById(SourceDom::attr($element, 'for'));
            if ($target instanceof DOMElement && $target->hasAttribute(NativeControlState::ATTRIBUTE)) return ConversionOutcome::unhandled();
            return ConversionOutcome::handled(null);
        }

        $nativeControl = $this->controlBlock($element, $tagName);

        return null === $nativeControl
            ? ConversionOutcome::unhandled()
            : ConversionOutcome::handled($nativeControl);
    }

    /** @return array<string, mixed>|null */
    private function controlBlock(DOMElement $element, string $tagName): ?array
    {
        if ( 'label' === $tagName ) {
            $controls = FormControlClassifier::controlElements($element);
            if ( 1 === count($controls) ) {
                $control = $controls[0];
                if ( 'input' === strtolower($control->tagName) ) {
                    return $this->authoredFormControlBlockConverter->input($control, $element, false, true);
                }
                if ( 'select' === strtolower($control->tagName) ) {
                    return $this->authoredFormControlBlockConverter->select($control, true, $element);
                }
            }
            return null;
        }
        if ( 'input' === $tagName ) {
            return $this->authoredFormControlBlockConverter->input($element, $element->hasAttribute(NativeControlState::ATTRIBUTE) ? null : $this->formControlMetadataBuilder->associatedLabel($element), false, true);
        }
        if ( 'select' === $tagName ) {
            return $this->authoredFormControlBlockConverter->select($element, true, $this->formControlMetadataBuilder->associatedLabel($element));
        }
        if ( 'button' === $tagName ) {
            $type = strtolower(trim(SourceDom::attr($element, 'type')));
            if ( ! in_array($type, array( 'button', 'reset', 'submit' ), true) ) {
                $type = 'submit';
            }
            return $this->createBlock->createBlock('core/button', array_merge($this->styleResolver->presentationAttributes($element), array(
                'tagName' => 'button',
                'type' => $type,
                'text' => SourceDom::innerHtml($element),
            )), array(), $element);
        }
        return null;
    }
}
