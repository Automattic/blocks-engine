<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
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
        if ( ! $this->nativeGetFormBlockBuilder->isInside() ) {
            return ConversionOutcome::unhandled();
        }

        if ( 'label' === $tagName && '' !== SourceDom::attr($element, 'for') ) {
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
            return $this->authoredFormControlBlockConverter->input($element, $this->formControlMetadataBuilder->associatedLabel($element), false, true);
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
